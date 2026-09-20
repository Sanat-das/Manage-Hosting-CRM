<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HostingAccount;
use App\Models\Server;
use App\ViewModels\Admin\ServerDetailViewModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Todo 14 evidence: canonical parser coverage for ServerDetailViewModel.
 *
 * Extends ServerSnmpLatestBridgeTest (todo 9, SNMP bridge) — no SNMP bridge
 * cases here. Pins: nested-wins, flat/case fallbacks, derived math (never
 * reads persisted derived keys), latencyMs legacy input, drift flag, and the
 * SNMP-absent fast path. Includes the NEGATIVE CONTROL: remoteTotal=5 /
 * local=3 must yield hasDrift=true AND the drift badge text in the rendered
 * Essential panels (suite fails if the badge is absent).
 */
final class ServerEssentialViewModelTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private function makeServer(array $overrides = []): Server
    {
        ++self::$seq;

        return Server::create(array_merge([
            'name' => 'Essential VM Srv '.self::$seq,
            'ip_address' => '192.0.2.'.(10 + self::$seq),
            'server_type' => 'cpanel',
            'status' => 'active',
            'connection_status' => 'connected',
        ], $overrides));
    }

    private function makeLocalAccounts(Server $server, int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            HostingAccount::create([
                'customer_id' => 1000 + self::$seq * 10 + $i,
                'product_id' => 2000 + $i,
                'server_id' => $server->id,
                'username' => 'essuser'.self::$seq.'_'.$i,
                'status' => 'active',
            ]);
        }
    }

    public function test_nested_meta_wins_over_flat_keys(): void
    {
        $server = $this->makeServer([
            'connection_meta' => [
                'hostname' => 'flat-host.example.com',
                'hostOS' => 'Flat OS 1.0',
                'meta' => [
                    'hostname' => 'nested-host.example.com',
                    'hostOS' => 'Nested OS 2.0',
                ],
            ],
        ]);

        $vm = ServerDetailViewModel::fromServer($server->refresh());

        $this->assertSame('nested-host.example.com', $vm->hostname);
        $this->assertSame('Nested OS 2.0', $vm->hostOS);

        fwrite(STDERR, "\n[EVIDENCE nested-wins] hostname={$vm->hostname} hostOS={$vm->hostOS}\n");
    }

    public function test_flat_keys_used_when_nested_absent(): void
    {
        $server = $this->makeServer([
            'connection_meta' => [
                'hostname' => 'flat-only.example.com',
                'ramTotal' => 8589934592,
                'ramFree' => 4294967296,
            ],
        ]);

        $vm = ServerDetailViewModel::fromServer($server->refresh());

        $this->assertSame('flat-only.example.com', $vm->hostname);
        $this->assertSame(8589934592, $vm->ramTotal);
        $this->assertSame(4294967296, $vm->ramFree);

        fwrite(STDERR, "\n[EVIDENCE flat-fallback] hostname={$vm->hostname} ramTotal={$vm->ramTotal}\n");
    }

    public function test_case_and_snake_variants_fall_back(): void
    {
        $server = $this->makeServer([
            'server_type' => 'hyperv',
            'connection_meta' => [
                'meta' => [
                    'HOSTNAME' => 'case-host.example.com',
                    'Host_Os' => 'Case OS 3.0',
                    'LOGICAL_CPU' => 16,
                    'Ram_Total' => 17179869184,
                    'TOTALACCOUNTS' => 7,
                ],
            ],
        ]);

        $vm = ServerDetailViewModel::fromServer($server->refresh());

        $this->assertSame('case-host.example.com', $vm->hostname);
        $this->assertSame('Case OS 3.0', $vm->hostOS);
        $this->assertSame(16, $vm->logicalCpu);
        $this->assertSame(17179869184, $vm->ramTotal);
        // Non-Hyper-V remoteTotal path reads totalAccounts case-insensitively too.
        $this->assertSame(7, $vm->remoteTotal);

        fwrite(STDERR, "\n[EVIDENCE case-fallback] hostname={$vm->hostname} logicalCpu={$vm->logicalCpu} remoteTotal={$vm->remoteTotal}\n");
    }

    public function test_derived_math_recomputed_and_ignores_persisted_derived_keys(): void
    {
        $server = $this->makeServer([
            'server_type' => 'hyperv',
            'connection_meta' => [
                'meta' => [
                    'ramTotal' => 8589934592,   // 8 GiB
                    'ramFree' => 2147483648,    // 2 GiB free → 6 GiB used, 75%
                    'storageTotal' => 100000000000,
                    'storageFree' => 40000000000, // → 60 GB used, 60%
                    // Poison: persisted derived keys must NEVER be read.
                    'ramUsed' => 1,
                    'ramPct' => 1,
                    'storageUsed' => 2,
                    'storagePct' => 2,
                ],
            ],
        ]);

        $vm = ServerDetailViewModel::fromServer($server->refresh());

        $this->assertSame(6442450944, $vm->ramUsed);
        $this->assertSame(75, (int) $vm->ramPct);
        $this->assertSame(60000000000, $vm->storageUsed);
        $this->assertSame(60, (int) $vm->storagePct);

        fwrite(STDERR, "\n[EVIDENCE derived-math] ramUsed={$vm->ramUsed} ramPct={$vm->ramPct} storageUsed={$vm->storageUsed} storagePct={$vm->storagePct}\n");
    }

    public function test_latencyms_is_legacy_input_only(): void
    {
        $server = $this->makeServer([
            'connection_meta' => ['meta' => ['latencyMs' => 42]],
        ]);

        $vm = ServerDetailViewModel::fromServer($server->refresh());

        $this->assertSame(42, $vm->latency);
        $this->assertArrayNotHasKey('latency', $vm->meta['meta'] ?? []);

        fwrite(STDERR, "\n[EVIDENCE latency] latency={$vm->latency}\n");
    }

    public function test_hyperv_vmcounts_total_wins_for_remote(): void
    {
        $server = $this->makeServer([
            'server_type' => 'hyperv',
            'connection_meta' => [
                'totalAccounts' => 99, // flat must lose to vmCounts.total
                'meta' => ['vmCounts' => ['running' => 2, 'stopped' => 1, 'total' => 3]],
            ],
        ]);

        $vm = ServerDetailViewModel::fromServer($server->refresh());

        $this->assertSame(3, $vm->remoteTotal);
        $this->assertTrue($vm->hasVmCounts);

        fwrite(STDERR, "\n[EVIDENCE vmcounts-wins] remoteTotal={$vm->remoteTotal}\n");
    }

    public function test_no_drift_when_remote_matches_local(): void
    {
        $server = $this->makeServer(['connection_meta' => ['totalAccounts' => 3]]);
        $this->makeLocalAccounts($server, 3);

        $vm = ServerDetailViewModel::fromServer($server->refresh());

        $this->assertSame(3, $vm->remoteTotal);
        $this->assertSame(3, $vm->localTotal);
        $this->assertFalse($vm->hasDrift);

        $html = view('admin.servers.partials._essential-panels', [
            'server' => $server->refresh(), 'vm' => $vm,
        ])->render();

        $this->assertStringNotContainsString('drift-badge', $html);

        fwrite(STDERR, "\n[EVIDENCE no-drift] remote=3 local=3 hasDrift=false badge absent\n");
    }

    public function test_null_remote_never_drifts_and_plesk_zero_is_no_remote_data(): void
    {
        $plain = $this->makeServer(['connection_meta' => []]);
        $this->makeLocalAccounts($plain, 2);
        $plainVm = ServerDetailViewModel::fromServer($plain->refresh());
        $this->assertNull($plainVm->remoteTotal);
        $this->assertFalse($plainVm->hasDrift);

        $plesk = $this->makeServer(['server_type' => 'plesk', 'connection_meta' => ['totalAccounts' => 0]]);
        $pleskVm = ServerDetailViewModel::fromServer($plesk->refresh());
        $this->assertNull($pleskVm->remoteTotal);
        $this->assertFalse($pleskVm->hasDrift);

        $html = view('admin.servers.partials._essential-panels', [
            'server' => $plesk->refresh(), 'vm' => $pleskVm,
        ])->render();
        $this->assertStringContainsString('No remote data', $html);

        fwrite(STDERR, "\n[EVIDENCE null-remote] plain remote=null plesk remote=null, no drift, plesk shows No remote data\n");
    }

    public function test_non_numeric_remote_treated_as_missing(): void
    {
        $server = $this->makeServer(['connection_meta' => ['totalAccounts' => 'many']]);
        $this->makeLocalAccounts($server, 1);

        $vm = ServerDetailViewModel::fromServer($server->refresh());

        $this->assertNull($vm->remoteTotal);
        $this->assertFalse($vm->hasDrift);

        fwrite(STDERR, "\n[EVIDENCE non-numeric-remote] remote=null hasDrift=false\n");
    }

    /**
     * NEGATIVE CONTROL: an intentionally-mismatched census fixture
     * (remoteTotal=5, local ledger=3) MUST report hasDrift=true AND render
     * the drift badge text. If the badge ever goes missing, this test fails.
     */
    public function test_negative_control_drift_mismatch_reports_badge(): void
    {
        $server = $this->makeServer(['connection_meta' => ['totalAccounts' => 5]]);
        $this->makeLocalAccounts($server, 3);

        $vm = ServerDetailViewModel::fromServer($server->refresh());

        $this->assertSame(5, $vm->remoteTotal);
        $this->assertSame(3, $vm->localTotal);
        $this->assertTrue($vm->hasDrift, 'Mismatched census (remote 5 vs local 3) must flag drift.');

        $html = view('admin.servers.partials._essential-panels', [
            'server' => $server->refresh(), 'vm' => $vm,
        ])->render();

        $this->assertStringContainsString('data-census="drift-badge"', $html);
        $this->assertStringContainsString('Remote differs from ledger', $html);

        fwrite(STDERR, "\n[EVIDENCE drift-negative-control] remote=5 local=3 hasDrift=true badge present\n");
    }

    public function test_snmp_absent_fast_path_stays_null(): void
    {
        $server = $this->makeServer();

        $start = microtime(true);
        $vm = ServerDetailViewModel::fromServer($server->refresh());
        $elapsed = microtime(true) - $start;

        $this->assertNull($vm->snmpSource);
        $this->assertNull($vm->snmpCollectedAt);
        $this->assertNull($vm->snmpUptime);
        $this->assertNull($vm->snmpCpu);
        $this->assertNull($vm->snmpMem);
        $this->assertNull($vm->snmpDisks);
        $this->assertLessThan(2.0, $elapsed, 'SNMP-absent ViewModel build must complete <2s.');

        fwrite(STDERR, sprintf("\n[EVIDENCE snmp-absent] all snmp* null, build elapsed=%.4fs (bound <2s)\n", $elapsed));
    }
}
