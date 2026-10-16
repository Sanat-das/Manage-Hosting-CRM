<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\Server;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Services\Payments\Drivers\BankTransferDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * Regression guard for the wiring password-field.js relies on.
 *
 * Generate writes the value into the group's [data-password-input] and, when the
 * group carries data-password-confirm="X", mirrors it into the element with
 * id="X". When that confirmation element has no matching id, Generate writes
 * only the field that was clicked, so a SET/CONFIRM pair submits mismatched — or,
 * with an empty confirmation, cannot submit at all. That defect shipped on the
 * Fortify auth views and fails silently; a paired id that lands on a container
 * rather than the input writes the secret into nothing.
 *
 * Each covered page is rendered and three invariants are asserted:
 *   (a) every data-password-confirm="X" resolves to an element with id="X";
 *   (b) that element is an <input>, not a container;
 *   (c) every type="password" input sits inside a [data-password-field] group
 *       and itself carries data-password-input.
 */
final class PasswordFieldWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_views_and_admin_forms_are_wired(): void
    {
        // @error directives read a shared $errors bag; pages rendered directly
        // (the unrouted vendor auth views and the servers.create schema form)
        // need it shared, exactly as the request lifecycle would.
        view()->share('errors', new ViewErrorBag);

        $admin = $this->adminUser();
        $client = $this->clientUser();

        $department = TicketDepartment::create([
            'name' => 'IT Support',
            'slug' => 'it-support',
            'enabled' => true,
            'sort_order' => 1,
        ]);

        $server = Server::create([
            'name' => 'hv-wiring-1',
            'ip_address' => '10.0.0.9',
            'server_type' => 'hyperv',
            'api_url' => 'http://10.0.0.9:5985',
            'api_username' => 'admin',
            'api_password_encrypted' => 'SECRET',
            'max_accounts' => 0,
            'status' => 'active',
            'connection_meta' => ['host' => '10.0.0.9', 'port' => 5985, 'use_ssl' => false, 'verify_tls' => true],
        ]);

        $gateway = PaymentGateway::create([
            'code' => 'bank_transfer',
            'name' => 'Bank Transfer',
            'driver' => BankTransferDriver::class,
            'mode' => 'test',
            'enabled' => true,
            'credentials' => [],
        ]);

        // Public auth pages must be fetched as a guest: Fortify redirects an
        // already-authenticated user away from register and reset.
        $registerHtml = $this->get(route('register'))->assertOk()->getContent();
        $resetHtml = $this->get(route('password.reset', 'pw-wiring-regression'))->assertOk()->getContent();

        // Log in before the admin renders so the adminlte layout (navbar,
        // permission-gated menu) has an authenticated user.
        $this->actingAs($admin);

        $pages = [
            // Public auth pages — defect #1 lived on the vendor adminlte views.
            'register (app view)' => $registerHtml,
            'password.reset (adminlte)' => $resetHtml,
            // Vendor adminlte auth views are not routed (Fortify binds the app's
            // own auth.register), so render them directly to cover the fixed ids.
            'adminlte::auth.register' => view('adminlte::auth.register')->render(),
            'adminlte::auth.register-v2' => view('adminlte::auth.register-v2')->render(),

            // Admin create/edit forms (authenticated).
            'admin.users.create' => $this->actingAs($admin)->get(route('admin.users.create'))->assertOk()->getContent(),
            'admin.customers.create' => $this->actingAs($admin)->get(route('admin.customers.create'))->assertOk()->getContent(),
            'admin.ticket-departments.create' => $this->actingAs($admin)->get(route('admin.ticket-departments.create'))->assertOk()->getContent(),
            'admin.ticket-departments.edit' => $this->actingAs($admin)->get(route('admin.ticket-departments.edit', $department))->assertOk()->getContent(),
            'admin.settings (integrations)' => $this->actingAs($admin)->get(route('admin.settings.index', ['tab' => 'integrations']))->assertOk()->getContent(),
            'admin.orders.create' => $this->actingAs($admin)->get(route('admin.orders.create'))->assertOk()->getContent(),
            'admin.servers.edit' => $this->actingAs($admin)->get(route('admin.servers.edit', $server))->assertOk()->getContent(),
            'admin.gateway-settings.edit' => $this->actingAs($admin)->get(route('admin.gateway-settings.edit', $gateway))->assertOk()->getContent(),

            // servers.create schema form: rendered directly with a type=password
            // field so the password branch and its create-form placeholder are
            // exercised deterministically (no active-slug dependency).
            'admin.servers.create (schema form)' => view('admin.servers.create', [
                'serverType' => 'generic',
                'moduleName' => 'Generic',
                'schema' => ['fields' => [['key' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'encrypted' => true]]],
                'groups' => collect(),
            ])->render(),

            // Client portal (own profile password change).
            'client.profile' => $this->actingAs($client)->get(route('client.profile'))->assertOk()->getContent(),
        ];

        foreach ($pages as $label => $html) {
            $this->assertPasswordWiring($label, $html);
        }
    }

    public function test_hosting_edit_is_wired(): void
    {
        view()->share('errors', new ViewErrorBag);

        $admin = $this->adminUser();
        $this->actingAs($admin);

        $customer = User::factory()->create(['role' => 'client']);
        $customerRow = Customer::create(['user_id' => $customer->id, 'status' => 'active']);

        $product = Product::create(['name' => 'Wiring Hosting', 'price' => 50]);
        ProductModule::create(['product_id' => $product->id, 'module_slug' => 'hyperv', 'enabled' => true, 'config' => []]);
        $account = HostingAccount::create([
            'customer_id' => $customerRow->id,
            'product_id' => $product->id,
            'host_name' => 'hv-wiring-01',
        ]);

        $html = $this->get(route('admin.hosting.edit', $account))->assertOk()->getContent();

        $this->assertPasswordWiring('admin.hosting.edit', $html);
    }

    private function assertPasswordWiring(string $label, string $html): void
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        // (a) + (b): every data-password-confirm="X" resolves to exactly one
        // element with id X, and that element is the paired INPUT.
        foreach (iterator_to_array($xpath->query('//*[@data-password-confirm]')) as $group) {
            $target = $group->getAttribute('data-password-confirm');
            $this->assertNotSame('', $target, "[{$label}] data-password-confirm must name an id");
            $matches = iterator_to_array($xpath->query('//*[@id="'.$target.'"]'));
            $this->assertCount(1, $matches, "[{$label}] data-password-confirm=\"{$target}\" must resolve to exactly one element");
            $this->assertSame('input', strtolower($matches[0]->tagName), "[{$label}] data-password-confirm=\"{$target}\" must resolve to an <input> (Generate writes the password there)");
        }

        // (c): every type="password" input sits inside a [data-password-field]
        // group and itself carries data-password-input.
        foreach (iterator_to_array($xpath->query('//input[@type="password"]')) as $input) {
            $name = $input->getAttribute('id');
            $this->assertTrue(
                $input->hasAttribute('data-password-input'),
                "[{$label}] password input (id=\"{$name}\") must carry data-password-input"
            );
            $this->assertGreaterThan(
                0,
                $xpath->query('ancestor::*[@data-password-field]', $input)->length,
                "[{$label}] password input (id=\"{$name}\") must sit inside a [data-password-field] group"
            );
        }
    }

    private function adminUser(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->assignRole('admin');

        return $user;
    }

    private function clientUser(): User
    {
        $user = User::factory()->create(['role' => 'client']);
        Customer::create(['user_id' => $user->id, 'status' => 'active']);

        return $user;
    }
}
