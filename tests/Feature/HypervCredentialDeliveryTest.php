<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendEmail;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PanelAccount;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\ServerGroupMember;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Provisioning\WelcomeMailer;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Hyper-V welcome mail must deliver the VM's real Administrator credentials
 * instead of the meaningless panel-generated password.
 *
 * Guest credentials (guest_username / guest_password) are what the customer
 * actually logs in with on a Windows VM; the panel password is a generated
 * secret nobody uses there. When rotation is enabled the email must contain
 * the ROTATED password, never the supplied CurrentSecret123.
 */
class HypervCredentialDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const VM_GUID = '22222222-2222-3333-4444-555555555555';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(EmailTemplateSeeder::class);
    }

    public function test_rotated_guest_password_is_emailed_not_the_supplied_one(): void
    {
        Queue::fake();

        $state = 'Off';
        Http::fake(function ($request) use (&$state) {
            $body = (string) $request->body();

            if (str_contains($body, 'New-VM')) {
                return Http::response(['vmId' => self::VM_GUID, 'name' => 'hv-rotated', 'state' => 'Off']);
            }
            if (str_contains($body, 'Start-VM -VM')) {
                $state = 'Running';
                return Http::response(['state' => 'Running', 'name' => 'hv-rotated', 'vmId' => self::VM_GUID]);
            }
            if (str_contains($body, 'Invoke-Command -VMName')) {
                // Rotation (Set-LocalUser) vs probe (COMPUTERNAME)
                if (str_contains($body, 'Set-LocalUser')) {
                    return Http::response(['ok' => true, 'vmName' => 'hv-rotated']);
                }
                return Http::response(['verified' => true, 'guest' => 'HV-ROTATED']);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => 'hv-rotated', 'state' => $state, 'vmId' => self::VM_GUID]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $order = $this->makePaidHypervOrder([
            'guest_username' => 'Administrator',
            'guest_password' => 'CurrentSecret123',
            'apply_password' => true,
            'start_after_create' => true,
            'credential_probe_attempts' => 1,
            'credential_probe_delay_seconds' => 0,
        ]);

        $result = app(OrderService::class)->advanceAfterPayment($order);
        $this->assertSame(Order::STATUS_ACTIVE, $result->fresh()->status);

        $panel = PanelAccount::sole();
        $rotated = $panel->guest_password_encrypted;
        $this->assertNotSame('CurrentSecret123', $rotated, 'Stored guest password should be the rotated one');
        $this->assertStringEndsWith('!aA9', $rotated);

        Queue::assertPushed(SendEmail::class, function (SendEmail $job) use ($rotated) {
            // WHY: guest credentials are the ones the customer logs in with on a Windows VM.
            $hasRotated = str_contains($job->body, $rotated);
            $hasAdmin = str_contains($job->body, 'Administrator');
            $noSupplied = ! str_contains($job->body, 'CurrentSecret123');
            $logRedacted = $job->logBody !== null
                && ! str_contains($job->logBody, $rotated)
                && str_contains($job->logBody, '[redacted]');
            $noCpanel = ! str_contains($job->body, '/cpanel');

            return $hasRotated && $hasAdmin && $noSupplied && $logRedacted && $noCpanel;
        });
    }

    public function test_supplied_guest_password_is_emailed_when_rotation_off(): void
    {
        Queue::fake();

        $state = 'Off';
        Http::fake(function ($request) use (&$state) {
            $body = (string) $request->body();

            if (str_contains($body, 'New-VM')) {
                return Http::response(['vmId' => self::VM_GUID, 'name' => 'hv-supplied', 'state' => 'Off']);
            }
            if (str_contains($body, 'Start-VM -VM')) {
                $state = 'Running';
                return Http::response(['state' => 'Running', 'name' => 'hv-supplied', 'vmId' => self::VM_GUID]);
            }
            if (str_contains($body, 'Invoke-Command -VMName')) {
                return Http::response(['verified' => true, 'guest' => 'HV-SUPPLIED']);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => 'hv-supplied', 'state' => $state, 'vmId' => self::VM_GUID]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $order = $this->makePaidHypervOrder([
            'guest_username' => 'Administrator',
            'guest_password' => 'CurrentSecret123',
            'start_after_create' => true,
            'credential_probe_attempts' => 1,
            'credential_probe_delay_seconds' => 0,
        ]);

        $result = app(OrderService::class)->advanceAfterPayment($order);
        $this->assertSame(Order::STATUS_ACTIVE, $result->fresh()->status);

        $panel = PanelAccount::sole();
        $this->assertSame('CurrentSecret123', $panel->guest_password_encrypted);

        Queue::assertPushed(SendEmail::class, function (SendEmail $job) {
            // Without rotation the original guest password must be delivered verbatim,
            // still redacted in the log body.
            return str_contains($job->body, 'CurrentSecret123')
                && str_contains($job->body, 'Administrator')
                && $job->logBody !== null
                && ! str_contains($job->logBody, 'CurrentSecret123')
                && str_contains($job->logBody, '[redacted]');
        });
    }

    public function test_non_hyperv_fallback_still_emails_panel_credentials(): void
    {
        Queue::fake();

        // Direct WelcomeMailer call without guest credentials — proves fallback unchanged.
        [$order, $service] = $this->makeCpanelOrderAndService();

        $sent = app(WelcomeMailer::class)->send($order, $service, [
            'username' => 'paneluser',
            'password' => 'PanelSecret1',
        ]);

        $this->assertTrue($sent);

        Queue::assertPushed(SendEmail::class, function (SendEmail $job) {
            $hasPanel = str_contains($job->body, 'PanelSecret1')
                && str_contains($job->body, 'paneluser');
            // cPanel-style service must still render the control-panel URL (no regression).
            $hasCpanel = str_contains($job->body, '/cpanel');
            $logRedacted = $job->logBody !== null
                && ! str_contains($job->logBody, 'PanelSecret1')
                && str_contains($job->logBody, '[redacted]');

            return $hasPanel && $hasCpanel && $logRedacted;
        });
    }

    public function test_hyperv_mail_has_no_cpanel_link(): void
    {
        Queue::fake();

        $state = 'Off';
        Http::fake(function ($request) use (&$state) {
            $body = (string) $request->body();

            if (str_contains($body, 'New-VM')) {
                return Http::response(['vmId' => self::VM_GUID, 'name' => 'hv-nocp', 'state' => 'Off']);
            }
            if (str_contains($body, 'Start-VM -VM')) {
                $state = 'Running';
                return Http::response(['state' => 'Running', 'name' => 'hv-nocp', 'vmId' => self::VM_GUID]);
            }
            if (str_contains($body, 'Invoke-Command -VMName')) {
                if (str_contains($body, 'Set-LocalUser')) {
                    return Http::response(['ok' => true, 'vmName' => 'hv-nocp']);
                }
                return Http::response(['verified' => true, 'guest' => 'HV-NOCP']);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => 'hv-nocp', 'state' => $state, 'vmId' => self::VM_GUID]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $order = $this->makePaidHypervOrder([
            'guest_username' => 'Administrator',
            'guest_password' => 'CurrentSecret123',
            'apply_password' => true,
            'start_after_create' => true,
            'credential_probe_attempts' => 1,
            'credential_probe_delay_seconds' => 0,
        ]);

        app(OrderService::class)->advanceAfterPayment($order);

        Queue::assertPushed(SendEmail::class, fn (SendEmail $job) => ! str_contains($job->body, '/cpanel')
            && ! str_contains($job->logBody ?? '', '/cpanel'));
    }

    // ───────────────── helpers ─────────────────

    /**
     * Hyper-V order that will auto-provision via OrderService::advanceAfterPayment.
     *
     * @param  array<string, mixed>  $extraConfig
     */
    private function makePaidHypervOrder(array $extraConfig = []): Order
    {
        $server = Server::create([
            'name' => 'hv-'.uniqid(),
            'ip_address' => '10.0.0.100',
            'server_type' => 'hyperv',
            'api_url' => '10.0.0.100',
            'api_username' => 'admin',
            'api_password_encrypted' => 'secret123',
            'status' => 'active',
            'max_accounts' => 0,
        ]);

        $group = ServerGroup::create(['name' => 'HyperV Group '.uniqid(), 'status' => 'active']);
        ServerGroupMember::create([
            'server_group_id' => $group->id,
            'server_id' => $server->id,
            'priority' => 1,
        ]);

        $product = Product::create([
            'name' => 'HyperV VM '.uniqid(),
            'price' => 150,
            'provisioning_module' => 'hyperv',
            'server_group_id' => $group->id,
        ]);

        $linkConfig = array_merge([
            'cpu' => 2,
            'ram' => 2048,
            'disk' => 50,
            'switch' => 'Default Switch',
            'generation' => 2,
        ], $extraConfig);

        ProductModule::create([
            'product_id' => $product->id,
            'module_slug' => 'hyperv',
            'enabled' => true,
            'provisioning_mode' => 'auto',
            'config' => $linkConfig,
        ]);

        $customer = Customer::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 150.00,
            'domain_name' => 'vmtest.example',
            'status' => Order::STATUS_PENDING,
        ]);

        return app(OrderService::class)->markPaid($order);
    }

    /**
     * @return array{0:Order,1:ServiceInstance}
     */
    private function makeCpanelOrderAndService(): array
    {
        $server = Server::create([
            'name' => 'whm-'.uniqid(),
            'ip_address' => '10.0.0.9',
            'server_type' => 'cpanel',
            'api_url' => 'https://whm.example.net:2087',
            'api_username' => 'root',
            'api_key' => 'TOKEN123',
            'max_accounts' => 0,
            'status' => 'active',
        ]);

        $group = ServerGroup::create(['name' => 'cPanel Group '.uniqid(), 'status' => 'active']);
        ServerGroupMember::create([
            'server_group_id' => $group->id,
            'server_id' => $server->id,
            'priority' => 1,
        ]);

        $product = Product::create([
            'name' => 'cPanel Starter '.uniqid(),
            'price' => 100,
            'provisioning_module' => 'cpanel',
            'server_group_id' => $group->id,
        ]);

        ProductModule::create([
            'product_id' => $product->id,
            'module_slug' => 'cpanel',
            'enabled' => true,
            'config' => ['plan' => 'starter'],
        ]);

        $customer = Customer::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-CP-'.uniqid(),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 100.00,
            'domain_name' => 'acme.test',
            'status' => Order::STATUS_PENDING,
        ]);
        $order = app(OrderService::class)->markPaid($order);

        // Service without provisioning — caller will send welcome mail directly.
        $service = ServiceInstance::create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'server_id' => $server->id,
            'service_tag' => 'SVC-'.$order->order_number,
            'username' => 'paneluser',
            'domain' => 'acme.test',
            'provisioning_method' => 'cpanel',
            'status' => 'active',
        ]);

        return [$order->refresh()->load(['customer.user', 'product']), $service->refresh()];
    }
}
