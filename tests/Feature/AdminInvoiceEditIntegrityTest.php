<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\GstSetting;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The invoice edit path must not be able to move money.
 *
 * A status that means "the money moved" (paid, partial, refunded) is only
 * reachable through the payment and refund actions — editing may not fake it.
 * The header amount is derived from the line items, the discount can never
 * exceed it, the tax base is the discounted value and the stored fields
 * survive a partial form.
 */
class AdminInvoiceEditIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsEditor()
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $view = Permission::firstOrCreate(['name' => 'invoices.view'], ['label' => 'View Invoices']);
        $create = Permission::firstOrCreate(['name' => 'invoices.create'], ['label' => 'Create Invoices']);
        $edit = Permission::firstOrCreate(['name' => 'invoices.edit'], ['label' => 'Edit Invoices']);
        $adminRole->permissions()->syncWithoutDetaching([$view->id, $create->id, $edit->id]);

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    private function customer(string $stateCode = '27'): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'state_code' => $stateCode,
            'status' => 'active',
        ]);
    }

    private function invoice(int $customerId, string $status = 'sent', array $items = [], float $amount = 100.0, array $overrides = []): Invoice
    {
        $invoice = Invoice::create(array_merge([
            'customer_id' => $customerId,
            'invoice_no' => 'INV-2026-'.random_int(10000, 99999),
            'amount' => $amount,
            'tax' => 0,
            'total' => $amount,
            'status' => $status,
            'due_date' => now()->addDays(7),
        ], $overrides));

        foreach ($items as $item) {
            $invoice->items()->create($item);
        }

        return $invoice;
    }

    private function enableGst(): void
    {
        GstSetting::firstOrNew([])->fill([
            'legal_name' => 'Acme Hosting Pvt Ltd',
            'state_code' => '27',
            'state_name' => 'Maharashtra',
            'cgst_rate' => 9.00,
            'sgst_rate' => 9.00,
            'igst_rate' => 18.00,
            'enabled' => true,
            'tax_mode' => 'global',
        ])->save();
    }

    public function test_edit_cannot_mark_an_invoice_paid(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer->id, 'sent', [
            ['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 100.0, 'total' => 100.0],
        ]);

        $this->actingAsEditor()->put(route('admin.invoices.update', $invoice), [
            'status' => 'paid',
            'due_date' => now()->addDays(7)->toDateString(),
            'items' => [
                ['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 100.0],
            ],
        ])->assertSessionHasErrors('status');

        $invoice->refresh();

        $this->assertSame('sent', $invoice->status);
        $this->assertSame(0.0, (float) $invoice->paid_amount);
        $this->assertNull($invoice->paid_at);
        $this->assertSame(0, Payment::where('invoice_id', $invoice->id)->count());
    }

    public function test_edit_cannot_mark_a_paid_invoice_refunded(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer->id, 'paid', [
            ['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 100.0, 'total' => 100.0],
        ], 100.0, ['paid_amount' => 100.0, 'paid_at' => now()]);

        $this->actingAsEditor()->put(route('admin.invoices.update', $invoice), [
            'status' => 'refunded',
            'notes' => 'Refund please.',
        ])->assertSessionHasErrors('status');

        $invoice->refresh();

        $this->assertSame('paid', $invoice->status);
    }

    public function test_create_derives_the_amount_from_the_line_items(): void
    {
        $customer = $this->customer();

        $this->actingAsEditor()->post(route('admin.invoices.store'), [
            'customer_id' => $customer->id,
            // A stale browser value must not reach the header.
            'amount' => 9999,
            'status' => 'draft',
            'due_date' => now()->addDays(7)->toDateString(),
            'items' => [
                ['description' => 'Hosting', 'quantity' => 2, 'unit_price' => 250.0],
            ],
        ])->assertRedirect();

        $invoice = Invoice::where('customer_id', $customer->id)->sole();

        $this->assertSame(500.0, (float) $invoice->amount);
        $this->assertSame(500.0, (float) $invoice->total);
    }

    public function test_update_rejects_a_discount_larger_than_the_amount(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer->id, 'sent', [
            ['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 100.0, 'total' => 100.0],
        ]);

        $this->actingAsEditor()->put(route('admin.invoices.update', $invoice), [
            'status' => 'sent',
            'discount' => 500,
            'due_date' => now()->addDays(7)->toDateString(),
            'items' => [
                ['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 100.0],
            ],
        ])->assertSessionHasErrors('discount');

        $invoice->refresh();

        $this->assertSame(100.0, (float) $invoice->amount);
        $this->assertSame(100.0, (float) $invoice->total);
        $this->assertSame(0.0, (float) $invoice->discount);
    }

    public function test_update_keeps_the_due_date_when_the_field_is_omitted(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer->id, 'sent', [
            ['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 100.0, 'total' => 100.0],
        ]);

        $this->actingAsEditor()->put(route('admin.invoices.update', $invoice), [
            'status' => 'sent',
            'items' => [
                ['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 100.0],
            ],
        ])->assertRedirect();

        $invoice->refresh();

        $this->assertNotNull($invoice->due_date);
        $this->assertSame(now()->addDays(7)->toDateString(), $invoice->due_date->toDateString());
    }

    public function test_update_ignores_a_posted_customer_id(): void
    {
        $customer = $this->customer();
        $other = $this->customer();
        $invoice = $this->invoice($customer->id, 'sent', [
            ['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 100.0, 'total' => 100.0],
        ]);

        $this->actingAsEditor()->put(route('admin.invoices.update', $invoice), [
            'customer_id' => $other->id,
            'status' => 'sent',
            'due_date' => now()->addDays(7)->toDateString(),
            'items' => [
                ['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 100.0],
            ],
        ])->assertRedirect();

        $invoice->refresh();

        $this->assertSame($customer->id, (int) $invoice->customer_id);
    }

    public function test_update_keeps_the_stored_discount_when_the_field_is_omitted(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer->id, 'sent', [
            ['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 100.0, 'total' => 100.0],
        ], 100.0, ['discount' => 10.0]);

        $this->actingAsEditor()->put(route('admin.invoices.update', $invoice), [
            'status' => 'sent',
            'due_date' => now()->addDays(7)->toDateString(),
            'items' => [
                ['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 100.0],
            ],
        ])->assertRedirect();

        $invoice->refresh();

        $this->assertSame(10.0, (float) $invoice->discount);
        $this->assertSame(90.0, (float) $invoice->total);
    }

    public function test_tax_is_computed_on_the_discounted_value(): void
    {
        $this->enableGst();

        $customer = $this->customer('27');

        $this->actingAsEditor()->post(route('admin.invoices.store'), [
            'customer_id' => $customer->id,
            'status' => 'sent',
            'discount' => 100,
            'due_date' => now()->addDays(7)->toDateString(),
            'items' => [
                ['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 1000.0],
            ],
        ])->assertRedirect();

        $invoice = Invoice::where('customer_id', $customer->id)->sole();

        // Taxable value = 1000 - 100 = 900; 18% intra-state (CGST 9 + SGST 9).
        $this->assertSame(1000.0, (float) $invoice->amount);
        $this->assertSame(81.0, (float) $invoice->cgst_amount);
        $this->assertSame(81.0, (float) $invoice->sgst_amount);
        $this->assertSame(162.0, (float) $invoice->tax);
        $this->assertSame(1062.0, (float) $invoice->total);
        $this->assertSame(18.0, (float) $invoice->tax_rate);

        // The line still bills its gross amount — the discount sits on the
        // header, once.
        $this->assertSame(1000.0, (float) $invoice->items->first()->total);
    }

    public function test_update_recomputes_the_tax_on_the_discounted_value(): void
    {
        $this->enableGst();

        $customer = $this->customer('27');
        $invoice = $this->invoice($customer->id, 'draft', [
            ['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 1000.0, 'total' => 1000.0],
        ]);

        $this->actingAsEditor()->put(route('admin.invoices.update', $invoice), [
            'status' => 'sent',
            'discount' => 250,
            'due_date' => now()->addDays(7)->toDateString(),
            'items' => [
                ['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 1000.0],
            ],
        ])->assertRedirect();

        $invoice->refresh();

        // Taxable value = 1000 - 250 = 750; 18% = 135.
        $this->assertSame(135.0, (float) $invoice->tax);
        $this->assertSame(885.0, (float) $invoice->total);
        $this->assertSame(18.0, (float) $invoice->tax_rate);
    }
}
