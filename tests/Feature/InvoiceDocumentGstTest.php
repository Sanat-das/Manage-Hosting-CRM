<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Settings\BillingSettings;
use App\Settings\BrandingSettings;
use App\Settings\GeneralSettings;
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

    public function test_pdf_renders_rupee_totals_amount_in_words_and_paid_badge(): void
    {
        $customer = $this->customer('27', null);
        $invoice = $this->invoice($customer, '27', Invoice::STATUS_PAID);

        $pdf = $this->pdf($invoice);

        $this->assertStringContainsString('₹100.00', $pdf);
        $this->assertStringContainsString('INR', $pdf);
        $this->assertStringContainsString('>PAID<', $pdf);
        $this->assertStringContainsString('Amount in words:', $pdf);
        $this->assertStringContainsString('Rupees', $pdf);
    }

    public function test_pdf_renders_seller_and_bank_details_from_settings(): void
    {
        $general = app(GeneralSettings::class);
        $general->company_gstin = '27BBBBB0000B1Z5';
        $general->save();

        $bank = app(BillingSettings::class);
        $bank->bank_ifsc = 'HDFC0001234';
        $bank->save();

        $customer = $this->customer('27', null);
        $invoice = $this->invoice($customer, '27');

        $pdf = $this->pdf($invoice);

        $this->assertStringContainsString(config('app.name'), $pdf);
        $this->assertStringContainsString('GSTIN: 27BBBBB0000B1Z5', $pdf);
        $this->assertStringContainsString('IFSC: HDFC0001234', $pdf);
    }

    public function test_pdf_renders_company_name_as_seller_name(): void
    {
        $general = app(GeneralSettings::class);
        $general->company_name = 'Acme Hosting Pvt Ltd';
        $general->save();

        $branding = app(BrandingSettings::class);
        $branding->branding_logo_path = 'branding/nonexistent.png';
        $branding->save();

        $customer = $this->customer('27', null);
        $invoice = $this->invoice($customer, '27');

        $pdf = $this->pdf($invoice);

        $this->assertStringContainsString('Acme Hosting Pvt Ltd', $pdf);
    }

    public function test_pdf_renders_ref_line_from_linked_order(): void
    {
        $customer = $this->customer('27', null);
        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => 1,
            'order_number' => 'ORD-2026-0001',
            'total' => 100.00,
        ]);
        $invoice = $this->invoice($customer, '27');
        $invoice->update(['order_id' => $order->id]);

        $pdf = $this->pdf($invoice);

        $this->assertStringContainsString('Ref: ', $pdf);
        $this->assertStringContainsString('Ref: ORD-2026-0001', $pdf);
    }

    public function test_pdf_renders_tax_label_with_intra_rates(): void
    {
        $customer = $this->customer('27', null);
        $invoice = $this->invoice($customer, '27');
        $invoice->update([
            'cgst_rate' => 9,
            'sgst_rate' => 9,
            'cgst_amount' => 9.00,
            'sgst_amount' => 9.00,
            'igst_rate' => null,
            'igst_amount' => 0,
            'tax' => 18.00,
            'total' => 118.00,
        ]);

        $pdf = $this->pdf($invoice);

        $this->assertStringContainsString('Tax (CGST 9% + SGST 9%)', $pdf);
    }

    public function test_pdf_renders_tax_label_with_inter_rate(): void
    {
        $customer = $this->customer('09', null);
        $invoice = $this->invoice($customer, '27');
        $invoice->update([
            'igst_rate' => 18,
            'igst_amount' => 18.00,
            'cgst_rate' => null,
            'cgst_amount' => 0,
            'sgst_rate' => null,
            'sgst_amount' => 0,
            'tax' => 18.00,
            'total' => 118.00,
        ]);

        $pdf = $this->pdf($invoice);

        $this->assertStringContainsString('Tax (IGST 18%)', $pdf);
    }

    // ─────────────────────────────── helpers ───────────────────────────────

    private function invoice(Customer $customer, ?string $stateCode, string $status = Invoice::STATUS_SENT): Invoice
    {
        $invoice = app(BillingService::class)->createWithItems([
            'customer_id' => $customer->id,
            'amount' => 100.00,
            'status' => Invoice::STATUS_SENT,
            'due_date' => now()->addDays(7)->toDateString(),
        ], [
            ['description' => 'Managed hosting', 'quantity' => 1, 'unit_price' => 100.00, 'total' => 100.00],
        ], $stateCode);

        if ($status !== Invoice::STATUS_SENT) {
            $invoice->update(['status' => $status]);
        }

        return $invoice;
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
