<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientDomainRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomerUser(): Customer
    {
        $user = User::factory()->create();

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Domain Corp',
            'status' => 'active',
        ]);
    }

    private function registrationProduct(): Product
    {
        $product = Product::query()
            ->where('name', 'Domain Registration')
            ->whereHas('group', fn ($q) => $q->where('slug', 'domain-registration'))
            ->first();

        if ($product !== null) {
            return $product;
        }

        // The migration inserts this row only when the seeded group exists —
        // a fresh test DB has no seeders, so mirror the migration's insert.
        $group = ProductGroup::firstOrCreate(
            ['slug' => 'domain-registration'],
            ['name' => 'Domain Registration', 'status' => 'active']
        );

        return Product::create([
            'name' => 'Domain Registration',
            'product_group_id' => $group->id,
            'price' => 0,
            'billing_cycle' => 'annual',
            'payment_type' => 'recurring',
            'provisioning_module' => 'manual',
            'require_domain' => true,
            'show_in_order' => false,
            'show_in_affiliate' => false,
            'only_admin' => false,
            'status' => 'active',
            'quantity_behaviour' => 'none',
            'recurring_cycles_limit' => 0,
        ]);
    }

    public function test_register_page_renders_search_form(): void
    {
        $customer = $this->makeCustomerUser();

        $this->actingAs($customer->user)
            ->get(route('client.domains.register'))
            ->assertOk()
            ->assertSee('Check Domain Availability');
    }

    public function test_register_page_searches_availability_and_renders_terms(): void
    {
        Http::fake(['rdap.org/*' => Http::response('', 404)]);

        $customer = $this->makeCustomerUser();

        $response = $this->actingAs($customer->user)
            ->get(route('client.domains.register', [
                'q' => 'brandnewdomain.com',
                'domain' => 'brandnewdomain.com',
            ]))
            ->assertOk();

        $response->assertSee('brandnewdomain.com');
        $response->assertSee('Available');
        $response->assertSee('Registration Period');
        $response->assertSee('Place Order', false);

        $this->assertDatabaseHas('domain_search_logs', [
            'customer_id' => $customer->id,
            'domain_name' => 'brandnewdomain.com',
        ]);
    }

    public function test_register_page_marks_taken_domain(): void
    {
        Http::fake(['rdap.org/*' => Http::response('{"ldhName": "taken.com"}', 200)]);

        $customer = $this->makeCustomerUser();

        $response = $this->actingAs($customer->user)
            ->get(route('client.domains.register', ['q' => 'taken.com']))
            ->assertOk();

        $response->assertSee('Taken');
        $response->assertDontSee('Registration Period');
    }

    public function test_client_registers_domain_creates_order_domain_and_invoice(): void
    {
        Http::fake(['rdap.org/*' => Http::response('', 404)]);

        $customer = $this->makeCustomerUser();
        $product = $this->registrationProduct();

        $response = $this->actingAs($customer->user)
            ->from(route('client.domains.register'))
            ->post(route('client.domains.register.post'), [
                'domain' => 'brandnewdomain.com',
                'registration_period' => 2,
            ]);

        $order = Order::sole();
        $domain = Domain::sole();
        $invoice = Invoice::sole();

        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame($product->id, $order->product_id);
        $this->assertSame('annual', $order->billing_cycle);
        $this->assertSame('pending', $order->status);
        $this->assertSame('brandnewdomain.com', $order->domain_name);
        // No domain_pricing rows in a fresh test DB → deterministic default
        // (.com = 999/yr) × 2 years.
        $this->assertSame(1998.00, (float) $order->total);
        $this->assertMatchesRegularExpression('/^ORD-\d{4}-\d{5}$/', $order->order_number);

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'domain_name' => 'brandnewdomain.com',
            'billing_cycle' => 'annual',
            'quantity' => 1,
            'unit_price' => 1998.00,
            'total' => 1998.00,
        ]);

        $this->assertSame($customer->id, $domain->customer_id);
        $this->assertSame($order->id, $domain->order_id);
        $this->assertSame('brandnewdomain.com', $domain->name);
        $this->assertSame('register', $domain->type);
        $this->assertSame(2, $domain->registration_period);
        $this->assertSame('pending', $domain->status);
        $this->assertSame(now()->addYears(2)->toDateString(), $domain->expiry_date->toDateString());
        $this->assertSame(999.00, (float) $domain->recurring_amount);

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'order_id' => $order->id,
            'status' => 'draft',
            'amount' => 1998.00,
        ]);

        $this->assertDatabaseHas('activity_log', [
            'customer_id' => $customer->id,
            'action' => 'order_created',
        ]);

        $response->assertRedirect(route('client.invoices.pay', $invoice));
        $response->assertSessionHas('success');
    }

    public function test_registration_rejects_domain_taken_between_search_and_submit(): void
    {
        Http::fake(['rdap.org/*' => Http::response('{"ldhName": "taken.com"}', 200)]);

        $customer = $this->makeCustomerUser();

        $response = $this->actingAs($customer->user)
            ->from(route('client.domains.register'))
            ->post(route('client.domains.register.post'), [
                'domain' => 'taken.com',
                'registration_period' => 1,
            ]);

        $response->assertSessionHasErrors('domain');

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Domain::count());
        $this->assertSame(0, Invoice::count());
    }

    public function test_registration_rejects_invalid_term(): void
    {
        Http::fake(['rdap.org/*' => Http::response('', 404)]);

        $customer = $this->makeCustomerUser();

        $response = $this->actingAs($customer->user)
            ->from(route('client.domains.register'))
            ->post(route('client.domains.register.post'), [
                'domain' => 'brandnewdomain.com',
                'registration_period' => 11,
            ]);

        $response->assertSessionHasErrors('registration_period');

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Domain::count());
    }
}
