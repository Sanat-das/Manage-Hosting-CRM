<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\PanelAccount;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\ProvisioningEvent;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Provisioning\VmStatusPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * VmStatusPresenter terminated guard + notice contract.
 *
 * The server (ManualProvisioner) never allows provisioning a terminated
 * account, so the presenter must deny create there too — in every VM
 * sub-case — and the additive `notice` key must answer "what is the state
 * of this service, and why can't I act" without the view re-deriving
 * precedence.
 */
final class VmStatusPresenterNoticeTest extends TestCase
{
    use RefreshDatabase;

    private const VM = 'testvm1';

    private const GUID = '11111111-2222-3333-4444-555555555555';

    public function test_terminated_account_denies_create_when_no_vm_exists(): void
    {
        [$account] = $this->hostingWithHyperV(hostingStatus: 'terminated');
        Http::fake();

        $status = app(VmStatusPresenter::class)->build($account->fresh(), true);

        $this->assertFalse($status['vm']['exists']);
        foreach (['create', 'start', 'stop', 'restart', 'delete', 'reset_password'] as $verb) {
            $this->assertFalse($status['can'][$verb]);
        }
        $this->assertNotEmpty($status['reasons']['create']);
        $this->assertSame('danger', $status['notice']['severity']);
        $this->assertNotEmpty($status['notice']['text']);
    }

    public function test_terminated_account_denies_create_when_vm_is_running(): void
    {
        [$account] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'terminated');
        $state = 'Running';
        $this->fakeHost($state);

        $status = app(VmStatusPresenter::class)->build($account->fresh(), true);

        $this->assertTrue($status['vm']['exists']);
        $this->assertFalse($status['can']['create']);
        $this->assertNotEmpty($status['reasons']['create']);
        $this->assertSame('danger', $status['notice']['severity']);
        $this->assertNotEmpty($status['notice']['text']);
    }

    public function test_terminated_account_denies_create_but_keeps_delete_cleanup_when_vm_is_off(): void
    {
        [$account] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'terminated');
        $state = 'Off';
        $this->fakeHost($state);

        $status = app(VmStatusPresenter::class)->build($account->fresh(), true);

        $this->assertFalse($status['can']['create']);
        $this->assertNotEmpty($status['reasons']['create']);
        $this->assertTrue($status['can']['delete']);
        $this->assertSame('danger', $status['notice']['severity']);
        $this->assertNotEmpty($status['notice']['text']);
    }

    public function test_terminated_account_denies_create_when_vm_state_is_unknown(): void
    {
        [$account] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'terminated');
        Http::fake(function ($request) {
            $body = (string) $request->body();
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => self::VM, 'vmId' => self::GUID]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $status = app(VmStatusPresenter::class)->build($account->fresh(), true);

        $this->assertTrue($status['vm']['exists']);
        $this->assertFalse($status['can']['create']);
        $this->assertNotEmpty($status['reasons']['create']);
        $this->assertFalse($status['can']['delete']);
        $this->assertNotEmpty($status['reasons']['delete']);
        $this->assertSame('danger', $status['notice']['severity']);
        $this->assertNotEmpty($status['notice']['text']);
    }

    public function test_running_healthy_vm_has_no_notice(): void
    {
        [$account] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active');
        $state = 'Running';
        $this->fakeHost($state);

        $status = app(VmStatusPresenter::class)->build($account->fresh(), true);

        $this->assertTrue($status['vm']['exists']);
        $this->assertNull($status['notice']['text']);
        $this->assertNull($status['notice']['severity']);
    }

    public function test_non_terminated_account_without_vm_still_allows_create(): void
    {
        [$account] = $this->hostingWithHyperV(hostingStatus: 'suspended');
        Http::fake();

        $status = app(VmStatusPresenter::class)->build($account->fresh(), true);

        $this->assertFalse($status['vm']['exists']);
        $this->assertTrue($status['can']['create']);
        $this->assertSame('info', $status['notice']['severity']);
        $this->assertNotEmpty($status['notice']['text']);
    }

    public function test_running_action_shows_a_warning_notice(): void
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

        $status = app(VmStatusPresenter::class)->build($account->fresh(), true);

        $this->assertSame('warning', $status['notice']['severity']);
        $this->assertSame('An action is already running.', $status['notice']['text']);
    }

    public function test_probe_failure_shows_a_danger_notice(): void
    {
        [$account] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active');
        Http::fake(function ($request) {
            $body = (string) $request->body();
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['error' => 'host unreachable'], 500);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $status = app(VmStatusPresenter::class)->build($account->fresh(), true);

        $this->assertNull($status['vm']['exists']);
        $this->assertSame('danger', $status['notice']['severity']);
        $this->assertStringStartsWith('Could not verify the VM on the host — ', (string) $status['notice']['text']);
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
            'meta' => ['vmName' => self::VM, 'meta' => ['vmName' => self::VM]],
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
            if (str_contains($body, 'Invoke-Command -VMName')) {
                return Http::response(['ok' => true, 'vmName' => self::VM]);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => self::VM, 'state' => $state, 'vmId' => self::GUID]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });
    }

    /**
     * @return array{0:HostingAccount,1?:ServiceInstance}
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

            return [$account->fresh(), $service];
        }

        return [$account->fresh()];
    }
}
