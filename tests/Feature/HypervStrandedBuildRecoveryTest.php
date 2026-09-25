<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProvisionHypervVm;
use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\PanelAccount;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\ProvisioningEvent;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Provisioning\HypervVmBuildDispatcher;
use App\Services\Provisioning\VmStatusPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HypervStrandedBuildRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const VM = 'testvm1';
    private const GUID = '11111111-2222-3333-4444-555555555555';
    private const VHD = 'C:\\VMs\\testvm1.vhdx';

    // ────────── a: stale running is ignored and dispatch reconciles ──────────

    public function test_stale_running_event_does_not_block_and_dispatch_reconciles_and_pushes_to_provisioning_queue(): void
    {
        [$account] = $this->hostingWithHyperV(hostingStatus: 'suspended');

        $stale = ProvisioningEvent::create([
            'service_instance_id' => null,
            'hosting_account_id' => $account->id,
            'event_type' => 'provision',
            'status' => 'running',
            'event_status' => 'running',
            'payload' => ['module' => 'hyperv', 'action' => 'create', 'stage' => 'credentials', 'hosting_account_id' => $account->id],
        ]);
        // Push created_at past the stale threshold (2100s + margin).
        $stale->created_at = now()->subSeconds(ProvisioningEvent::RUNNING_STALE_AFTER_SECONDS + 500);
        $stale->save();
        $stale->refresh();

        $this->assertTrue($stale->isStaleRunning(), 'Setup did not create a stale event');

        $dispatcher = app(HypervVmBuildDispatcher::class);
        $this->assertFalse($dispatcher->isBuildRunning($account), 'Stale running event should not block a new build');

        Queue::fake();

        $newEvent = $dispatcher->dispatch($account, null, false, null, null);

        Queue::assertPushedOn('provisioning', ProvisionHypervVm::class);
        // Also ensure normal push queue assertion passes
        Queue::assertPushed(ProvisionHypervVm::class, 1);

        $stale->refresh();
        $this->assertSame('failed', $stale->status);
        $this->assertSame('failed', $stale->event_status);
        $this->assertNotNull($stale->last_error);
        $this->assertStringContainsString('interrupted before it finished', strtolower((string) $stale->last_error));
        $this->assertStringContainsString('retry the create', strtolower((string) $stale->last_error));

        // Exactly one new running event was opened (plus the now-failed stale row).
        $running = ProvisioningEvent::where('hosting_account_id', $account->id)->where('status', 'running')->get();
        $this->assertCount(1, $running);
        $this->assertSame($newEvent->id, $running->sole()->id);
        $this->assertSame('running', $newEvent->fresh()->status);

        $allForAccount = ProvisioningEvent::where('hosting_account_id', $account->id)->count();
        $this->assertSame(2, $allForAccount, 'Should be exactly stale-failed + new running');
    }

    // ────────── b: fresh running still blocks ──────────

    public function test_fresh_running_event_still_blocks_new_build(): void
    {
        [$account] = $this->hostingWithHyperV(hostingStatus: 'suspended');

        ProvisioningEvent::create([
            'service_instance_id' => null,
            'hosting_account_id' => $account->id,
            'event_type' => 'provision',
            'status' => 'running',
            'event_status' => 'running',
            'payload' => ['module' => 'hyperv', 'action' => 'create', 'stage' => 'cloning', 'hosting_account_id' => $account->id],
        ]);

        $dispatcher = app(HypervVmBuildDispatcher::class);
        $this->assertTrue($dispatcher->isBuildRunning($account), 'Fresh running event must still return true');
        // Do not assert that dispatch() throws — the controller guard is what
        // blocks; the dispatcher itself reconciles only stale rows.
    }

    // ────────── c: failed() handler flips running, idempotent ──────────

    public function test_job_failed_handler_flips_running_and_is_idempotent(): void
    {
        [$account] = $this->hostingWithHyperV(hostingStatus: 'suspended');

        $event = ProvisioningEvent::create([
            'service_instance_id' => null,
            'hosting_account_id' => $account->id,
            'event_type' => 'provision',
            'status' => 'running',
            'event_status' => 'running',
            'payload' => ['module' => 'hyperv', 'action' => 'create', 'stage' => 'credentials'],
        ]);

        (new ProvisionHypervVm($event->id, $account->id, null, false, null, null))->failed(new \RuntimeException('worker stopped'));

        $event->refresh();
        $this->assertSame('failed', $event->status);
        $this->assertSame('failed', $event->event_status);
        $this->assertNotEmpty($event->last_error);
        $this->assertStringContainsString('worker stopped', (string) $event->last_error);

        // Calling failed() again must be a no-op and must not throw.
        (new ProvisionHypervVm($event->id, $account->id, null, false, null, null))->failed(new \RuntimeException('worker stopped again'));
        $event->refresh();
        $this->assertSame('failed', $event->status);

        // Completed event is also a no-op.
        $completed = ProvisioningEvent::create([
            'service_instance_id' => null,
            'hosting_account_id' => $account->id,
            'event_type' => 'provision',
            'status' => 'completed',
            'event_status' => 'completed',
            'payload' => ['module' => 'hyperv', 'action' => 'create', 'stage' => 'done'],
            'result' => ['message' => 'done'],
        ]);

        (new ProvisionHypervVm($completed->id, $account->id, null, false, null, null))->failed(new \RuntimeException('worker stopped'));
        $completed->refresh();
        $this->assertSame('completed', $completed->status);

        // Missing event must not throw.
        (new ProvisionHypervVm(999999, $account->id, null, false, null, null))->failed(new \RuntimeException('missing'));
        $this->assertTrue(true);

        // Null exception → worker stopped fallback.
        $event2 = ProvisioningEvent::create([
            'service_instance_id' => null,
            'hosting_account_id' => $account->id,
            'event_type' => 'provision',
            'status' => 'running',
            'event_status' => 'running',
            'payload' => ['module' => 'hyperv', 'action' => 'create', 'stage' => 'queued'],
        ]);
        (new ProvisionHypervVm($event2->id, $account->id, null, false, null, null))->failed(null);
        $event2->refresh();
        $this->assertSame('failed', $event2->status);
        $this->assertStringContainsString('worker stopped', strtolower((string) $event2->last_error));
    }

    // ────────── d: presenter shows stale as interrupted, allows create when VM absent ──────────

    public function test_presenter_stale_interrupted_allows_create_when_vm_absent(): void
    {
        [$account] = $this->hostingWithHyperV(hostingStatus: 'suspended');

        $stale = ProvisioningEvent::create([
            'service_instance_id' => null,
            'hosting_account_id' => $account->id,
            'event_type' => 'provision',
            'status' => 'running',
            'event_status' => 'running',
            'payload' => ['module' => 'hyperv', 'action' => 'create', 'stage' => 'credentials', 'hosting_account_id' => $account->id],
        ]);
        $stale->created_at = now()->subSeconds(ProvisioningEvent::RUNNING_STALE_AFTER_SECONDS + 800);
        $stale->save();
        $stale->refresh();

        $this->assertTrue($stale->isStaleRunning());

        // No PanelAccount → vm.exists false. Even with a panel, fake host as missing.
        // We do not need Http for the no-panel case, but fake anyway to prove
        // presenter stays read-only and does not attempt host calls when running=false.
        Http::fake(function ($request) {
            $body = (string) $request->body();
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => false]);
            }
            return Http::response(['error' => 'unexpected host call'], 500);
        });

        // Via HTTP polling endpoint (read path must not write).
        $json = $this->actingAsAdminWith(['hosting.view'])
            ->getJson(route('admin.hosting.vm-status', $account))
            ->assertOk()
            ->json();

        $this->assertFalse($json['action']['running'], 'Stale event should be presented as not running');
        $this->assertSame('credentials', $json['action']['stage']);
        // Progress for credentials is 92 per VmStatusPresenter::STAGES
        $this->assertSame(92, $json['action']['progress']);
        $this->assertStringContainsString('Interrupted', (string) $json['action']['stage_label']);
        $this->assertStringContainsString('interrupted before it finished', strtolower((string) $json['action']['message']));
        $this->assertNull($json['action']['error']);
        $this->assertSame($stale->id, $json['action']['event_id']);

        // Permissions must allow create when VM is absent (no panel / exists false).
        $this->assertTrue($json['can']['create'], 'Create should be allowed after interruption when VM is absent');
        $this->assertFalse($json['vm']['exists']);

        // Presenter must be read-only: row still running in DB.
        $this->assertSame('running', $stale->fresh()->status);

        // Second panel variant: ensure with stale, vm probe is not skipped
        // (running false → probe runs). We fake missing again and assert still allow create.
        $json2 = app(VmStatusPresenter::class)->build($account->fresh());
        $this->assertFalse($json2['action']['running']);
        $this->assertTrue($json2['can']['create']);
    }

    public function test_presenter_fresh_running_still_blocks_all_actions(): void
    {
        [$account] = $this->hostingWithHyperV(hostingStatus: 'suspended');
        ProvisioningEvent::create([
            'service_instance_id' => null,
            'hosting_account_id' => $account->id,
            'event_type' => 'provision',
            'status' => 'running',
            'event_status' => 'running',
            'payload' => ['module' => 'hyperv', 'action' => 'create', 'stage' => 'cloning'],
        ]);

        $json = $this->actingAsAdminWith(['hosting.view'])
            ->getJson(route('admin.hosting.vm-status', $account))->json();

        $this->assertTrue($json['action']['running']);
        $this->assertSame('cloning', $json['action']['stage']);
        foreach (['create', 'start', 'stop', 'restart', 'delete', 'reset_password'] as $verb) {
            $this->assertFalse($json['can'][$verb]);
            $this->assertSame('An action is already running.', $json['reasons'][$verb]);
        }
    }

    // ────────── e: job queue name ──────────

    public function test_job_is_pushed_onto_provisioning_queue(): void
    {
        [$account] = $this->hostingWithHyperV(hostingStatus: 'suspended');

        Queue::fake();

        $dispatcher = app(HypervVmBuildDispatcher::class);
        $dispatcher->dispatch($account, null, false, null, null);

        Queue::assertPushedOn('provisioning', ProvisionHypervVm::class);

        $job = new ProvisionHypervVm(1, $account->id, null, false, null, null);
        $this->assertSame('provisioning', $job->queue);
    }

    public function test_is_stale_running_tolerates_null_created_at_and_non_running(): void
    {
        [$account] = $this->hostingWithHyperV(hostingStatus: 'suspended');

        $failed = ProvisioningEvent::create([
            'service_instance_id' => null,
            'hosting_account_id' => $account->id,
            'event_type' => 'provision',
            'status' => 'failed',
            'event_status' => 'failed',
            'payload' => ['module' => 'hyperv', 'action' => 'create', 'stage' => 'credentials'],
        ]);
        $failed->created_at = now()->subSeconds(ProvisioningEvent::RUNNING_STALE_AFTER_SECONDS + 5000);
        $failed->save();
        $this->assertFalse($failed->fresh()->isStaleRunning(), 'Non-running status must never be stale');

        // Null created_at tolerance — use raw DB insert to bypass Eloquent timestamp
        $id = \Illuminate\Support\Facades\DB::table('provisioning_events')->insertGetId([
            'service_instance_id' => null,
            'hosting_account_id' => $account->id,
            'event_type' => 'provision',
            'status' => 'running',
            'event_status' => 'running',
            'payload' => json_encode(['module' => 'hyperv', 'action' => 'create', 'stage' => 'credentials']),
            'created_at' => null,
            'result' => null,
            'last_error' => null,
        ]);
        $nullCreated = ProvisioningEvent::find($id);
        $this->assertFalse($nullCreated->isStaleRunning(), 'Null created_at must return false');
        $this->assertFalse(app(HypervVmBuildDispatcher::class)->isBuildRunning($account) && $nullCreated->isStaleRunning() ? true : $nullCreated->isStaleRunning(), 'Null created_at stale check');
        // Sanity: isBuildRunning for null-created row should still consider it running (not stale)
        // because isStaleRunning false → the contains should treat it as blocking.
        // To avoid cross-pollution, delete other running rows and test isolated.
        ProvisioningEvent::where('hosting_account_id', $account->id)->where('id', '!=', $id)->delete();
        $this->assertTrue(app(HypervVmBuildDispatcher::class)->isBuildRunning($account), 'Null created_at running event should still block (not considered stale)');
    }

    // ─────────────────────────── helpers ───────────────────────────

    private function hypervServer(): Server
    {
        return Server::create([
            'name' => 'hv-1',
            'ip_address' => '10.0.0.9',
            'server_type' => 'hyperv',
            'api_url' => 'http://10.0.0.9:5985',
            'api_username' => 'admin',
            'api_password_encrypted' => 'SECRET',
            'max_accounts' => 0,
            'status' => 'active',
        ]);
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);
    }

    private function actingAsAdminWith(array $permissionNames): self
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $ids = [];
        foreach ($permissionNames as $name) {
            $ids[] = Permission::firstOrCreate(['name' => $name], ['label' => $name])->id;
        }
        $role->permissions()->sync($ids);
        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    /**
     * @return array{0:HostingAccount,1?:ServiceInstance}
     */
    private function hostingWithHyperV(bool $withPanelAccount = false, string $hostingStatus = 'suspended', bool $withGuest = false, array $linkConfig = []): array
    {
        $server = $this->hypervServer();
        $product = Product::create(['name' => 'HV', 'price' => 50]);
        ProductModule::create([
            'product_id' => $product->id, 'module_slug' => 'hyperv', 'enabled' => true, 'config' => $linkConfig,
        ]);
        $customer = $this->makeCustomer();
        $account = HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'server_id' => $server->id,
            'domain' => 'vm.test',
            'host_name' => 'hv-web-'.str()->lower(str()->random(6)),
            'status' => $hostingStatus,
        ]);

        if ($withPanelAccount) {
            $service = ServiceInstance::create([
                'customer_id' => $customer->id,
                'order_id' => null,
                'server_id' => $server->id,
                'domain' => 'vm.test',
                'service_tag' => 'HOST-'.$account->id,
                'username' => self::VM,
                'provisioning_method' => 'hyperv',
                'status' => 'pending',
            ]);
            $data = [
                'service_instance_id' => $service->id,
                'server_id' => $server->id,
                'panel' => 'hyperv',
                'username' => self::VM,
                'external_id' => self::GUID,
                'meta' => ['vmName' => self::VM, 'meta' => ['vmName' => self::VM, 'vhdPath' => self::VHD]],
                'status' => PanelAccount::STATUS_ACTIVE,
            ];
            if ($withGuest) {
                $data['guest_username'] = 'Administrator';
                $data['guest_password_encrypted'] = 'OldSecret123';
            }
            PanelAccount::create($data);

            return [$account->fresh(), $service];
        }

        return [$account->fresh()];
    }
}
