<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S2/T2.5 — Indian tax-invoice document fields: the place-of-supply snapshot
 * and the customer's GSTIN on the admin show page and the invoice PDF. Each
 * line is gated on its own value so legacy documents render unchanged.
 */
class InvoiceDocumentGstTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_page_and_pdf_render_place_of_supply_and_gstin(): void
    {
        $customer = $this->customer('27', '27AAAAA0000A1Z5');
        $invoice = $this->invoice($customer, '27');

        $show = $this->actingAs($this->admin())->get(route('admin.invoices.show', $invoice));

        $show->assertOk();
        $show->assertSeeText('Place of Supply');
        $show->assertSee('<div class="fw-semibold">27</div>', false);
        $show->assertSee('<td>27AAAAA0000A1Z5</td>', false);

        $pdf = $this->pdf($invoice);

        $this->assertStringContainsString('Place of Supply: 27', $pdf);
        $this->assertStringContainsString('GSTIN: 27AAAAA0000A1Z5', $pdf);
    }

    public function test_legacy_invoice_without_state_code_or_gstin_omits_both_lines(): void
    {
        $customer = $this->customer('27', null);
        $invoice = $this->invoice($customer, null);

        $show = $this->actingAs($this->admin())->get(route('admin.invoices.show', $invoice));

        $show->assertOk();
        $show->assertDontSeeText('Place of Supply');
        $show->assertDontSeeText('GSTIN');

        $pdf = $this->pdf($invoice);

        $this->assertStringNotContainsString('Place of Supply', $pdf);
        $this->assertStringNotContainsString('GSTIN', $pdf);
    }

    public function test_place_of_supply_renders_without_a_customer_gstin(): void
    {
        $customer = $this->customer('29', null);
        $invoice = $this->invoice($customer, '29');

        $show = $this->actingAs($this->admin())->get(route('admin.invoices.show', $invoice));

        $show->assertOk();
        $show->assertSeeText('Place of Supply');
        $show->assertDontSeeText('GSTIN');

        $pdf = $this->pdf($invoice);

        $this->assertStringContainsString('Place of Supply: 29', $pdf);
        $this->assertStringNotContainsString('GSTIN', $pdf);
    }

    // ─────────────────────────────── helpers ───────────────────────────────

    private function invoice(Customer $customer, ?string $stateCode): Invoice
    {
        return app(BillingService::class)->createWithItems([
            'customer_id' => $customer->id,
            'amount' => 100.00,
            'status' => Invoice::STATUS_SENT,
            'due_date' => now()->addDays(7)->toDateString(),
        ], [
            ['description' => 'Managed hosting', 'quantity' => 1, 'unit_price' => 100.00, 'total' => 100.00],
        ], $stateCode);
    }

    private function pdf(Invoice $invoice): string
    {
        $fresh = $invoice->fresh();

        return view('admin.invoices.pdf', [
            'invoice' => $fresh,
            'gstBreakdown' => $fresh->gst_breakdown,
        ])->render();
    }

    private function customer(string $stateCode, ?string $taxId): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Document Fields Corp',
            'state_code' => $stateCode,
            'tax_id' => $taxId,
            'status' => 'active',
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $permission = Permission::firstOrCreate(['name' => 'invoices.view'], ['label' => 'View Invoices']);
        $adminRole->permissions()->syncWithoutDetaching($permission->id);

        $user->assignRole('admin');

        return $user;
    }
}
