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
use App\Modules\HyperV\HyperV;
use App\Modules\HyperV\Services\HyperVClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Hyper-V VM identity: ID-first (GUID) resolution, host-side rename, and
 * recorded-name drift sync.
 *
 * Mirrors the Http::fake() WinRM patterns from HyperVModuleActionsTest and
 * HyperVTemplateCloneTest.
 */
class HyperVIdentityRenameTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_VM = 'testvm1';

    private const GUID = '11111111-2222-3333-4444-555555555555';

    private const VHD = 'C:\\VMs\\testvm1.vhdx';

    public function test_start_resolves_by_id_when_host_renamed_the_vm(): void
    {
        $server = $this->hypervServer();
        $current = 'renamed-on-host';

        Http::fake(function ($request) use ($current) {
            $body = (string) $request->body();

            if (str_contains($body, 'Start-VM -VM')) {
                return Http::response(['state' => 'Running', 'name' => $current, 'vmId' => self::GUID]);
            }

            // Emulate a host where the GUID resolves but the stale recorded
            // name no longer exists.
            if (str_contains($body, 'Get-VM -Id') && str_contains($body, self::GUID)) {
                return Http::response(['exists' => true, 'name' => $current, 'state' => 'Off', 'vmId' => self::GUID]);
            }

            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => false]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $result = (new HyperVClient($server))->startVm(self::OLD_VM, self::GUID);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('Running', $result['state']);
        $this->assertFalse($result['already']);

        $joined = implode("\n", $this->bodies());
        $this->assertStringContainsString('Get-VM -Id', $joined);
        $this->assertStringContainsString(self::GUID, $joined);
        // The stale name fallback must still be present in the lookup.
        $this->assertStringContainsString('Get-VM -Name', $joined);
    }

    public function test_name_only_lookup_still_works_without_id(): void
    {
        $server = $this->hypervServer();
        $hostState = 'Off';
        $this->fakeHost($hostState);

        $result = (new HyperVClient($server))->startVm(self::OLD_VM);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertFalse($result['already']);
        $this->assertSame('Running', $result['state']);

        $joined = implode("\n", $this->bodies());
        $this->assertStringContainsString('Get-VM -Name', $joined);
        $this->assertStringNotContainsString('Get-VM -Id', $joined);
    }

    public function test_module_rename_renames_on_host_and_updates_meta(): void
    {
        $server = $this->hypervServer();
        $service = $this->service($server);
        $account = $this->panelAccount($service, $server);
        $newName = 'web-renamed-01';

        Http::fake(function ($request) use ($newName) {
            $body = (string) $request->body();

            if (str_contains($body, 'Rename-VM')) {
                return Http::response(['name' => $newName, 'vmId' => self::GUID, 'renamed' => true]);
            }

            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => self::OLD_VM, 'state' => 'Off', 'vmId' => self::GUID]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $result = app(HyperV::class)->rename($service, $newName);

        $this->assertTrue($result->success);
        $this->assertStringContainsString($newName, (string) $result->message);

        $joined = implode("\n", $this->bodies());
        $this->assertStringContainsString('Rename-VM', $joined);
        $this->assertStringContainsString('Get-VM -Id', $joined);

        $meta = $account->fresh()->meta;
        $this->assertSame($newName, $meta['vmName'] ?? null);
        $this->assertSame($newName, $meta['meta']['vmName'] ?? null);
        // The GUID linkage is untouched.
        $this->assertSame(self::GUID, $account->fresh()->external_id);
    }

    public function test_admin_hosting_update_renames_vm_and_records_event(): void
    {
        [$hosting] = $this->hostingWithHyperV(withPanelAccount: true);
        $newName = 'hv-renamed-'.str()->lower(str()->random(5));

        Http::fake(function ($request) use ($newName) {
            $body = (string) $request->body();

            if (str_contains($body, 'Rename-VM')) {
                return Http::response(['name' => $newName, 'vmId' => self::GUID, 'renamed' => true]);
            }

            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => self::OLD_VM, 'state' => 'Off', 'vmId' => self::GUID]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $this->actingAsAdminWith(['hosting.edit'])
            ->put(route('admin.hosting.update', $hosting), [
                'customer_id' => $hosting->customer_id,
                'product_id' => $hosting->product_id,
                'host_name' => $newName,
            ])
            ->assertRedirect(route('admin.hosting.show', $hosting))
            ->assertSessionHas('success');

        $joined = implode("\n", $this->bodies());
        $this->assertStringContainsString('Rename-VM', $joined);

        $event = ProvisioningEvent::where('hosting_account_id', $hosting->id)
            ->where('event_type', 'update')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('completed', $event->status);
        $this->assertSame('rename', $event->payload['action'] ?? null);
        $this->assertSame('hyperv', $event->payload['module'] ?? null);
        $this->assertSame($newName, $event->payload['new_name'] ?? null);
    }

    public function test_admin_hosting_update_rename_failure_keeps_name_and_flashes_error(): void
    {
        [$hosting] = $this->hostingWithHyperV(withPanelAccount: true);
        $newName = 'hv-renamed-'.str()->lower(str()->random(5));

        Http::fake(function ($request) {
            $body = (string) $request->body();

            if (str_contains($body, 'Rename-VM')) {
                return Http::response(['error' => 'Rename-VM : simulated host failure.']);
            }

            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => self::OLD_VM, 'state' => 'Off', 'vmId' => self::GUID]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $this->actingAsAdminWith(['hosting.edit'])
            ->put(route('admin.hosting.update', $hosting), [
                'customer_id' => $hosting->customer_id,
                'product_id' => $hosting->product_id,
                'host_name' => $newName,
            ])
            ->assertRedirect(route('admin.hosting.show', $hosting))
            ->assertSessionHas('success')
            ->assertSessionHas('error');

        // The account keeps the new host_name (ID linkage makes that safe).
        $this->assertSame($newName, $hosting->fresh()->host_name);

        $event = ProvisioningEvent::where('hosting_account_id', $hosting->id)
            ->where('event_type', 'update')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('failed', $event->status);
        $this->assertSame('rename', $event->payload['action'] ?? null);
        $this->assertNotEmpty($event->last_error);
    }

    public function test_id_lookup_drift_syncs_recorded_vm_name(): void
    {
        $server = $this->hypervServer();
        $service = $this->service($server);
        $account = $this->panelAccount($service, $server);
        $drifted = 'drifted-on-host';

        Http::fake(function ($request) use ($drifted) {
            $body = (string) $request->body();

            if (str_contains($body, 'Start-VM -VM')) {
                return Http::response(['state' => 'Running', 'name' => $drifted, 'vmId' => self::GUID]);
            }

            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => $drifted, 'state' => 'Off', 'vmId' => self::GUID]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $result = app(HyperV::class)->unsuspend($service, []);

        $this->assertTrue($result->success);

        $meta = $account->fresh()->meta;
        $this->assertSame($drifted, $meta['vmName'] ?? null);
        $this->assertSame($drifted, $meta['meta']['vmName'] ?? null);
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

    private function service(Server $server): ServiceInstance
    {
        return ServiceInstance::create([
            'customer_id' => $this->makeCustomer()->id,
            'server_id' => $server->id,
            'service_tag' => 'SVC-'.str()->random(8),
            'username' => self::OLD_VM,
            'status' => 'active',
        ]);
    }

    private function panelAccount(ServiceInstance $service, Server $server): PanelAccount
    {
        return PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'hyperv',
            'username' => self::OLD_VM,
            'external_id' => self::GUID,
            'meta' => ['vmName' => self::OLD_VM, 'meta' => ['vmName' => self::OLD_VM, 'vhdPath' => self::VHD]],
            'status' => PanelAccount::STATUS_ACTIVE,
        ]);
    }

    /**
     * Fake the WinRM /wsman endpoint with a stateful host: Get-VM answers
     * the CURRENT value of $state, power verbs answer their post-action
     * state. Anything else is a loud error so stray calls cannot pass
     * silently.
     *
     * NOTE: Http::fake() MERGES stub callbacks (first match wins), so
     * calling this twice does NOT replace the fake. Pass a variable and
     * mutate it mid-test instead — the closure binds by reference.
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
                return Http::response(['exists' => true, 'name' => self::OLD_VM, 'state' => $state, 'vmId' => self::GUID]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });
    }

    /** @return list<string> */
    private function bodies(): array
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => (string) $pair[0]->body())
            ->all();
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
            // Mirror the ServiceInstance serviceForHosting() will resolve so
            // the module finds a recorded VM.
            $service = ServiceInstance::create([
                'customer_id' => $customer->id,
                'order_id' => null,
                'server_id' => $server->id,
                'domain' => 'vm.test',
                'service_tag' => 'HOST-'.$account->id,
                'username' => self::OLD_VM,
                'provisioning_method' => 'hyperv',
                'status' => 'pending',
            ]);
            $this->panelAccount($service, $server);
        }

        return [$account->fresh()];
    }
}
