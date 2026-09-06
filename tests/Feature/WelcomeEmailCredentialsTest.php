<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendEmail;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\PanelAccount;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\ServerGroupMember;
use App\Models\User;
use App\Services\Modules\ModuleManager;
use App\Services\OrderService;
use App\Settings\HostingSettings;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * `products.welcome_email_template_id` is finally read: a provisioned service
 * must email the customer the credentials the module generated.
 *
 * The security property under test is that the password reaches the customer
 * but never the `emails` audit table - SendEmail persists its body, so the job
 * is given a separately redacted `logBody`.
 */
class WelcomeEmailCredentialsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EmailTemplateSeeder::class);

        $manager = app(ModuleManager::class);
        $manager->reconcile();
        $manager->activate($manager->find('cpanel'));
    }

    /**
     * Faked per-test rather than in setUp: Http::fake() merges stubs and the
     * first registered match wins, so a setUp success stub would shadow the
     * failure stub a later test registers.
     */
    private function fakeWhmSuccess(): void
    {
        Http::fake(['*/json-api/createacct*' => Http::response([
            'metadata' => ['result' => 1, 'reason' => 'Account Created'],
            'data' => ['ip' => '10.0.0.9', 'nameserver' => 'ns1.example.net'],
        ])]);
    }

    public function test_provisioned_order_emails_the_generated_credentials(): void
    {
        Queue::fake();
        $this->fakeWhmSuccess();

        $order = $this->makePaidOrder();
        app(OrderService::class)->advanceAfterPayment($order);

        $account = PanelAccount::sole();

        Queue::assertPushed(SendEmail::class, function (SendEmail $job) use ($order, $account) {
            return $job->toEmail === $order->customer->user->email
                && str_contains($job->subject, 'Active')
                && str_contains($job->body, $account->password_encrypted)
                && str_contains($job->body, $account->username)
                && str_contains($job->body, '10.0.0.9')
                && str_contains($job->body, 'ns1.example.net');
        });
    }

    public function test_the_sent_body_carries_the_password_but_the_logged_body_does_not(): void
    {
        Queue::fake();
        $this->fakeWhmSuccess();

        app(OrderService::class)->advanceAfterPayment($this->makePaidOrder());

        $password = PanelAccount::sole()->password_encrypted;
        $this->assertNotEmpty($password);

        Queue::assertPushed(SendEmail::class, function (SendEmail $job) use ($password) {
            // What the customer receives.
            return str_contains($job->body, $password)
                // What SendEmail will persist to the `emails` table instead.
                && $job->logBody !== null
                && ! str_contains($job->logBody, $password)
                && str_contains($job->logBody, '[redacted]');
        });
    }

    public function test_the_emails_table_row_is_redacted_end_to_end(): void
    {
        Mail::fake();
        $this->fakeWhmSuccess();

        // No Queue::fake here - the sync queue runs SendEmail inline, so this
        // asserts the row the job actually writes rather than its inputs.
        app(OrderService::class)->advanceAfterPayment($this->makePaidOrder());

        $password = PanelAccount::sole()->password_encrypted;

        $log = EmailLog::query()->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString($password, (string) $log->body);
        $this->assertStringContainsString('[redacted]', (string) $log->body);
    }

    public function test_the_products_own_template_wins_over_the_fallback(): void
    {
        Queue::fake();
        $this->fakeWhmSuccess();

        $custom = EmailTemplate::create([
            'name' => 'custom_welcome',
            'subject' => 'Custom welcome for {{product_name}}',
            'body' => "Hi {{name}},\n\nUser {{service_username}} / pass {{service_password}}\n",
            'status' => 'active',
        ]);

        $order = $this->makePaidOrder();
        $order->product->update(['welcome_email_template_id' => $custom->id]);

        app(OrderService::class)->advanceAfterPayment($order);

        Queue::assertPushed(SendEmail::class, fn (SendEmail $job) => str_starts_with($job->subject, 'Custom welcome for')
            && str_contains($job->body, 'User '.PanelAccount::sole()->username));
    }

    public function test_an_inactive_product_template_falls_back_rather_than_sending_nothing(): void
    {
        Queue::fake();
        $this->fakeWhmSuccess();

        $inactive = EmailTemplate::create([
            'name' => 'retired_welcome',
            'subject' => 'Never sent',
            'body' => 'x',
            'status' => 'inactive',
        ]);

        $order = $this->makePaidOrder();
        $order->product->update(['welcome_email_template_id' => $inactive->id]);

        app(OrderService::class)->advanceAfterPayment($order);

        Queue::assertPushed(SendEmail::class, fn (SendEmail $job) => $job->subject !== 'Never sent'
            && str_contains($job->body, PanelAccount::sole()->password_encrypted));
    }

    public function test_a_template_without_the_placeholder_still_gets_the_credentials_appended(): void
    {
        Queue::fake();
        $this->fakeWhmSuccess();

        // Mirrors an install still carrying the pre-credentials template body:
        // the seeder does not retro-fit existing rows, so the block has to be
        // appended or the password would be silently dropped.
        EmailTemplate::where('name', 'service_activated')
            ->update(['body' => "Hi {{name}},\n\nYour service {{product_name}} is active.\n"]);

        app(OrderService::class)->advanceAfterPayment($this->makePaidOrder());

        Queue::assertPushed(SendEmail::class, fn (SendEmail $job) => str_contains($job->body, 'Your login details:')
            && str_contains($job->body, PanelAccount::sole()->password_encrypted));
    }

    public function test_the_hosting_welcome_email_setting_disables_it(): void
    {
        Queue::fake();
        $this->fakeWhmSuccess();

        app(HostingSettings::class)->fill(['hosting_welcome_email_enabled' => false])->save();

        app(OrderService::class)->advanceAfterPayment($this->makePaidOrder());

        Queue::assertNotPushed(SendEmail::class);
    }

    public function test_no_email_when_provisioning_failed(): void
    {
        Queue::fake();
        Http::fake(['*/json-api/createacct*' => Http::response([
            'metadata' => ['result' => 0, 'reason' => 'Username already exists'],
        ])]);

        $order = $this->makePaidOrder();
        $result = app(OrderService::class)->advanceAfterPayment($order);

        $this->assertSame(Order::STATUS_FAILED, $result->fresh()->status);
        Queue::assertNotPushed(SendEmail::class);
    }

    public function test_a_missing_template_does_not_break_activation(): void
    {
        Queue::fake();
        $this->fakeWhmSuccess();
        EmailTemplate::query()->delete();

        $result = app(OrderService::class)->advanceAfterPayment($this->makePaidOrder());

        $this->assertSame(Order::STATUS_ACTIVE, $result->fresh()->status);
        Queue::assertNotPushed(SendEmail::class);
    }

    // --- helpers ---

    private function makePaidOrder(): Order
    {
        $server = Server::create([
            'name' => 'whm-1',
            'ip_address' => '10.0.0.1',
            'panel_type' => 'cpanel',
            'api_url' => 'https://whm.example.net:2087',
            'api_username' => 'root',
            'api_key' => 'TOKEN123',
            'max_accounts' => 0,
            'status' => 'active',
        ]);

        $group = ServerGroup::create(['name' => 'cPanel Shared', 'status' => 'active']);
        ServerGroupMember::create([
            'server_group_id' => $group->id,
            'server_id' => $server->id,
            'priority' => 1,
        ]);

        $product = Product::create([
            'name' => 'cPanel Starter',
            'price' => 100,
            'provisioning_module' => 'cpanel',
            'server_group_id' => $group->id,
        ]);

        ProductModule::create([
            'product_id' => $product->id,
            'module_id' => app(ModuleManager::class)->find('cpanel')->id,
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
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 100.00,
            'domain_name' => 'acme.test',
            'status' => Order::STATUS_PENDING,
        ]);

        return app(OrderService::class)->markPaid($order);
    }
}
