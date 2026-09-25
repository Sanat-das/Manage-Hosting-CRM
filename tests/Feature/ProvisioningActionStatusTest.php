<?php

declare(strict_types=1);

namespace Tests\Feature;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Provisioning attempt audit for admin module actions.
 *
 * Every driver-attempt module action (except hyperv create, which
 * ManualProvisioner already records) writes one durable provisioning_events
 * row (running -> completed/failed), and failures are surfaced on the
 * hosting show page plus the provisioning-events index/show pages.
 */
class ProvisioningActionStatusTest extends TestCase
{
    use RefreshDatabase;

    private const VM = 'testvm1';

    private const GUID = '11111111-2222-3333-4444-555555555555';

    private const VHD = 'C:\\VMs\\testvm1.vhdx';

    public function test_module_action_start_failure_records_failed_event(): void
    {
        [$account] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'suspended');
        Http::fake(fn ($r) => Http::response(['error' => 'HOST-START-BOOM-7'], 500));

        $this->actingAsAdminWith(['hosting.edit'])
            ->post(route('admin.hosting.module-action', $account), [
                'module_slug' => 'hyperv',
                'action' => 'start',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $event = ProvisioningEvent::where('hosting_account_id', $account->id)->sole();

        $this->assertSame('failed', $event->status);
        $this->assertSame('failed', $event->event_status);
        $this->assertSame('unsuspend', $event->event_type);
        $this->assertSame($account->id, $event->hosting_account_id);
        $this->assertStringContainsString('HOST-START-BOOM-7', (string) $event->last_error);
        $this->assertNull($event->completed_at);
        $this->assertSame('suspended', $account->fresh()->status);
    }

    public function test_module_action_start_success_records_completed_event(): void
    {
        [$account] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'suspended');
        $hostState = 'Off';
        $this->fakeHost($hostState);

        $this->actingAsAdminWith(['hosting.edit'])
            ->post(route('admin.hosting.module-action', $account), [
                'module_slug' => 'hyperv',
                'action' => 'start',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $event = ProvisioningEvent::where('hosting_account_id', $account->id)->sole();

        $this->assertSame('completed', $event->status);
        $this->assertSame('completed', $event->event_status);
        $this->assertNotNull($event->completed_at);
    }

    public function test_hyperv_create_records_exactly_one_provision_event(): void
    {
        [$account] = $this->hostingWithHyperV(hostingStatus: 'suspended');
        // Async create: sync queue runs the job inline; New-VM + verify probe.
        Http::fake(function ($request) {
            $body = (string) $request->body();
            if (str_contains($body, 'New-VM')) {
                return Http::response(['vmId' => self::GUID, 'name' => 'newvm', 'state' => 'Off']);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => 'newvm', 'state' => 'Off', 'vmId' => self::GUID]);
            }
            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $this->actingAsAdminWith(['hosting.edit'])
            ->post(route('admin.hosting.module-action', $account), [
                'module_slug' => 'hyperv',
                'action' => 'create',
            ])
            ->assertRedirect()
            ->assertSessionHas('info');

        $events = ProvisioningEvent::where('hosting_account_id', $account->id)
            ->where('event_type', 'provision')
            ->get();

        $this->assertCount(1, $events);
        $this->assertSame('completed', $events->sole()->status);
    }

    public function test_hosting_show_renders_failed_provisioning_alert(): void
    {
        $product = Product::create(['name' => 'HV', 'price' => 50]);
        ProductModule::create([
            'product_id' => $product->id, 'module_slug' => 'hyperv', 'enabled' => true, 'config' => [],
        ]);
        $account = HostingAccount::create([
            'customer_id' => $this->makeCustomer()->id,
            'product_id' => $product->id,
            'host_name' => 'hv-alert-01',
        ]);
        ProvisioningEvent::create([
            'service_instance_id' => null,
            'hosting_account_id' => $account->id,
            'event_type' => 'unsuspend',
            'status' => 'failed',
            'event_status' => 'failed',
            'payload' => ['module' => 'hyperv', 'action' => 'start'],
            'result' => ['error' => 'HOST-ALERT-BOOM'],
            'last_error' => 'HOST-ALERT-BOOM',
        ]);

        $this->actingAsAdminWith(['hosting.view'])
            ->get(route('admin.hosting.show', $account))
            ->assertOk()
            ->assertSee('Last unsuspend failed', false)
            ->assertSee('HOST-ALERT-BOOM')
            ->assertSee('View event');
    }

    public function test_provisioning_events_pages_link_hosting_account_and_show_error(): void
    {
        $product = Product::create(['name' => 'HV', 'price' => 50]);
        $account = HostingAccount::create([
            'customer_id' => $this->makeCustomer()->id,
            'product_id' => $product->id,
            'host_name' => 'hv-link-01',
        ]);
        $event = ProvisioningEvent::create([
            'service_instance_id' => null,
            'hosting_account_id' => $account->id,
            'event_type' => 'unsuspend',
            'status' => 'failed',
            'event_status' => 'failed',
            'payload' => ['module' => 'hyperv', 'action' => 'start'],
            'result' => ['error' => 'HOST-EVENT-BOOM'],
            'last_error' => 'HOST-EVENT-BOOM',
        ]);

        $this->actingAsAdminWith(['provisioning-events.view'])
            ->get(route('admin.provisioning-events.index'))
            ->assertOk()
            ->assertSee('HOST-'.$account->id, false)
            ->assertSee(route('admin.hosting.show', $account->id), false);

        $this->actingAsAdminWith(['provisioning-events.view'])
            ->get(route('admin.provisioning-events.show', $event))
            ->assertOk()
            ->assertSee('Error')
            ->assertSee('HOST-EVENT-BOOM');
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

    private function panelAccount(ServiceInstance $service, Server $server): PanelAccount
    {
        return PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'hyperv',
            'username' => self::VM,
            'external_id' => self::GUID,
            'meta' => ['vmName' => self::VM, 'meta' => ['vmName' => self::VM, 'vhdPath' => self::VHD]],
            'status' => PanelAccount::STATUS_ACTIVE,
        ]);
    }

    /**
     * Fake the WinRM /wsman endpoint with a stateful host: Get-VM answers
     * the CURRENT value of $state, power verbs answer their post-action
     * state. Anything else is a loud error so stray calls cannot pass
     * silently.
     */
    private function fakeHost(string &$state): void
    {
        Http::fake(function ($request) use (&$state) {
            $body = (string) $request->body();

            if (str_contains($body, 'Remove-VM -VM')) {
                return Http::response(['deletedVhd' => str_contains($body, 'Remove-Item')]);
            }
            if (str_contains($body, 'Restart-VM -VM')) {
                return Http::response(['state' => 'Running']);
            }
            if (str_contains($body, 'Stop-VM -VM')) {
                return Http::response(['state' => 'Off']);
            }
            if (str_contains($body, 'Start-VM -VM')) {
                return Http::response(['state' => 'Running']);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => self::VM, 'state' => $state, 'vmId' => self::GUID]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });
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
     * @return array{0:HostingAccount}
     */
    private function hostingWithHyperV(bool $withPanelAccount = false, string $hostingStatus = 'suspended'): array
    {
        $server = $this->hypervServer();
        $product = Product::create(['name' => 'HV', 'price' => 50]);
        ProductModule::create([
            'product_id' => $product->id, 'module_slug' => 'hyperv', 'enabled' => true, 'config' => [],
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
            $this->panelAccount($service, $server);
        }

        return [$account->fresh()];
    }
}
