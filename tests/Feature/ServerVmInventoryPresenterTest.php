<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\PanelAccount;
use App\Models\ServiceInstance;
use App\Models\User;
use App\ViewModels\Admin\ServerDetailViewModel;
use App\ViewModels\Admin\ServerVmInventoryPresenter;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ServerVmInventoryPresenterTest extends TestCase
{
    private const GUID_UPPER = 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE';

    private const GUID_LOWER = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    private const OTHER_GUID = '11111111-2222-3333-4444-555555555555';

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makePanel(array $attributes = [], ?ServiceInstance $serviceInstance = null): PanelAccount
    {
        $panel = new PanelAccount($attributes);
        if ($serviceInstance !== null) {
            $panel->setRelation('serviceInstance', $serviceInstance);
        }

        return $panel;
    }

    private function makeServiceInstance(?Order $order = null, ?Customer $customer = null): ServiceInstance
    {
        $instance = new ServiceInstance();
        $instance->forceFill(['status' => 'active']);
        $instance->setRelation('order', $order);
        $instance->setRelation('customer', $customer);

        return $instance;
    }

    private function makeCustomer(?User $user = null): Customer
    {
        $customer = new Customer();
        $customer->forceFill(['company' => 'Acme Ltd']);
        $customer->setRelation('user', $user);

        return $customer;
    }

    private function makeUser(): User
    {
        $user = new User();
        $user->forceFill([
            'email' => 'jane@example.com',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);

        return $user;
    }

    private function makeOrder(): Order
    {
        $order = new Order();
        $order->id = 7;
        $order->forceFill(['order_number' => 'ORD-0007', 'status' => 'active']);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function makeLiveRow(string $vmId, array $overrides = []): array
    {
        return array_merge([
            'name' => 'vm-01',
            'state' => 'Running',
            'uptime' => '2.05:00:00',
            'cpuUsage' => 12,
            'memoryAssigned' => 4294967296,
            'memoryDemand' => 2147483648,
            'processorCount' => 4,
            'version' => '9.0',
            'vmId' => $vmId,
            'switchName' => 'External',
            'vhdPath' => 'C:\\VMs\\vm-01.vhdx',
        ], $overrides);
    }

    public function test_matches_provisioned_vm_to_live_row_case_insensitively(): void
    {
        $order = $this->makeOrder();
        $customer = $this->makeCustomer($this->makeUser());
        $panel = $this->makePanel([
            'username' => 'vm-01',
            'external_id' => self::GUID_UPPER,
            'status' => 'active',
            'meta' => ['meta' => ['vmName' => 'vm-01']],
            'provisioned_at' => Carbon::now()->subDays(2),
        ], $this->makeServiceInstance($order, $customer));

        $result = ServerVmInventoryPresenter::build([$panel], [$this->makeLiveRow(self::GUID_LOWER)]);

        $this->assertFalse($result['liveUnavailable']);
        $this->assertSame(1, $result['liveTotal']);
        $this->assertSame(1, $result['matchedCount']);
        $this->assertSame(0, $result['missingOnHostCount']);
        $this->assertSame([], $result['unmatchedLive']);

        $row = $result['rows'][0];
        $this->assertTrue($row['hostMatch']);
        $this->assertSame('Running', $row['liveState']);
        $this->assertSame('success', $row['liveStateTheme']);
        $this->assertSame('2.05:00:00', $row['liveUptime']);
        $this->assertSame(12, $row['liveCpu']);
        $this->assertSame(4, $row['liveVcpu']);
        $this->assertSame(4294967296, $row['liveMemoryAssigned']);
        $this->assertSame(2147483648, $row['liveMemoryDemand']);

        $this->assertSame('vm-01', $row['vmName']);
        $this->assertSame('vm-01', $row['username']);
        $this->assertSame(self::GUID_UPPER, $row['externalId']);
        $this->assertSame('AAAAAAAA…EEEE', $row['externalIdShort']);
        $this->assertSame('Jane Doe', $row['customerName']);
        $this->assertSame('ORD-0007', $row['orderNumber']);
        $this->assertSame('active', $row['orderStatus']);
        $this->assertSame(route('admin.orders.show', ['order' => 7]), $row['orderUrl']);
        $this->assertSame('active', $row['provisionStatus']);
    }

    public function test_provisioned_vm_without_live_counterpart(): void
    {
        $panel = $this->makePanel([
            'username' => 'vm-orphan',
            'external_id' => self::OTHER_GUID,
            'status' => 'active',
            'meta' => ['vmName' => 'vm-orphan'],
        ]);

        $result = ServerVmInventoryPresenter::build([$panel], [$this->makeLiveRow(self::GUID_LOWER)]);

        $row = $result['rows'][0];
        $this->assertFalse($row['hostMatch']);
        $this->assertNull($row['liveState']);
        $this->assertNull($row['liveUptime']);
        $this->assertNull($row['liveCpu']);
        $this->assertNull($row['liveVcpu']);
        $this->assertNull($row['liveMemoryAssigned']);
        $this->assertNull($row['liveMemoryDemand']);

        $this->assertSame(0, $result['matchedCount']);
        $this->assertSame(1, $result['missingOnHostCount']);
        $this->assertCount(1, $result['unmatchedLive']);
    }

    public function test_live_vm_without_provisioned_counterpart_appears_in_unmatched_live(): void
    {
        $result = ServerVmInventoryPresenter::build([], [$this->makeLiveRow(self::GUID_LOWER, ['state' => 'Off'])]);

        $this->assertSame([], $result['rows']);
        $this->assertSame(0, $result['matchedCount']);
        $this->assertSame(0, $result['missingOnHostCount']);
        $this->assertSame(1, $result['liveTotal']);
        $this->assertFalse($result['liveUnavailable']);

        $unmatched = $result['unmatchedLive'][0];
        $this->assertSame('vm-01', $unmatched['name']);
        $this->assertSame('Off', $unmatched['state']);
        $this->assertSame('secondary', $unmatched['stateTheme']);
        $this->assertSame('2.05:00:00', $unmatched['uptime']);
        $this->assertSame(12, $unmatched['cpuUsage']);
        $this->assertSame(4, $unmatched['processorCount']);
        $this->assertSame(4294967296, $unmatched['memoryAssigned']);
        $this->assertSame(2147483648, $unmatched['memoryDemand']);
        $this->assertSame('External', $unmatched['switchName']);
        $this->assertSame(self::GUID_LOWER, $unmatched['vmId']);
        $this->assertSame('aaaaaaaa…eeee', $unmatched['vmIdShort']);
    }

    public function test_blank_ids_on_either_side_never_match(): void
    {
        $panels = [
            $this->makePanel(['username' => 'null-id', 'external_id' => null, 'status' => 'active']),
            $this->makePanel(['username' => 'empty-id', 'external_id' => '', 'status' => 'active']),
            $this->makePanel(['username' => 'space-id', 'external_id' => '   ', 'status' => 'active']),
        ];
        $liveRows = [
            $this->makeLiveRow(''),
            $this->makeLiveRow('   '),
            $this->makeLiveRow(self::GUID_LOWER),
        ];

        $result = ServerVmInventoryPresenter::build($panels, $liveRows);

        foreach ($result['rows'] as $row) {
            $this->assertFalse($row['hostMatch']);
            $this->assertNull($row['liveState']);
        }
        $this->assertSame('—', $result['rows'][0]['externalIdShort']);
        $this->assertNull($result['rows'][0]['externalId']);

        $this->assertSame(0, $result['matchedCount']);
        $this->assertSame(3, $result['missingOnHostCount']);
        $this->assertSame(3, $result['liveTotal']);

        // Every live row (including the blank-id ones) is unmatched.
        $this->assertCount(3, $result['unmatchedLive']);
        $this->assertNull($result['unmatchedLive'][0]['vmId']);
        $this->assertSame('—', $result['unmatchedLive'][0]['vmIdShort']);
    }

    public function test_null_live_rows_marks_inventory_unavailable(): void
    {
        $panels = [
            $this->makePanel(['username' => 'vm-01', 'external_id' => self::GUID_UPPER, 'status' => 'active']),
        ];

        $result = ServerVmInventoryPresenter::build($panels, null);

        $this->assertTrue($result['liveUnavailable']);
        $this->assertSame(0, $result['liveTotal']);
        $this->assertSame([], $result['unmatchedLive']);
        $this->assertSame(0, $result['matchedCount']);
        $this->assertSame(1, $result['missingOnHostCount']);

        $this->assertFalse($result['rows'][0]['hostMatch']);
        $this->assertNull($result['rows'][0]['liveState']);
    }

    public function test_nested_meta_vm_name_wins_over_flat(): void
    {
        $nested = $this->makePanel([
            'username' => 'vm-nested',
            'external_id' => self::GUID_UPPER,
            'status' => 'active',
            'meta' => [
                'meta' => ['vmName' => 'Nested-Name', 'name' => 'Nested-Fallback'],
                'vmName' => 'Flat-Name',
                'name' => 'Flat-Fallback',
            ],
        ]);
        $flat = $this->makePanel([
            'username' => 'vm-flat',
            'external_id' => self::OTHER_GUID,
            'status' => 'active',
            'meta' => ['vmName' => 'Flat-Only'],
        ]);
        $absent = $this->makePanel([
            'username' => 'vm-bare',
            'external_id' => null,
            'status' => 'active',
            'meta' => [],
        ]);

        $result = ServerVmInventoryPresenter::build([$nested, $flat, $absent], null);

        $this->assertSame('Nested-Name', $result['rows'][0]['vmName']);
        $this->assertSame('Flat-Only', $result['rows'][1]['vmName']);
        $this->assertNull($result['rows'][2]['vmName']);
    }

    public function test_built_at_prefers_provisioned_at_and_falls_back_to_created_at(): void
    {
        $provisionedAt = Carbon::now()->subDays(2)->startOfMinute();
        $createdAt = Carbon::now()->subDays(5)->startOfMinute();

        $withProvisioned = $this->makePanel([
            'username' => 'vm-01',
            'external_id' => self::GUID_UPPER,
            'status' => 'active',
        ]);
        $withProvisioned->setAttribute('provisioned_at', $provisionedAt);
        $withProvisioned->setAttribute('created_at', $createdAt);

        $withoutProvisioned = $this->makePanel([
            'username' => 'vm-02',
            'external_id' => self::OTHER_GUID,
            'status' => 'active',
        ]);
        $withoutProvisioned->setAttribute('provisioned_at', null);
        $withoutProvisioned->setAttribute('created_at', $createdAt);

        $result = ServerVmInventoryPresenter::build([$withProvisioned, $withoutProvisioned], null);

        $this->assertSame($provisionedAt->format('Y-m-d H:i'), $result['rows'][0]['builtAtAbsolute']);
        $this->assertStringContainsString('ago', (string) $result['rows'][0]['builtAtHuman']);

        $this->assertSame($createdAt->format('Y-m-d H:i'), $result['rows'][1]['builtAtAbsolute']);
        $this->assertStringContainsString('ago', (string) $result['rows'][1]['builtAtHuman']);
    }

    public function test_customer_email_fallback_and_short_id_passthrough(): void
    {
        $panel = [
            'username' => 'vm-array',
            'external_id' => 'short-id',
            'status' => 'active',
            'meta' => [],
            'serviceInstance' => [
                'customer' => ['user' => ['email' => 'owner@example.com']],
                'order' => null,
            ],
        ];

        $result = ServerVmInventoryPresenter::build([$panel], null);

        $row = $result['rows'][0];
        $this->assertSame('owner@example.com', $row['customerName']);
        $this->assertNull($row['orderNumber']);
        $this->assertNull($row['orderUrl']);
        // Ids of 16 chars or fewer pass through unshortened.
        $this->assertSame('short-id', $row['externalIdShort']);
    }

    public function test_terminated_unmatched_row_is_absent_not_missing(): void
    {
        $panel = $this->makePanel([
            'username' => 'vm-gone',
            'external_id' => self::OTHER_GUID,
            'status' => 'terminated',
            'meta' => ['vmName' => 'vm-gone'],
        ]);

        $result = ServerVmInventoryPresenter::build([$panel], [$this->makeLiveRow(self::GUID_LOWER)]);

        $row = $result['rows'][0];
        $this->assertFalse($row['hostMatch']);
        $this->assertSame('absent', $row['hostPresence']);
        $this->assertSame('removed', $row['absentLabel']);
        $this->assertSame(0, $result['missingUnexpectedCount']);
        $this->assertSame(1, $result['absentExpectedCount']);
        // Legacy tally is untouched: the row still counts as not-on-host.
        $this->assertSame(1, $result['missingOnHostCount']);
    }

    public function test_pending_unmatched_row_is_absent_not_missing(): void
    {
        $panel = $this->makePanel([
            'username' => 'vm-waiting',
            'external_id' => self::OTHER_GUID,
            'status' => 'pending',
            'meta' => ['vmName' => 'vm-waiting'],
        ]);

        $result = ServerVmInventoryPresenter::build([$panel], [$this->makeLiveRow(self::GUID_LOWER)]);

        $row = $result['rows'][0];
        $this->assertFalse($row['hostMatch']);
        $this->assertSame('absent', $row['hostPresence']);
        $this->assertSame('not provisioned', $row['absentLabel']);
        $this->assertSame(0, $result['missingUnexpectedCount']);
        $this->assertSame(1, $result['absentExpectedCount']);
    }

    public function test_active_unmatched_row_is_missing(): void
    {
        $panel = $this->makePanel([
            'username' => 'vm-orphan',
            'external_id' => self::OTHER_GUID,
            'status' => 'active',
            'meta' => ['vmName' => 'vm-orphan'],
        ]);

        $result = ServerVmInventoryPresenter::build([$panel], [$this->makeLiveRow(self::GUID_LOWER)]);

        $row = $result['rows'][0];
        $this->assertFalse($row['hostMatch']);
        $this->assertSame('missing', $row['hostPresence']);
        $this->assertNull($row['absentLabel']);
        $this->assertSame(1, $result['missingUnexpectedCount']);
        $this->assertSame(0, $result['absentExpectedCount']);
    }

    public function test_null_status_unmatched_row_is_missing(): void
    {
        $panel = $this->makePanel([
            'username' => 'vm-unknown',
            'external_id' => self::OTHER_GUID,
            'meta' => ['vmName' => 'vm-unknown'],
        ]);

        $result = ServerVmInventoryPresenter::build([$panel], [$this->makeLiveRow(self::GUID_LOWER)]);

        $row = $result['rows'][0];
        $this->assertFalse($row['hostMatch']);
        $this->assertSame('missing', $row['hostPresence']);
        $this->assertNull($row['absentLabel']);
        $this->assertSame(1, $result['missingUnexpectedCount']);
        $this->assertSame(0, $result['absentExpectedCount']);
    }

    public function test_matched_row_is_matched_regardless_of_status(): void
    {
        $panels = [
            $this->makePanel([
                'username' => 'vm-term',
                'external_id' => self::GUID_UPPER,
                'status' => 'terminated',
                'meta' => ['vmName' => 'vm-term'],
            ]),
            $this->makePanel([
                'username' => 'vm-pend',
                'external_id' => self::OTHER_GUID,
                'status' => '  PENDING  ',
                'meta' => ['vmName' => 'vm-pend'],
            ]),
        ];
        $liveRows = [
            $this->makeLiveRow(self::GUID_LOWER),
            $this->makeLiveRow(self::OTHER_GUID),
        ];

        $result = ServerVmInventoryPresenter::build($panels, $liveRows);

        foreach ($result['rows'] as $row) {
            $this->assertTrue($row['hostMatch']);
            $this->assertSame('matched', $row['hostPresence']);
            $this->assertNull($row['absentLabel']);
        }
        $this->assertSame(0, $result['missingUnexpectedCount']);
        $this->assertSame(0, $result['absentExpectedCount']);
        $this->assertSame(0, $result['missingOnHostCount']);
    }

    public function test_presence_classification_trims_and_ignores_case(): void
    {
        $panels = [
            $this->makePanel(['username' => 'vm-t', 'external_id' => null, 'status' => '  TERMINATED  ']),
            $this->makePanel(['username' => 'vm-p', 'external_id' => null, 'status' => 'Pending']),
        ];

        $result = ServerVmInventoryPresenter::build($panels, []);

        $this->assertSame('absent', $result['rows'][0]['hostPresence']);
        $this->assertSame('removed', $result['rows'][0]['absentLabel']);
        $this->assertSame('absent', $result['rows'][1]['hostPresence']);
        $this->assertSame('not provisioned', $result['rows'][1]['absentLabel']);
        $this->assertSame(0, $result['missingUnexpectedCount']);
        $this->assertSame(2, $result['absentExpectedCount']);
    }

    public function test_vm_list_renders_absent_neutral_and_missing_warning(): void
    {
        $terminated = $this->makePanel([
            'username' => 'vm-gone',
            'external_id' => self::OTHER_GUID,
            'status' => 'terminated',
            'meta' => ['vmName' => 'vm-gone'],
        ]);
        $active = $this->makePanel([
            'username' => 'vm-orphan',
            'external_id' => 'deadbeef-dead-beef-dead-beefdeadbeef',
            'status' => 'active',
            'meta' => ['vmName' => 'vm-orphan'],
        ]);

        $result = ServerVmInventoryPresenter::build(
            [$terminated, $active],
            [$this->makeLiveRow(self::GUID_LOWER)]
        );

        $html = view('admin.servers.partials._vm-list', ['server' => new \App\Models\Server(), 'vm' => null, 'vmInventory' => $result, 'panelAccounts' => collect()])->render();

        // Terminated unmatched row renders the neutral label…
        $this->assertStringContainsString('removed', $html);
        $this->assertStringContainsString('text-bg-light border text-muted fw-normal', $html);
        // …and only the active unmatched row warns.
        $this->assertSame(1, substr_count($html, 'not found on host'));
    }

    public function test_fmt_bytes_delegates_to_server_detail_view_model(): void
    {
        foreach ([0, 512, 2048, 4294967296, -1, null] as $bytes) {
            $this->assertSame(
                ServerDetailViewModel::fmtBytes($bytes),
                ServerVmInventoryPresenter::fmtBytes($bytes)
            );
        }
    }
}
