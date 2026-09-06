<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin()
    {
        $user = User::factory()->create();

        // Seed the admin role and invoices.view permission in test DB
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $perm = Permission::firstOrCreate(['name' => 'invoices.view'], ['label' => 'View Invoices']);
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

    // ─── Invoice edit / update ───────────────────────────────────

    private function actingAsInvoiceEditor()
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $view = Permission::firstOrCreate(['name' => 'invoices.view'], ['label' => 'View Invoices']);
        $edit = Permission::firstOrCreate(['name' => 'invoices.edit'], ['label' => 'Edit Invoices']);
        $adminRole->permissions()->syncWithoutDetaching([$view->id, $edit->id]);

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    private function makeInvoice(int $customerId, string $status = 'draft', array $items = [], float $amount = 100.0): Invoice
    {
        $invoice = Invoice::create([
            'customer_id' => $customerId,
            'invoice_no' => 'INV-2026-'.random_int(10000, 99999),
            'amount' => $amount,
            'tax' => 0,
            'total' => $amount,
            'status' => $status,
            'due_date' => now()->addDays(7),
        ]);

        foreach ($items as $item) {
            $invoice->items()->create($item);
        }

        return $invoice;
    }

    public function test_invoice_edit_loads(): void
    {
        $customer = $this->createCustomerWithUser();
        $invoice = $this->makeInvoice($customer->id, 'draft', [
            ['description' => 'Setup', 'quantity' => 1, 'unit_price' => 50.0, 'total' => 50.0],
        ]);

        $response = $this->actingAsInvoiceEditor()->get("/admin/invoices/{$invoice->id}/edit");
        $response->assertStatus(200);
        $response->assertSee('name="items[0][description]"', false);
        $response->assertSee('name="discount"', false);
        $response->assertSee('name="customer_id"', false);
    }

    public function test_invoice_update_recomputes_totals(): void
    {
        $customer = $this->createCustomerWithUser();
        $invoice = $this->makeInvoice($customer->id, 'draft', [
            ['description' => 'Old', 'quantity' => 1, 'unit_price' => 10.0, 'total' => 10.0],
        ]);

        $response = $this->actingAsInvoiceEditor()->put("/admin/invoices/{$invoice->id}", [
            'customer_id' => $customer->id,
            'discount' => 10,
            'status' => 'sent',
            'due_date' => now()->addDays(14)->toDateString(),
            'notes' => 'Updated.',
            'items' => [
                ['description' => 'Hosting', 'quantity' => 2, 'unit_price' => 100.0],
                ['description' => 'Addon', 'quantity' => 1, 'unit_price' => 25.0],
            ],
        ]);

        $response->assertRedirect(route('admin.invoices.show', $invoice));

        $invoice->refresh();
        $this->assertEquals(225.0, (float) $invoice->amount);
        $this->assertEquals(215.0, (float) $invoice->total); // 225 + 0 tax − 10 discount
        $this->assertCount(2, $invoice->items);
        $this->assertEquals('Hosting', $invoice->items->first()->description);
        $this->assertEquals('sent', $invoice->status);
        $this->assertEquals('Updated.', $invoice->notes);
    }

    public function test_paid_invoice_rejects_item_changes(): void
    {
        $customer = $this->createCustomerWithUser();
        $invoice = $this->makeInvoice($customer->id, 'paid', [
            ['description' => 'Original', 'quantity' => 1, 'unit_price' => 100.0, 'total' => 100.0],
        ]);

        $response = $this->actingAsInvoiceEditor()->put("/admin/invoices/{$invoice->id}", [
            'customer_id' => $customer->id,
            'discount' => 50,
            'status' => 'paid',
            'notes' => 'Just a note.',
            'items' => [
                ['description' => 'Hacked', 'quantity' => 9, 'unit_price' => 999.0],
            ],
        ]);

        $response->assertRedirect(route('admin.invoices.show', $invoice));

        $invoice->refresh();
        $this->assertCount(1, $invoice->items);
        $this->assertEquals('Original', $invoice->items->first()->description);
        $this->assertEquals(100.0, (float) $invoice->amount);
        $this->assertEquals('Just a note.', $invoice->notes);
    }

    public function test_invalid_status_transition_rejected(): void
    {
        $customer = $this->createCustomerWithUser();
        $invoice = $this->makeInvoice($customer->id, 'draft');

        $response = $this->actingAsInvoiceEditor()->put("/admin/invoices/{$invoice->id}", [
            'customer_id' => $customer->id,
            'status' => 'paid', // not in draft's transition set
            'items' => [
                ['description' => 'X', 'quantity' => 1, 'unit_price' => 10.0],
            ],
        ]);

        $response->assertSessionHasErrors('status');

        $invoice->refresh();
        $this->assertEquals('draft', $invoice->status);
    }

    // ─── Inline payment recording (invoice page) ─────────────────

    private function actingAsPaymentRecorder()
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $view = Permission::firstOrCreate(['name' => 'invoices.view'], ['label' => 'View Invoices']);
        $create = Permission::firstOrCreate(['name' => 'payments.create'], ['label' => 'Create Payments']);
        $adminRole->permissions()->syncWithoutDetaching([$view->id, $create->id]);

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    public function test_invoice_page_records_full_payment(): void
    {
        $customer = $this->createCustomerWithUser();
        $invoice = $this->makeInvoice($customer->id, 'sent', [
            ['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 100.0, 'total' => 100.0],
        ], 100.0);

        $response = $this->actingAsPaymentRecorder()->post("/admin/invoices/{$invoice->id}/payment", [
            'amount' => 100,
            'method' => 'bank_transfer',
            'transaction_id' => 'TXN-1',
        ]);

        $response->assertRedirect(route('admin.invoices.show', $invoice));

        $invoice->refresh();
        $this->assertEquals('paid', $invoice->status);
        $this->assertEquals(100.0, (float) $invoice->paid_amount);
        $this->assertNotNull($invoice->paid_at);
        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'amount' => 100.0,
            'method' => 'bank_transfer',
            'transaction_id' => 'TXN-1',
            'status' => 'completed',
        ]);
    }

    public function test_invoice_page_records_partial_payment(): void
    {
        $customer = $this->createCustomerWithUser();
        $invoice = $this->makeInvoice($customer->id, 'sent', [
            ['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 100.0, 'total' => 100.0],
        ], 100.0);

        $response = $this->actingAsPaymentRecorder()->post("/admin/invoices/{$invoice->id}/payment", [
            'amount' => 40,
            'method' => 'manual',
        ]);

        $response->assertRedirect(route('admin.invoices.show', $invoice));

        $invoice->refresh();
        $this->assertEquals('partial', $invoice->status);
        $this->assertEquals(40.0, (float) $invoice->paid_amount);
    }

    public function test_invoice_page_rejects_payment_on_paid_invoice(): void
    {
        $customer = $this->createCustomerWithUser();
        $invoice = $this->makeInvoice($customer->id, 'paid', [], 100.0);

        $response = $this->actingAsPaymentRecorder()->post("/admin/invoices/{$invoice->id}/payment", [
            'amount' => 50,
            'method' => 'manual',
        ]);

        $response->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('payments', 0);
        $this->assertEquals('paid', $invoice->fresh()->status);
    }
}
