<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin()
    {
        $user = User::factory()->create();

        // Seed the admin role and invoices.view permission in test DB
        $adminRole = \App\Models\Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $perm = \App\Models\Permission::firstOrCreate(['name' => 'invoices.view'], ['label' => 'View Invoices']);
        $adminRole->permissions()->syncWithoutDetaching($perm->id);

        $user->assignRole('admin');
        return $this->actingAs($user);
    }

    private function createCustomerWithUser(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');
        return Customer::create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
    }

    public function test_invoice_index_requires_auth(): void
    {
        $response = $this->get('/admin/invoices');
        $response->assertRedirect();
    }

    public function test_invoice_index_loads_for_admin(): void
    {
        $response = $this->actingAsAdmin()->get('/admin/invoices');
        $response->assertStatus(200);
    }

    public function test_invoice_show_loads(): void
    {
        $customer = $this->createCustomerWithUser();
        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'invoice_no' => 'INV-2026-00001',
            'amount' => 100.00,
            'tax' => 0,
            'total' => 100.00,
            'status' => 'sent',
            'due_date' => now()->addDays(7),
        ]);

        $response = $this->actingAsAdmin()->get("/admin/invoices/{$invoice->id}");
        $response->assertStatus(200);
    }

    public function test_client_cannot_view_other_invoice(): void
    {
        $user = User::factory()->create();
        $user->assignRole('client');
        $customer = Customer::create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $otherUser = User::factory()->create();
        $otherUser->assignRole('client');
        $otherCustomer = Customer::create([
            'user_id' => $otherUser->id,
            'status' => 'active',
        ]);

        $invoice = Invoice::create([
            'customer_id' => $otherCustomer->id,
            'invoice_no' => 'INV-2026-00002',
            'amount' => 100.00,
            'tax' => 0,
            'total' => 100.00,
            'status' => 'sent',
            'due_date' => now()->addDays(7),
        ]);

        $response = $this->actingAs($user)->get("/client/invoices/{$invoice->id}");
        $response->assertStatus(404);
    }
}
