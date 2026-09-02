<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HostingAccount;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\Role;
use App\Models\User;
use App\Services\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\SnmpMonitor\Exceptions\UnlinkedAccountException;
use Modules\SnmpMonitor\Models\SnmpTarget;
use Modules\SnmpMonitor\Services\SnmpMetricRepository;
use Tests\Support\InteractsWithSnmpMonitorModule;
use Tests\TestCase;

// Worktrees share one composer vendor junction, so the autoloader resolves
// Tests\ against another checkout; load this suite's support trait directly.
require_once __DIR__.'/../Support/InteractsWithSnmpMonitorModule.php';

/**
 * Plan task 6 — admin SNMP dashboard: metric repository math (bucket snap,
 * hourly-tier switch, filtering), the product-link isolation guard and the
 * HTTP surface (routes, permissions, JSON contract, rendered views).
 *
 * Time-series tables are seeded directly through the real monitoring
 * connection; no SNMP network I/O happens anywhere in this suite.
 */
final class SnmpDashboardTest extends TestCase
{
    use InteractsWithSnmpMonitorModule;
    use RefreshDatabase;

    private ModuleManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerSnmpMonitorAutoloader();
        $this->ensureSnmpMonitoringTables($this->manager = app(ModuleManager::class));

        // Real production path for module routes: provider boot + route
        // registration, then refresh the router's lookup tables so route()
        // resolves names registered after the initial compilation.
        $module = $this->activateSnmpMonitorModule($this->manager);
        $instance = $this->manager->resolve($module);

        if ($instance !== null) {
            $instance->boot($this->manager->contextFor($module));
            $this->manager->registerModuleRoutes();
            app('router')->getRoutes()->refreshNameLookups();
            app('router')->getRoutes()->refreshActionLookups();
        }
    }

    // ==================================================================
    // Repository: bucket math
    // ==================================================================

    public function test_bucket_resolution_snaps_range_over_four_hundred_onto_ladder(): void
    {
        // Snap-UP contract: the smallest ladder step >= range/400, keeping
        // every rendered series at or below ~400 points.
        $this->assertSame(60, SnmpMetricRepository::resolveBucket(3600), '1h / 400 = 9s snaps up to the smallest ladder step.');
        $this->assertSame(300, SnmpMetricRepository::resolveBucket(86400), '24h / 400 = 216s snaps up to 300.');
        $this->assertSame(3600, SnmpMetricRepository::resolveBucket(604800), '7d / 400 = 1512s snaps up to 3600.');
        $this->assertSame(21600, SnmpMetricRepository::resolveBucket(2592000), '30d / 400 = 6480s snaps up to the 6h cap.');

        // Sub-ladder ranges clamp to the smallest bucket; huge ranges cap at
        // the largest instead of producing thousands of points.
        $this->assertSame(60, SnmpMetricRepository::resolveBucket(600));
        $this->assertSame(21600, SnmpMetricRepository::resolveBucket(31536000));
    }

    // ==================================================================
    // Repository: tier switch — raw samples up to 30 days, hourly rollups
    // strictly beyond.
    // ==================================================================

    public function test_series_reads_raw_samples_within_and_hourly_rollups_beyond_thirty_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00'));

        [$account] = $this->makeMonitoredHost();

        // Raw sample one hour ago with a distinctive value.
        $this->insertSample($this->targetId($account), now()->subHour(), ['cpu_pct' => 11.5]);

        // Hourly rollups: one inside the >30d window, one far outside it.
        $insideHourly = now()->subDays(29)->startOfHour();
        $outsideHourly = now()->subDays(40)->startOfHour();

        foreach ([[$insideHourly, 42.0], [$outsideHourly, 99.0]] as [$start, $avg]) {
            $this->monitoring()->table('snmp_metric_hourly')->insert([
                'host_id' => $this->targetId($account),
                'series' => 'cpu_pct',
                'hour_start' => $start->format('Y-m-d H:i:s'),
                'v_avg' => $avg,
                'v_min' => $avg - 1,
                'v_max' => $avg + 1,
                'samples' => 60,
            ]);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $repo = new SnmpMetricRepository($this->manager);

        // Exactly 30 days stays on the raw tier.
        $within = $repo->series($account, ['cpu_pct'], 2592000);
        $this->assertContains('snmp_host_samples', $this->queriedTables($queries));
        $this->assertNotContains('snmp_metric_hourly', $this->queriedTables($queries));
        $this->assertTrue(collect($within['datasets'][0]['data'])->contains(
            fn ($v) => $v !== null && abs((float) $v - 11.5) < 0.001
        ), 'Within-30d series must aggregate raw samples.');
        $this->assertFalse(collect($within['datasets'][0]['data'])->contains(42.0));

        $queries = [];

        // A single second past the boundary flips to the hourly tier.
        $beyond = $repo->series($account, ['cpu_pct'], 2592001);
        $this->assertContains('snmp_metric_hourly', $this->queriedTables($queries));
        $this->assertNotContains('snmp_host_samples', $this->queriedTables($queries));
        $this->assertTrue(collect($beyond['datasets'][0]['data'])->contains(
            fn ($v) => $v !== null && abs((float) $v - 42.0) < 0.001
        ), 'Beyond-30d series must come from the hourly rollups.');
        $this->assertFalse(collect($beyond['datasets'][0]['data'])->contains(11.5));
        $this->assertFalse(
            collect($beyond['datasets'][0]['data'])->contains(fn ($v) => $v !== null && abs((float) $v - 99.0) < 0.001),
            'Hourly rows outside the window must be filtered out.'
        );
    }

    // ==================================================================
    // Repository: series filtering — only requested metrics, window bounds,
    // interface rates summed across interfaces.
    // ==================================================================

    public function test_series_returns_only_requested_metrics_inside_the_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00'));

        [$account] = $this->makeMonitoredHost();
        $hostId = $this->targetId($account);

        $this->insertSample($hostId, now()->subMinutes(10), ['cpu_pct' => 7.5, 'mem_used_mb' => 999]);
        $this->insertSample($hostId, now()->subHours(2), ['cpu_pct' => 555.0]);

        $series = (new SnmpMetricRepository($this->manager))->series($account, ['mem_used_mb'], 3600);

        $this->assertCount(1, $series['datasets'], 'Unselected metrics must never leak into the response.');
        $this->assertStringContainsStringIgnoringCase('memory', $series['datasets'][0]['label']);
        $this->assertTrue(collect($series['datasets'][0]['data'])->contains(fn ($v) => $v !== null && abs((float) $v - 999.0) < 0.001));
        $this->assertFalse(collect($series['datasets'][0]['data'])->contains(555.0), 'Samples older than the window are excluded.');
    }

    public function test_series_sums_interface_rates_across_interfaces(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00'));

        [$account] = $this->makeMonitoredHost();
        $hostId = $this->targetId($account);

        $at = now()->subMinute()->format('Y-m-d H:i:s');
        foreach ([1 => 1000.0, 2 => 250.0] as $ifIndex => $inBps) {
            $this->monitoring()->table('snmp_if_samples')->insert([
                'host_id' => $hostId,
                'if_index' => $ifIndex,
                'collected_at' => $at,
                'in_octets' => null,
                'out_octets' => null,
                'in_bps' => $inBps,
                'out_bps' => null,
            ]);
        }

        $series = (new SnmpMetricRepository($this->manager))->series($account, ['in_bps'], 3600);

        $this->assertTrue(
            collect($series['datasets'][0]['data'])->contains(fn ($v) => $v !== null && abs((float) $v - 1250.0) < 0.001),
            'Interface rates must total across interfaces.'
        );
    }

    // ==================================================================
    // Repository: label grid math — 48h of seeded samples, 24h request,
    // bucket 300s -> ceil(86400/300) = 288 labels ±1 and populated data.
    // ==================================================================

    public function test_series_labels_match_bucket_math_for_seeded_samples(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00'));

        [$account] = $this->makeMonitoredHost();
        $hostId = $this->targetId($account);

        for ($minutesAgo = 2880; $minutesAgo >= 0; $minutesAgo -= 5) {
            $this->insertSample($hostId, now()->subMinutes($minutesAgo), [
                'cpu_pct' => 20.0 + ($minutesAgo % 7),
            ]);
        }

        $response = $this->actingAsAdmin()->getJson(
            $this->seriesUrl($account).'?'.http_build_query(['metrics' => ['cpu_pct'], 'range' => '24h'])
        );

        $response->assertOk();
        $labels = $response->json('labels');
        $data = $response->json('datasets.0.data');

        $this->assertIsArray($labels);
        $expected = (int) ceil(86400 / 300);
        $this->assertGreaterThanOrEqual($expected - 1, count($labels), 'Label grid must cover the requested window.');
        $this->assertLessThanOrEqual($expected + 1, count($labels));
        $this->assertCount(count($labels), $data, 'Every label slot needs a data slot.');
        $this->assertNotEmpty(array_filter($data, fn ($v) => $v !== null), 'Seeded samples must populate the dataset.');
    }

    // ==================================================================
    // CRITICAL guard: no snmp data may be read for an account whose
    // product lacks an enabled snmp-monitor module link.
    // ==================================================================

    public function test_repository_rejects_accounts_without_enabled_module_link(): void
    {
        $repo = new SnmpMetricRepository($this->manager);

        // No pivot row at all.
        $unlinkedAccount = $this->makeAccount($this->makePlainProduct());

        try {
            $repo->series($unlinkedAccount, ['cpu_pct'], 3600);
            $this->fail('Series must reject an account whose product has no snmp-monitor link.');
        } catch (UnlinkedAccountException) {
            $this->addToAssertionCount(1);
        }

        // Pivot present but disabled.
        $disabledAccount = $this->makeLinkedAccount(false);

        try {
            $repo->summary($disabledAccount);
            $this->fail('Summary must reject a disabled module link.');
        } catch (UnlinkedAccountException) {
            $this->addToAssertionCount(1);
        }

        try {
            $repo->index(['account' => $disabledAccount->id]);
            $this->fail('Index must refuse to scope down to an unlinked account.');
        } catch (UnlinkedAccountException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_index_lists_only_linked_targets_and_supports_filters(): void
    {
        [$accountA, $accountB] = $this->makeMonitoredHost([], ['host' => '192.0.2.10']);
        $this->makeTarget($accountB, ['host' => '192.0.2.20', 'status' => SnmpTarget::STATUS_DOWN]);

        $hidden = $this->makeAccount($this->makePlainProduct());
        $this->makeTarget($hidden, ['host' => '192.0.2.99']);

        $repo = new SnmpMetricRepository($this->manager);

        $hosts = collect($repo->index()['rows'])->pluck('t.host')->all();
        $this->assertContains('192.0.2.10', $hosts);
        $this->assertContains('192.0.2.20', $hosts);
        $this->assertNotContains('192.0.2.99', $hosts, 'Targets of products without an enabled link must be invisible.');

        $byStatus = $repo->index(['status' => 'down'])['rows'];
        $this->assertCount(1, $byStatus, 'Status filter must isolate matching targets. Got: '.json_encode(collect($byStatus)->pluck('t.host')));

        $bySearch = $repo->index(['q' => '192.0.2.10'])['rows'];
        $this->assertCount(1, $bySearch, 'Hostname search must isolate matching targets. Got: '.json_encode(collect($bySearch)->pluck('t.host')));

        $byAccount = $repo->index(['account' => $accountA->id])['rows'];
        $this->assertCount(1, $byAccount, 'Account filter must isolate the account target. Got: '.json_encode(collect($byAccount)->pluck('t.host')));
        $this->assertSame('192.0.2.10', $byAccount[0]['t']->host);
    }

    public function test_index_merges_latest_payload_without_n_plus_one_queries(): void
    {
        [$accountA, $accountB] = $this->makeMonitoredHost();
        $this->makeTarget($accountB);

        $this->monitoring()->table('snmp_latest')->insert([
            [
                'host_id' => $this->targetId($accountA),
                'collected_at' => '2026-08-25 11:59:00',
                'status' => 'up',
                'payload' => json_encode(['hostname' => 'web-01']),
            ],
            [
                'host_id' => $this->targetId($accountB),
                'collected_at' => '2026-08-25 11:58:00',
                'status' => 'down',
                'payload' => json_encode(['hostname' => 'db-01']),
            ],
        ]);

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $rows = (new SnmpMetricRepository($this->manager))->index()['rows'];

        $this->assertLessThanOrEqual(6, $count, 'Listing two hosts must stay a fixed handful of queries, never one per row.');

        $latestByAccount = collect($rows)->keyBy(fn ($r) => $r['t']->hosting_account_id);
        $this->assertSame('web-01', $latestByAccount[$accountA->id]['latest']['payload']['hostname']);
        $this->assertSame('down', $latestByAccount[$accountB->id]['latest']['status']);
    }

    // ==================================================================
    // latestSampleMetrics(): CPU/memory/disk gauges from the most recent
    // snmp_host_samples row — never one query per host.
    // ==================================================================

    public function test_latest_sample_metrics_returns_cpu_mem_disk_from_most_recent_sample(): void
    {
        [$account] = $this->makeMonitoredHost();
        $hostId = $this->targetId($account);

        $this->insertHostSample($hostId, '2026-08-25 11:00:00', ['cpu_pct' => 10.0, 'mem_used_mb' => 512, 'mem_total_mb' => 1024, 'storage_pct' => 40.0]);
        $this->insertHostSample($hostId, '2026-08-25 12:00:00', ['cpu_pct' => 55.0, 'mem_used_mb' => 768, 'mem_total_mb' => 1024, 'storage_pct' => 62.5]);

        $metrics = (new SnmpMetricRepository($this->manager))->latestSampleMetrics([$hostId]);

        $this->assertSame(55.0, $metrics[$hostId]['cpu_pct'], 'The most recent row must win, not the first.');
        $this->assertSame(75.0, $metrics[$hostId]['mem_pct'], '768/1024 MB = 75%.');
        $this->assertSame(62.5, $metrics[$hostId]['disk_pct']);
    }

    public function test_latest_sample_metrics_is_one_query_for_the_whole_batch(): void
    {
        [$accountA, $accountB] = $this->makeMonitoredHost();
        $this->makeTarget($accountB);
        $idA = $this->targetId($accountA);
        $idB = $this->targetId($accountB);

        $this->insertHostSample($idA, '2026-08-25 12:00:00', ['cpu_pct' => 20.0]);
        $this->insertHostSample($idB, '2026-08-25 12:00:00', ['cpu_pct' => 30.0]);

        $count = 0;
        DB::listen(function () use (&$count): void { $count++; });

        $metrics = (new SnmpMetricRepository($this->manager))->latestSampleMetrics([$idA, $idB]);

        $this->assertSame(1, $count, 'One join query must cover the whole requested batch.');
        $this->assertSame(20.0, $metrics[$idA]['cpu_pct']);
        $this->assertSame(30.0, $metrics[$idB]['cpu_pct']);
    }

    // ==================================================================
    // index(): CPU/memory/disk threshold filters — the metric-filtered
    // path that materializes the set and filters/paginates in PHP.
    // ==================================================================

    public function test_index_filters_by_cpu_min_threshold(): void
    {
        [$accountA, $accountB] = $this->makeMonitoredHost([], ['host' => '192.0.2.30']);
        $this->makeTarget($accountB, ['host' => '192.0.2.31']);

        $this->insertHostSample($this->targetId($accountA), '2026-08-25 12:00:00', ['cpu_pct' => 40.0]);
        $this->insertHostSample($this->targetId($accountB), '2026-08-25 12:00:00', ['cpu_pct' => 92.0]);

        $repo = new SnmpMetricRepository($this->manager);

        $hot = $repo->index(['cpu_min' => '85'])['rows'];
        $this->assertCount(1, $hot, 'Only the host at/above the CPU threshold must survive.');
        $this->assertSame('192.0.2.31', $hot[0]['t']->host);

        $all = $repo->index(['cpu_min' => '10'])['rows'];
        $this->assertCount(2, $all, 'Both hosts clear a low threshold.');
    }

    public function test_index_excludes_hosts_without_any_sample_when_a_metric_filter_is_active(): void
    {
        [$account] = $this->makeMonitoredHost();
        // No snmp_host_samples row at all for this host.

        $rows = (new SnmpMetricRepository($this->manager))->index(['cpu_min' => '1'])['rows'];

        $this->assertCount(0, $rows, 'A host with no sample data can never satisfy a metric threshold.');
    }

    public function test_index_metric_filters_combine_with_status_filter_and_report_matching_stats(): void
    {
        [$accountA, $accountB] = $this->makeMonitoredHost([], ['host' => '192.0.2.40', 'status' => SnmpTarget::STATUS_UP]);
        $this->makeTarget($accountB, ['host' => '192.0.2.41', 'status' => SnmpTarget::STATUS_DOWN]);

        $this->insertHostSample($this->targetId($accountA), '2026-08-25 12:00:00', ['storage_pct' => 95.0]);
        $this->insertHostSample($this->targetId($accountB), '2026-08-25 12:00:00', ['storage_pct' => 95.0]);

        $result = (new SnmpMetricRepository($this->manager))->index(['disk_min' => '90', 'status' => 'up']);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('192.0.2.40', $result['rows'][0]['t']->host);
        $this->assertSame(1, $result['stats']['total'], 'Stats must reflect the final (status + metric) filtered set.');
        $this->assertSame(1, $result['stats']['up']);
        $this->assertSame(0, $result['stats']['down'], 'The down host was filtered out by status before the metric threshold ran.');
    }

    public function test_index_metric_filtered_path_paginates_in_php(): void
    {
        $module = $this->activateSnmpMonitorModule($this->manager);
        $product = $this->makeMonitoredProduct($this->manager, $module);

        for ($i = 0; $i < 3; $i++) {
            $account = $this->makeAccount($product);
            $target = $this->makeTarget($account, ['host' => "192.0.3.{$i}"]);
            $this->insertHostSample($target->id, '2026-08-25 12:00:00', ['cpu_pct' => 90.0]);
        }

        $repo = new SnmpMetricRepository($this->manager);

        $page1 = $repo->index(['cpu_min' => '50'], 2, 1);
        $page2 = $repo->index(['cpu_min' => '50'], 2, 2);

        $this->assertSame(3, $page1['total']);
        $this->assertCount(2, $page1['rows']);
        $this->assertCount(1, $page2['rows']);

        $seenHosts = array_merge(
            collect($page1['rows'])->pluck('t.host')->all(),
            collect($page2['rows'])->pluck('t.host')->all(),
        );
        $this->assertCount(3, array_unique($seenHosts), 'Every matching host must appear exactly once across pages.');
    }

    // ==================================================================
    // HTTP surface: route names, bindings and middleware.
    // ==================================================================

    public function test_dashboard_routes_exist_with_expected_names_and_verbs(): void
    {
        foreach (['dashboard', 'host.show', 'series'] as $name) {
            $route = Route::getRoutes()->getByName("admin.snmp-monitor.{$name}");

            $this->assertNotNull($route, "Route [admin.snmp-monitor.{$name}] must be registered.");
            $this->assertTrue(in_array('GET', $route->methods(), true));
        }

        $show = Route::getRoutes()->getByName('admin.snmp-monitor.host.show');
        $this->assertStringContainsString('{hostingAccount}', $show->uri, 'Binding param must stay {hostingAccount}.');
    }

    public function test_guest_is_redirected_from_every_dashboard_route(): void
    {
        [$account] = $this->makeMonitoredHost();

        $this->get(route('admin.snmp-monitor.dashboard'))->assertRedirect();
        $this->get(route('admin.snmp-monitor.host.show', $account))->assertRedirect();
        $this->getJson($this->seriesUrl($account))->assertRedirect();
    }

    public function test_admin_without_hosting_view_permission_is_forbidden(): void
    {
        [$account] = $this->makeMonitoredHost();

        $this->actingAsAdminWithoutPermission()
            ->get(route('admin.snmp-monitor.dashboard'))
            ->assertForbidden();
        $this->actingAsAdminWithoutPermission()
            ->get(route('admin.snmp-monitor.host.show', $account))
            ->assertForbidden();
        $this->actingAsAdminWithoutPermission()
            ->getJson($this->seriesUrl($account))
            ->assertForbidden();
    }

    public function test_listing_route_renders_cpu_mem_disk_filters_and_gauges(): void
    {
        [$account] = $this->makeMonitoredHost([], ['host' => '192.0.2.80']);
        $this->insertHostSample($this->targetId($account), '2026-08-25 12:00:00', [
            'cpu_pct' => 91.0, 'mem_used_mb' => 900, 'mem_total_mb' => 1000, 'storage_pct' => 42.0,
        ]);

        $response = $this->actingAsAdmin()->get(route('admin.snmp-monitor.dashboard'));

        $response->assertOk();
        $response->assertSee('name="cpu_min"', false);
        $response->assertSee('name="mem_min"', false);
        $response->assertSee('name="disk_min"', false);
        $response->assertSee('91%', false);
        $response->assertSee('90%', false);
        $response->assertSee('42%', false);
    }

    public function test_listing_route_applies_cpu_min_filter(): void
    {
        [$accountA, $accountB] = $this->makeMonitoredHost([], ['host' => '192.0.2.81']);
        $this->makeTarget($accountB, ['host' => '192.0.2.82']);

        $this->insertHostSample($this->targetId($accountA), '2026-08-25 12:00:00', ['cpu_pct' => 10.0]);
        $this->insertHostSample($this->targetId($accountB), '2026-08-25 12:00:00', ['cpu_pct' => 95.0]);

        $response = $this->actingAsAdmin()->get(route('admin.snmp-monitor.dashboard', ['cpu_min' => 90]));

        $response->assertOk();
        $response->assertSee('192.0.2.82');
        $response->assertDontSee('192.0.2.81');
    }

    public function test_linked_account_renders_listing_and_chart_page_with_cdn_script(): void
    {
        [$account] = $this->makeMonitoredHost([], ['host' => '192.0.2.77']);

        $listing = $this->actingAsAdmin()->get(route('admin.snmp-monitor.dashboard'));
        $listing->assertOk();
        $listing->assertSee('SNMP Monitor');
        $listing->assertSee('192.0.2.77');

        $page = $this->actingAsAdmin()->get(route('admin.snmp-monitor.host.show', $account));
        $page->assertOk();
        $page->assertSee('cdn.jsdelivr.net/npm/chart.js@4', false);
        $page->assertSee('192.0.2.77');
        $page->assertSee('metrics[]', false);
        $page->assertSee('name="range"', false);
    }

    public function test_unlinked_product_gets_403_on_show_and_series(): void
    {
        $unlinked = $this->makeAccount($this->makePlainProduct());
        $disabled = $this->makeLinkedAccount(false);

        foreach ([$unlinked, $disabled] as $account) {
            $this->actingAsAdmin()
                ->get(route('admin.snmp-monitor.host.show', $account))
                ->assertForbidden();
            $this->actingAsAdmin()
                ->getJson($this->seriesUrl($account).'?'.http_build_query(['metrics' => ['cpu_pct'], 'range' => '24h']))
                ->assertForbidden();
        }
    }

    public function test_series_endpoint_returns_json_contract_for_valid_input(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00'));

        [$account] = $this->makeMonitoredHost();
        $this->insertSample($this->targetId($account), now()->subMinutes(5), ['cpu_pct' => 33.0]);

        $response = $this->actingAsAdmin()->getJson(
            $this->seriesUrl($account).'?'.http_build_query(['metrics' => ['cpu_pct', 'mem_used_mb'], 'range' => '24h'])
        );

        $response->assertOk()->assertHeader('content-type', 'application/json');
        $body = $response->json();

        $this->assertArrayHasKey('labels', $body);
        $this->assertArrayHasKey('datasets', $body);
        $this->assertCount(2, $body['datasets']);
        foreach ($body['datasets'] as $dataset) {
            $this->assertArrayHasKey('label', $dataset);
            $this->assertArrayHasKey('data', $dataset);
            $this->assertCount(count($body['labels']), $dataset['data']);
        }
    }

    public function test_series_endpoint_validates_malformed_input(): void
    {
        [$account] = $this->makeMonitoredHost();

        $cases = [
            'unknown metric' => ['metrics' => ['cpu_pct', 'drop table users'], 'range' => '24h'],
            'scalar metric' => ['metrics' => 'cpu_pct', 'range' => '24h'],
            'unknown range' => ['metrics' => ['cpu_pct'], 'range' => '99h'],
            'garbage range' => ['metrics' => ['cpu_pct'], 'range' => '"><script>'],
        ];

        foreach ($cases as $label => $params) {
            $this->actingAsAdmin()
                ->getJson($this->seriesUrl($account).'?'.http_build_query($params))
                ->assertStatus(422);
            $this->assertTrue(true, "Case [{$label}] must be rejected with a validation error.");
        }
    }

    // ==================================================================
    // Host page — single-source-of-truth rendering + poll freshness
    // ==================================================================

    /**
     * The page restructure is only worth anything if it holds: every fact is
     * rendered exactly once. The address is the canary — it used to appear
     * three times (hero card, identity badge, "Poll target" tile).
     */
    public function test_host_page_renders_each_identity_fact_exactly_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00'));

        [$account] = $this->makeMonitoredHost([], ['host' => '192.0.2.91', 'port' => 161]);
        $this->insertLatest($this->targetId($account), now()->format('Y-m-d H:i:s.v'), 'up', [
            'hostname' => 'single-render-host',
            'uptime_human' => '1 days, 2:03:04',
            'cpu_load' => 12.5,
            'cpu_cores' => 4,
            'memory_used_mb' => 1024,
            'memory_total_mb' => 4096,
        ]);

        $body = $this->actingAsAdmin()
            ->get(route('admin.snmp-monitor.host.show', $account))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($body, '192.0.2.91:161'), 'The poll address must render exactly once.');
        $this->assertSame(1, substr_count($body, 'single-render-host'), 'The hostname must render exactly once.');
        $this->assertSame(1, substr_count($body, '1,024 / 4,096 MB'), 'Memory used/total must render exactly once.');
        $this->assertSame(1, substr_count($body, '1 days, 2:03:04'), 'Uptime must render exactly once.');

        // Boxes whose only content was a second rendering of the above.
        foreach (['Poll target', 'Latest sample', 'System identity'] as $removed) {
            $this->assertStringNotContainsString($removed, $body, "[{$removed}] duplicated a fact shown elsewhere.");
        }
    }

    public function test_host_page_reports_stale_rather_than_a_frozen_up_status(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00'));

        // snmp_targets.status only moves when a poll succeeds or fails, so a
        // host whose collector stopped keeps saying "up" forever. Ten minutes
        // against a 60s interval is far past the three-interval horizon.
        [$account] = $this->makeMonitoredHost([], ['poll_interval' => 60, 'status' => 'up']);
        $this->insertLatest($this->targetId($account), now()->subMinutes(10)->format('Y-m-d H:i:s.v'));

        $response = $this->actingAsAdmin()->get(route('admin.snmp-monitor.host.show', $account));

        $response->assertOk();
        $response->assertSee('Stale');
        $response->assertSee('are not live');
    }

    public function test_host_page_reports_live_status_inside_the_poll_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00'));

        [$account] = $this->makeMonitoredHost([], ['poll_interval' => 300, 'status' => 'up']);
        $this->insertLatest($this->targetId($account), now()->subSeconds(30)->format('Y-m-d H:i:s.v'));

        $response = $this->actingAsAdmin()->get(route('admin.snmp-monitor.host.show', $account));

        $response->assertOk();
        $response->assertDontSee('Stale');
        $response->assertDontSee('are not live');
    }

    public function test_staleness_does_not_mask_a_down_verdict(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00'));

        // "Down" is an observed result with failures behind it — the
        // actionable fact. Staleness exists to stop a GREEN status lying, so
        // it must never relabel a red one.
        [$account] = $this->makeMonitoredHost([], [
            'poll_interval' => 60,
            'status' => 'down',
            'consecutive_failures' => 9,
        ]);
        $this->insertLatest($this->targetId($account), now()->subMinutes(10)->format('Y-m-d H:i:s.v'), 'down');

        $response = $this->actingAsAdmin()->get(route('admin.snmp-monitor.host.show', $account));

        $response->assertOk();
        $response->assertSee('Down');
        $response->assertSee('9 fails');
        // The collection gap is still reported, just not as the headline.
        $response->assertSee('are not live');
    }

    public function test_a_never_sampled_host_gets_no_contradictory_stale_bar(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00'));

        // last_polled_at set (we tried) but no snmp_latest row (it failed).
        // The stale bar qualifies "the readings below" — there are none, so
        // it must stay away and let the no-data banner speak.
        [$account] = $this->makeMonitoredHost([], [
            'poll_interval' => 60,
            'status' => 'down',
            'last_polled_at' => now()->subMinutes(30),
        ]);

        $response = $this->actingAsAdmin()->get(route('admin.snmp-monitor.host.show', $account));

        $response->assertOk();
        $response->assertSee('No SNMP data yet');
        $response->assertDontSee('are not live');
    }

    public function test_host_page_hides_the_bandwidth_card_when_the_legacy_quota_is_zero(): void
    {
        // bandwidth_quota/bandwidth_used are legacy integer-cast columns that
        // read 0 on every modern account, which rendered a permanent
        // "0 / 0 GB" card. Gated on > 0, so it must not appear at all.
        [$account] = $this->makeMonitoredHost();

        $this->actingAsAdmin()
            ->get(route('admin.snmp-monitor.host.show', $account))
            ->assertOk()
            ->assertDontSee('Bandwidth quota');
    }

    public function test_metric_history_renders_one_chart_per_resource(): void
    {
        [$account] = $this->makeMonitoredHost();

        $body = $this->actingAsAdmin()
            ->get(route('admin.snmp-monitor.host.show', $account))
            ->assertOk()
            ->getContent();

        // One canvas per resource, replacing the single multi-select chart
        // that drew "% used", "MB" and "bps" onto one shared axis.
        foreach (['chart-cpu', 'chart-memory', 'chart-disk', 'chart-network', 'chart-response', 'chart-procs'] as $id) {
            $this->assertSame(
                1,
                substr_count($body, 'id="'.$id.'"'),
                "Chart panel [{$id}] must render exactly one canvas."
            );
        }

        // Every catalogued metric is still plotted somewhere — splitting the
        // charts must not quietly drop any of them.
        foreach (array_keys(SnmpMetricRepository::METRICS) as $metric) {
            $this->assertStringContainsString('"'.$metric.'"', $body, "Metric [{$metric}] is no longer plotted.");
        }

        // The multi-select is gone; the shared range control stays.
        $this->assertStringNotContainsString('name="metrics[]"', $body);
        $this->assertStringContainsString('name="range"', $body);
    }

    public function test_host_page_renders_for_a_never_polled_target(): void
    {
        [$account] = $this->makeMonitoredHost();

        $this->actingAsAdmin()
            ->get(route('admin.snmp-monitor.host.show', $account))
            ->assertOk()
            ->assertSee('No SNMP data yet')
            ->assertSee('Never polled');
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * Write the snmp_latest snapshot the host page reads for its payload,
     * freshness and status.
     *
     * @param  array<string, mixed>  $payload
     */
    private function insertLatest(int $hostId, string $collectedAt, string $status = 'up', array $payload = []): void
    {
        $this->monitoring()->table('snmp_latest')->updateOrInsert(
            ['host_id' => $hostId],
            [
                'collected_at' => $collectedAt,
                'status' => $status,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ],
        );
    }

    /**
     * Two accounts on a shared monitored product; the first gets an SNMP
     * target (default host overridable).
     *
     * @param  array<string, mixed>  $productConfig
     * @param  array<string, mixed>  $targetOverrides
     * @return array{0: HostingAccount, 1: HostingAccount}
     */
    private function makeMonitoredHost(array $productConfig = [], array $targetOverrides = []): array
    {
        $module = $this->activateSnmpMonitorModule($this->manager);
        $product = $this->makeMonitoredProduct($this->manager, $module, $productConfig);

        $first = $this->makeAccount($product);
        $second = $this->makeAccount($product);

        $this->makeTarget($first, $targetOverrides);

        return [$first, $second];
    }

    private function makePlainProduct(): Product
    {
        static $sequence = 0;
        $sequence++;

        return Product::create(['name' => "Unlinked Dashboard Product {$sequence}"]);
    }

    private function makeLinkedAccount(bool $enabled): HostingAccount
    {
        $module = $this->activateSnmpMonitorModule($this->manager);
        $product = $this->makeMonitoredProduct($this->manager, $module);

        if (! $enabled) {
            ProductModule::query()
                ->where('product_id', $product->id)
                ->where('module_id', $module->id)
                ->update(['enabled' => false]);
        }

        return $this->makeAccount($product);
    }

    private function targetId(HostingAccount $account): int
    {
        return (int) DB::table('snmp_targets')->where('hosting_account_id', $account->id)->value('id');
    }

    /**
     * @param  array<string, mixed>  $values  column => value on snmp_host_samples
     */
    private function insertHostSample(int $hostId, string $collectedAt, array $values = []): void
    {
        $this->monitoring()->table('snmp_host_samples')->insert(array_merge([
            'host_id' => $hostId,
            'collected_at' => $collectedAt,
        ], $values));
    }

    /**
     * @param  array<string, float|int|null>  $values  column => value on snmp_host_samples
     */
    private function insertSample(int $hostId, Carbon $at, array $values): void
    {
        $this->monitoring()->table('snmp_host_samples')->insert(array_merge([
            'host_id' => $hostId,
            'collected_at' => $at->format('Y-m-d H:i:s'),
            'uptime_secs' => null,
            'cpu_load1' => null,
            'cpu_load5' => null,
            'cpu_load15' => null,
            'cpu_pct' => null,
            'cpu_source' => null,
            'mem_total_mb' => null,
            'mem_used_mb' => null,
            'storage_pct' => null,
            'proc_count' => null,
            'response_ms' => null,
        ], $values));
    }

    private function seriesUrl(HostingAccount $account): string
    {
        return route('admin.snmp-monitor.series', $account);
    }

    /**
     * Reduce captured SQL to the monitored tables it touched.
     *
     * @param  list<string>  $sqlList
     * @return list<string>
     */
    private function queriedTables(array $sqlList): array
    {
        $tables = [];

        foreach (['snmp_host_samples', 'snmp_metric_hourly', 'snmp_if_samples', 'snmp_latest'] as $table) {
            foreach ($sqlList as $sql) {
                if (str_contains($sql, $table)) {
                    $tables[] = $table;

                    break;
                }
            }
        }

        return $tables;
    }

    private function actingAsAdmin(): self
    {
        return $this->actingAs($this->makeAdminUser(true));
    }

    private function actingAsAdminWithoutPermission(): self
    {
        return $this->actingAs($this->makeAdminUser(false));
    }

    private function makeAdminUser(bool $withHostingView): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        if ($withHostingView) {
            $perm = Permission::firstOrCreate(['name' => 'hosting.view'], ['label' => 'Hosting view']);
            $adminRole->permissions()->syncWithoutDetaching([$perm->id]);
        }

        $user->assignRole('admin');

        return $user;
    }
}
