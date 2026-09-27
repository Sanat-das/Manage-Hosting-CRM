<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendEmail;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Provisioning\WelcomeMailer;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Proxmox VE welcome mail must never invent a login.
 *
 * A Proxmox VM's credentials live inside the guest (cloud-init); the panel
 * password is generated bookkeeping. Without guest credentials the mail must
 * deliver the service address, not a username/password pair that opens nothing.
 */
final class ProxmoxCredentialDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(EmailTemplateSeeder::class);
    }

    public function test_without_guest_credentials_the_mail_delivers_no_fake_login(): void
    {
        Queue::fake();

        [$order, $service] = $this->orderAndService();

        $sent = app(WelcomeMailer::class)->send($order, $service, [
            'username' => 'acme123',
            'password' => 'PanelSecret1',
            'ip' => '10.0.0.20',
        ]);

        $this->assertTrue($sent);

        Queue::assertPushed(SendEmail::class, function (SendEmail $job) {
            return ! str_contains($job->body, 'PanelSecret1')
                && ! str_contains($job->body, 'acme123')
                && str_contains($job->body, '10.0.0.20')
                // A Proxmox VM has no cPanel.
                && ! str_contains($job->body, '/cpanel')
                // No password was delivered, so no "change it" instruction.
                && ! str_contains($job->body, 'change this password');
        });
    }

    public function test_cloud_init_guest_credentials_are_delivered_and_redacted_in_the_log(): void
    {
        Queue::fake();

        [$order, $service] = $this->orderAndService();

        $sent = app(WelcomeMailer::class)->send($order, $service, [
            'username' => 'acme123',
            'password' => 'PanelSecret1',
            'guest_username' => 'ubuntu',
            'guest_password' => 's3cret-guest',
            'ip' => '10.0.0.20',
        ]);

        $this->assertTrue($sent);

        Queue::assertPushed(SendEmail::class, function (SendEmail $job) {
            return str_contains($job->body, 'ubuntu')
                && str_contains($job->body, 's3cret-guest')
                && ! str_contains($job->body, 'PanelSecret1')
                && ! str_contains($job->body, '/cpanel')
                && $job->logBody !== null
                && ! str_contains($job->logBody, 's3cret-guest')
                && str_contains($job->logBody, '[redacted]');
        });
    }

    /**
     * The narrow customer-visible bug: a guest username was paired with the
     * generated panel password, which exists nowhere in the guest. The username
     * may be delivered, but never with that password.
     */
    public function test_a_guest_username_is_never_paired_with_the_panel_password(): void
    {
        Queue::fake();

        [$order, $service] = $this->orderAndService();

        $sent = app(WelcomeMailer::class)->send($order, $service, [
            'username' => 'acme123',
            'password' => 'PanelSecret1',
            'guest_username' => 'ubuntu',
            'ip' => '10.0.0.20',
        ]);

        $this->assertTrue($sent);

        Queue::assertPushed(SendEmail::class, function (SendEmail $job) {
            return str_contains($job->body, 'ubuntu')
                && ! str_contains($job->body, 'PanelSecret1')
                && ! str_contains($job->body, 'change this password');
        });
    }

    /**
     * @return array{0: Order, 1: ServiceInstance}
     */
    private function orderAndService(): array
    {
        $server = Server::create([
            'name' => 'pve-mail-'.uniqid(),
            'ip_address' => '10.0.0.20',
            'server_type' => 'proxmox',
            'api_username' => 'root@pam!automation',
            'api_password_encrypted' => 'TOKEN-SECRET',
            'max_accounts' => 0,
            'status' => 'active',
        ]);

        $product = Product::create([
            'name' => 'Proxmox VM '.uniqid(),
            'price' => 50,
            'provisioning_module' => 'proxmox',
        ]);

        $customer = Customer::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-PVE-'.uniqid(),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 50.00,
            'domain_name' => 'vm.test',
            'status' => Order::STATUS_PENDING,
        ]);

        $service = ServiceInstance::create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'server_id' => $server->id,
            'service_tag' => 'SVC-'.$order->order_number,
            'username' => 'acme123',
            'domain' => 'vm.test',
            'provisioning_method' => 'proxmox',
            'status' => 'active',
        ]);

        return [$order->refresh()->load(['customer.user', 'product']), $service->refresh()];
    }
}
