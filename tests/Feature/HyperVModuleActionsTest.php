<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\PanelAccount;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use App\Modules\HyperV\HyperV;
use App\Modules\HyperV\Services\HyperVClient;
use Tests\TestCase;

/**
 * Hyper-V power actions against a faked WinRM host.
 *
 * Every test pins a production-safety rule: state-checked host calls,
 * graceful-only power verbs (no -Force/-TurnOff except Remove-VM itself),
 * refusal paths that never touch the host, and fail-loud provisioning.
 */
class HyperVModuleActionsTest extends TestCase
{
    use RefreshDatabase;

    private const VM = 'testvm1';

    private const GUID = '11111111-2222-3333-4444-555555555555';

    private const VHD = 'C:\\VMs\\testvm1.vhdx';

    protected function setUp(): void
    {
        parent::setUp();
    }

    // ───────────────────────── HyperVClient power verbs ─────────────────────────

    public function test_start_is_idempotent_when_already_running(): void
    {
        $server = $this->hypervServer();
        $hostState = 'Running'; $this->fakeHost($hostState);

        $result = (new HyperVClient($server))->startVm(self::VM);

        $this->assertSame(['state' => 'Running', 'already' => true], $result);
        Http::assertSentCount(1); // state probe only — Start-VM never sent
        $this->assertStringNotContainsString('Start-VM -VM', $this->bodies()[0]);
    }

    public function test_start_powers_on_an_off_vm_without_force(): void
    {
        $server = $this->hypervServer();
        $hostState = 'Off'; $this->fakeHost($hostState);

        $result = (new HyperVClient($server))->startVm(self::VM);

        $this->assertFalse($result['already']);
        $this->assertSame('Running', $result['state']);
        Http::assertSent(fn ($r) => str_contains($r->body(), 'Start-VM -VM'));
        $this->assertNoForceOrTurnOff();
    }

    public function test_stop_is_idempotent_when_already_off(): void
    {
        $server = $this->hypervServer();
        $hostState = 'Off'; $this->fakeHost($hostState);

        $result = (new HyperVClient($server))->stopVm(self::VM);

        $this->assertSame(['state' => 'Off', 'already' => true], $result);
        Http::assertSentCount(1);
    }

    public function test_stop_is_graceful_without_force_or_turnoff(): void
    {
        $server = $this->hypervServer();
        $hostState = 'Running'; $this->fakeHost($hostState);

        $result = (new HyperVClient($server))->stopVm(self::VM);

        $this->assertFalse($result['already']);
        $this->assertSame('Off', $result['state']);
        Http::assertSent(fn ($r) => str_contains($r->body(), 'Stop-VM -VM'));
        $this->assertNoForceOrTurnOff();
    }

    public function test_restart_is_refused_when_not_running_without_touching_host(): void
    {
        $server = $this->hypervServer();
        $hostState = 'Off'; $this->fakeHost($hostState);

        $result = (new HyperVClient($server))->restartVm(self::VM);

        $this->assertStringContainsString('start it instead', $result['error']);
        Http::assertSentCount(1); // probe only
    }

    public function test_restart_reboots_a_running_vm_without_force(): void
    {
        $server = $this->hypervServer();
        $hostState = 'Running'; $this->fakeHost($hostState);

        $result = (new HyperVClient($server))->restartVm(self::VM);

        $this->assertSame('Running', $result['state']);
        Http::assertSent(fn ($r) => str_contains($r->body(), 'Restart-VM -VM'));
        $this->assertNoForceOrTurnOff();
    }

    public function test_remove_refuses_a_running_vm_without_touching_host(): void
    {
        $server = $this->hypervServer();
        $hostState = 'Running'; $this->fakeHost($hostState);

        $result = (new HyperVClient($server))->removeVm(self::VM, self::VHD, true);

        $this->assertStringContainsString('Stop it first', $result['error']);
        Http::assertSentCount(1); // probe only — no Remove-VM
    }

    public function test_remove_off_vm_deletes_the_recorded_vhd(): void
    {
        $server = $this->hypervServer();
        $hostState = 'Off'; $this->fakeHost($hostState);

        $result = (new HyperVClient($server))->removeVm(self::VM, self::VHD, true);

        $this->assertTrue($result['deletedVhd']);
        Http::assertSent(fn ($r) => str_contains($r->body(), 'Remove-VM -VM'));
        Http::assertSent(fn ($r) => str_contains($r->body(), 'Remove-Item'));
    }

    public function test_remove_rejects_an_unvalidated_vhd_path(): void
    {
        $server = $this->hypervServer();
        $hostState = 'Off'; $this->fakeHost($hostState);

        $result = (new HyperVClient($server))->removeVm(self::VM, 'C:\\Windows\\other.vhdx', true);

        $this->assertStringContainsString('safety validation', $result['error']);
        Http::assertSentCount(1); // probe only — no Remove-VM
    }

    public function test_remove_without_vhd_flag_keeps_the_disk(): void
    {
        $server = $this->hypervServer();
        $hostState = 'Off'; $this->fakeHost($hostState);

        $result = (new HyperVClient($server))->removeVm(self::VM, self::VHD, false);

        $this->assertFalse($result['deletedVhd']);
        Http::assertSent(fn ($r) => str_contains($r->body(), 'Remove-VM -VM'));
        foreach ($this->bodies() as $body) {
            $this->assertStringNotContainsString('Remove-Item', $body);
        }
    }

    public function test_soap_protocol_fault_reports_reason_without_trustedhosts_noise(): void
    {
        $server = $this->hypervServer();
        $fault = '<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope">'
            . '<s:Body><s:Fault><s:Code><s:Subcode><s:Value>a:MessageInformationHeaderRequired</s:Value></s:Subcode></s:Code>'
            . '<s:Reason><s:Text>The WS-Management service cannot process the request. The SOAP packet contains a WS-Addressing To element that was invalid.</s:Text></s:Reason>'
            . '</s:Fault></s:Body></s:Envelope>';
        Http::fake(['*' => Http::response($fault, 500)]);

        $result = (new HyperVClient($server))->getVmState(self::VM);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('rejected the WinRM request', $result['error']);
        $this->assertStringContainsString('MessageInformationHeaderRequired', $result['error']);
        $this->assertStringNotContainsString('TrustedHosts', $result['error']);
        $this->assertStringNotContainsString('<s:', $result['error']);
    }

    // ───────────────────────── module lifecycle ─────────────────────────

    public function test_provision_fails_loud_on_host_fault_with_no_record(): void
    {
        $server = $this->hypervServer();
        Http::fake(fn ($r) => str_contains($r->body(), 'New-VM')
            ? Http::response('<Envelope><Body><Fault>WS-Management shell missing</Fault></Body></Envelope>', 500)
            : Http::response(['error' => 'unexpected host call'], 500));

        $service = $this->service($server);
        $result = app(HyperV::class)->provision($service, $this->vmConfig());

        $this->assertFalse($result->success);
        $this->assertSame(0, PanelAccount::count());
    }

    public function test_suspend_stops_and_unsuspend_starts_on_the_host(): void
    {
        $server = $this->hypervServer();
        $service = $this->service($server);
        $this->panelAccount($service, $server);

        $hostState = 'Running'; $this->fakeHost($hostState);
        $this->assertTrue(app(HyperV::class)->suspend($service, [])->success);
        $this->assertSame(PanelAccount::STATUS_SUSPENDED, PanelAccount::sole()->status);
        Http::assertSent(fn ($r) => str_contains($r->body(), 'Stop-VM -VM'));

        $hostState = 'Off'; // same fake observes the new state (Http::fake merges stubs)
        $this->assertTrue(app(HyperV::class)->unsuspend($service, [])->success);
        $this->assertSame(PanelAccount::STATUS_ACTIVE, PanelAccount::sole()->status);
        Http::assertSent(fn ($r) => str_contains($r->body(), 'Start-VM -VM'));
    }

    public function test_terminate_refuses_a_running_vm(): void
    {
        $server = $this->hypervServer();
        $service = $this->service($server);
        $this->panelAccount($service, $server);
        $hostState = 'Running'; $this->fakeHost($hostState);

        $result = app(HyperV::class)->terminate($service, []);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Stop it first', (string) $result->message);
        $this->assertSame(PanelAccount::STATUS_ACTIVE, PanelAccount::sole()->status);
    }

    public function test_terminate_off_vm_deletes_vm_and_vhd(): void
    {
        $server = $this->hypervServer();
        $service = $this->service($server);
        $this->panelAccount($service, $server);
        $hostState = 'Off'; $this->fakeHost($hostState);

        $result = app(HyperV::class)->terminate($service, ['delete_vhd_on_terminate' => true]);

        $this->assertTrue($result->success);
        $this->assertSame(PanelAccount::STATUS_TERMINATED, PanelAccount::sole()->status);
        Http::assertSent(fn ($r) => str_contains($r->body(), 'Remove-VM -VM'));
    }

    public function test_restart_requires_a_running_vm(): void
    {
        $server = $this->hypervServer();
        $service = $this->service($server);
        $this->panelAccount($service, $server);

        $hostState = 'Off'; $this->fakeHost($hostState);
        $this->assertFalse(app(HyperV::class)->restart($service, [])->success);

        $hostState = 'Running'; // same fake observes the new state (Http::fake merges stubs)
        $result = app(HyperV::class)->restart($service, []);
        $this->assertTrue($result->success);
        Http::assertSent(fn ($r) => str_contains($r->body(), 'Restart-VM -VM'));
    }

    public function test_logical_hv_records_are_unmanageable_without_host_calls(): void
    {
        Http::fake();
        $server = $this->hypervServer();
        $service = $this->service($server);
        PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'hyperv',
            'username' => self::VM,
            'external_id' => 'hv-'.self::VM,
            'status' => PanelAccount::STATUS_ACTIVE,
        ]);

        $result = app(HyperV::class)->suspend($service, []);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('logical record', (string) $result->message);
        Http::assertNothingSent();
    }

    // ───────────────────────── admin UI + controller guards ─────────────────────────

    public function test_hosting_show_renders_hyperv_power_buttons_with_typed_confirms(): void
    {
        $product = Product::create(['name' => 'HV', 'price' => 50]);
        ProductModule::create([
            'product_id' => $product->id, 'module_slug' => 'hyperv', 'enabled' => true, 'config' => [],
        ]);
        $account = HostingAccount::create([
            'customer_id' => $this->makeCustomer()->id,
            'product_id' => $product->id,
            'host_name' => 'hv-demo-01',
        ]);

        $this->actingAsAdminWith(['hosting.view'])
            ->get(route('admin.hosting.show', $account))
            ->assertOk()
            ->assertSee('hv-create-', false)
            ->assertSee('hv-start-', false)
            ->assertSee('data-hv-action="stop"', false)
            ->assertSee('hv-restart-', false)
            ->assertSee('hv-delete-', false)
            ->assertSee('>Start<', false)
            ->assertSee('>Stop<', false)
            ->assertSee('>Restart<', false)
            ->assertSee('>Delete<', false)
            ->assertSee('hv-demo-01')
            ->assertSee('name="delete_vhd"', false)
            ->assertDontSee('Unsuspend');
    }

    public function test_module_action_restart_rejects_a_wrong_confirmation(): void
    {
        Http::fake();
        [$account] = $this->hostingWithHyperV();

        $this->actingAsAdminWith(['hosting.edit'])
            ->post(route('admin.hosting.module-action', $account), [
                'module_slug' => 'hyperv',
                'action' => 'restart',
                'confirm' => 'wrong-name',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_module_action_restart_with_confirmation_calls_the_host(): void
    {
        [$account] = $this->hostingWithHyperV(withPanelAccount: true);
        $hostState = 'Running'; $this->fakeHost($hostState);

        $this->actingAsAdminWith(['hosting.edit'])
            ->post(route('admin.hosting.module-action', $account), [
                'module_slug' => 'hyperv',
                'action' => 'restart',
                'confirm' => $account->host_name,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Http::assertSent(fn ($r) => str_contains($r->body(), 'Restart-VM -VM'));
    }

    public function test_provision_names_the_vm_after_the_product_hostname(): void
    {
        $server = $this->hypervServer();
        Http::fake(function ($request) {
            $body = (string) $request->body();
            if (str_contains($body, 'New-VM')) {
                return Http::response(['vmId' => self::GUID, 'name' => 'web-01', 'state' => 'Off']);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => 'web-01', 'state' => 'Off', 'vmId' => self::GUID]);
            }
            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $service = $this->service($server);
        $result = app(HyperV::class)->provision($service, $this->vmConfig() + ['host_name' => 'web-01']);

        $this->assertTrue($result->success);
        Http::assertSent(fn ($r) => str_contains($r->body(), "\$vmName = 'web-01'"));
        $this->assertSame('web-01', PanelAccount::sole()->meta['meta']['vmName']);
    }

    public function test_provision_falls_back_to_hosting_account_hostname(): void
    {
        $server = $this->hypervServer();
        Http::fake(function ($request) {
            $body = (string) $request->body();
            if (str_contains($body, 'New-VM')) {
                return Http::response(['vmId' => self::GUID, 'name' => 'hv-web-01', 'state' => 'Off']);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => 'hv-web-01', 'state' => 'Off', 'vmId' => self::GUID]);
            }
            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $customer = $this->makeCustomer();
        $product = Product::create(['name' => 'HV', 'price' => 50]);
        $account = HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'host_name' => 'hv-web-01',
        ]);
        $service = ServiceInstance::create([
            'customer_id' => $customer->id,
            'server_id' => $server->id,
            'service_tag' => 'HOST-'.$account->id,
            'username' => self::VM,
            'status' => 'pending',
        ]);

        $result = app(HyperV::class)->provision($service, $this->vmConfig());

        $this->assertTrue($result->success);
        Http::assertSent(fn ($r) => str_contains($r->body(), "\$vmName = 'hv-web-01'"));
    }

    public function test_provision_falls_back_to_username_without_any_hostname(): void
    {
        $server = $this->hypervServer();
        Http::fake(function ($request) {
            $body = (string) $request->body();
            if (str_contains($body, 'New-VM')) {
                return Http::response(['vmId' => self::GUID, 'name' => self::VM, 'state' => 'Off']);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => self::VM, 'state' => 'Off', 'vmId' => self::GUID]);
            }
            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $service = $this->service($server);
        $result = app(HyperV::class)->provision($service, $this->vmConfig());

        $this->assertTrue($result->success);
        // No hostname anywhere: the derived panel username names the VM.
        $username = PanelAccount::sole()->username;
        Http::assertSent(fn ($r) => str_contains($r->body(), "\$vmName = '{$username}'"));
    }

    public function test_module_action_delete_refuses_a_running_vm(): void
    {
        [$account] = $this->hostingWithHyperV(withPanelAccount: true);
        $hostState = 'Running'; $this->fakeHost($hostState);

        $this->actingAsAdminWith(['hosting.edit'])
            ->post(route('admin.hosting.module-action', $account), [
                'module_slug' => 'hyperv',
                'action' => 'delete',
                'confirm' => $account->host_name,
                'delete_vhd' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        foreach ($this->bodies() as $body) {
            $this->assertStringNotContainsString('Remove-VM -VM', $body);
        }
    }

    public function test_module_action_start_powers_on_and_reactivates(): void
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

        Http::assertSent(fn ($r) => str_contains($r->body(), 'Start-VM -VM'));
        $this->assertSame('active', $account->fresh()->status);
        $this->assertSame(PanelAccount::STATUS_ACTIVE, PanelAccount::sole()->status);
    }

    public function test_module_action_stop_shuts_down_and_suspends_locally(): void
    {
        [$account] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active');
        $hostState = 'Running';
        $this->fakeHost($hostState);

        $this->actingAsAdminWith(['hosting.edit'])
            ->post(route('admin.hosting.module-action', $account), [
                'module_slug' => 'hyperv',
                'action' => 'stop',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Http::assertSent(fn ($r) => str_contains($r->body(), 'Stop-VM -VM'));
        $this->assertSame('suspended', $account->fresh()->status);
        $this->assertSame(PanelAccount::STATUS_SUSPENDED, PanelAccount::sole()->status);
    }

    public function test_module_action_create_provisions_a_new_vm(): void
    {
        [$account] = $this->hostingWithHyperV(hostingStatus: 'suspended');
        // Async create (sync queue runs inline): New-VM + host-verify Get-VM probe.
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

        $panelAccount = PanelAccount::sole();
        $this->assertSame('hyperv', $panelAccount->panel);
        $this->assertSame(self::GUID, $panelAccount->external_id);
        $this->assertSame('active', $account->fresh()->status);
        // The VM on the host carries the product hostname, not the username.
        Http::assertSent(fn ($r) => str_contains($r->body(), "\$vmName = '{$account->host_name}'"));
    }

    public function test_module_action_delete_off_vm_terminates_and_leaves_to_index(): void
    {
        [$account] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active');
        $hostState = 'Off';
        $this->fakeHost($hostState);

        $this->actingAsAdminWith(['hosting.edit'])
            ->post(route('admin.hosting.module-action', $account), [
                'module_slug' => 'hyperv',
                'action' => 'delete',
                'confirm' => $account->host_name,
                'delete_vhd' => true,
            ])
            ->assertRedirect(route('admin.hosting.index'))
            ->assertSessionHas('success');

        Http::assertSent(fn ($r) => str_contains($r->body(), 'Remove-VM -VM'));
        $this->assertSame(PanelAccount::STATUS_TERMINATED, PanelAccount::sole()->status);
        $this->assertSame('terminated', $account->fresh()->status);
    }

    public function test_module_action_delete_with_unchecked_vhd_orphans_the_disk(): void
    {
        [$account] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active');
        $hostState = 'Off';
        $this->fakeHost($hostState);

        // Unchecked checkbox submits the hidden 0 — the disk must survive.
        $this->actingAsAdminWith(['hosting.edit'])
            ->post(route('admin.hosting.module-action', $account), [
                'module_slug' => 'hyperv',
                'action' => 'delete',
                'confirm' => $account->host_name,
                'delete_vhd' => false,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Http::assertSent(fn ($r) => str_contains($r->body(), 'Remove-VM -VM'));
        foreach ($this->bodies() as $body) {
            $this->assertStringNotContainsString('Remove-Item', $body);
        }
    }

    public function test_module_action_power_is_refused_on_terminated_services(): void
    {
        Http::fake();

        foreach (['start', 'stop', 'restart'] as $action) {
            [$account] = $this->hostingWithHyperV(hostingStatus: 'terminated');

            $this->actingAsAdminWith(['hosting.edit'])
                ->post(route('admin.hosting.module-action', $account), [
                    'module_slug' => 'hyperv',
                    'action' => $action,
                    'confirm' => $account->host_name,
                ])
                ->assertRedirect()
                ->assertSessionHas('error');
        }

        Http::assertNothingSent();
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
            'username' => self::VM,
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

    /** @return array{string,string,string,array} */
    private function vmConfig(): array
    {
        return ['cpu' => 2, 'ram' => 2048, 'disk' => 50, 'switch' => 'Default Switch', 'generation' => 2];
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
                return Http::response(['exists' => true, 'name' => self::VM, 'state' => $state, 'vmId' => self::GUID]);
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

    private function assertNoForceOrTurnOff(): void
    {
        foreach ($this->bodies() as $body) {
            $this->assertStringNotContainsString('-Force', $body);
            $this->assertStringNotContainsString('-TurnOff', $body);
        }
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
                'username' => self::VM,
                'provisioning_method' => 'hyperv',
                'status' => 'pending',
            ]);
            $this->panelAccount($service, $server);
        }

        return [$account->fresh()];
    }
}
