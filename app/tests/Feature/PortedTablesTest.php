<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortedTablesTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdminUser(): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $view = Permission::firstOrCreate(['name' => 'hosting.view'], ['label' => 'View Hosting']);
        $manage = Permission::firstOrCreate(['name' => 'hosting.manage'], ['label' => 'Manage Hosting']);
        $invoiceView = Permission::firstOrCreate(['name' => 'invoices.view'], ['label' => 'View Invoices']);
        $adminRole->permissions()->syncWithoutDetaching([$view->id, $manage->id, $invoiceView->id]);

        $user->assignRole('admin');

        return $user;
    }

    private function makeClient(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Ported Corp',
            'status' => 'active',
        ]);
    }

    private function makeInvoice(Customer $customer, ?string $invoiceNo = null): Invoice
    {
        return Invoice::create([
            'customer_id' => $customer->id,
            'invoice_no' => $invoiceNo ?? 'INV-'.strtoupper(Str::random(10)),
            'amount' => 100.00,
            'tax' => 0,
            'total' => 100.00,
            'status' => 'sent',
            'due_date' => now()->addDays(7),
        ]);
    }

    // ─── tax_rates CRUD ────────────────────────────────────────────

    public function test_tax_rate_index_requires_auth(): void
    {
        $this->get('/admin/tax-rates')->assertRedirect();
    }

    public function test_admin_can_index_tax_rates(): void
    {
        $admin = $this->makeAdminUser();
        TaxRate::create(['name' => 'GST', 'rate' => 18.00, 'is_active' => true]);
        TaxRate::create(['name' => 'Service', 'rate' => 5.00, 'is_active' => false]);

        $this->actingAs($admin)->get('/admin/tax-rates')
            ->assertOk()
            ->assertSee('GST')
            ->assertSee('Service');
    }

    public function test_admin_can_store_tax_rate(): void
    {
        $admin = $this->makeAdminUser();

        $response = $this->actingAs($admin)->post('/admin/tax-rates', [
            'name' => 'GST',
            'rate' => 18.00,
        ]);

        $response->assertRedirect(route('admin.tax-rates.index'));
        $this->assertDatabaseHas('tax_rates', [
            'name' => 'GST',
            'rate' => 18.00,
            'is_active' => 1,
        ]);
    }

    public function test_admin_can_update_tax_rate(): void
    {
        $admin = $this->makeAdminUser();
        $rate = TaxRate::create(['name' => 'GST', 'rate' => 18.00, 'is_active' => true]);

        $response = $this->actingAs($admin)->put("/admin/tax-rates/{$rate->id}", [
            'name' => 'GST Revised',
            'rate' => 19.00,
            'is_active' => 0,
        ]);

        $response->assertRedirect(route('admin.tax-rates.show', $rate));
        $this->assertDatabaseHas('tax_rates', [
            'id' => $rate->id,
            'name' => 'GST Revised',
            'rate' => 19.00,
            'is_active' => 0,
        ]);
    }

    public function test_admin_can_destroy_tax_rate(): void
    {
        $admin = $this->makeAdminUser();
        $rate = TaxRate::create(['name' => 'GST', 'rate' => 18.00, 'is_active' => true]);

        $response = $this->actingAs($admin)->delete("/admin/tax-rates/{$rate->id}");

        $response->assertRedirect(route('admin.tax-rates.index'));
        $this->assertDatabaseMissing('tax_rates', ['id' => $rate->id]);
    }

    // ─── invoice_pdf_log ───────────────────────────────────────────

    public function test_invoice_pdf_log_requires_auth(): void
    {
        $customer = $this->makeClient();
        $invoice = $this->makeInvoice($customer);

        $this->get(route('admin.invoices.pdf', $invoice))->assertRedirect();
    }

    public function test_admin_invoice_pdf_generation_writes_pdf_log(): void
    {
        $admin = $this->makeAdminUser();
        $customer = $this->makeClient();
        $invoice = $this->makeInvoice($customer);

        $bytes = 'fake-pdf-bytes-'.str_repeat('x', 128);

        // The Pdf facade resolves a fresh 'dompdf.wrapper' instance per call
        // (it overrides __callStatic), so bind a mock into the container.
        $pdf = \Mockery::mock();
        $pdf->shouldReceive('loadView')->once()->andReturnSelf();
        $pdf->shouldReceive('setPaper')->andReturnSelf();
        $pdf->shouldReceive('output')->andReturn($bytes);
        $pdf->shouldReceive('download')->andReturn(response($bytes, 200, ['Content-Type' => 'application/pdf']));

        $this->app->instance('dompdf.wrapper', $pdf);

        $this->actingAs($admin)->get(route('admin.invoices.pdf', $invoice));

        $this->assertDatabaseHas('invoice_pdf_log', [
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'generated_by' => $admin->id,
            'file_name' => "invoice-{$invoice->invoice_no}.pdf",
            'file_size' => strlen($bytes),
            'file_path' => '',
        ]);
    }

    // ─── marketing_consent_log ─────────────────────────────────────

    public function test_client_consent_opt_in_creates_log_row(): void
    {
        $customer = $this->makeClient();
        $user = $customer->user;

        $this->actingAs($user)
            ->post(route('client.profile.consent'), ['consent' => '1', 'contact_type' => 'marketing_email'])
            ->assertRedirect(route('client.profile'))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('marketing_consent_log', 1);
        $this->assertDatabaseHas('marketing_consent_log', [
            'customer_id' => $customer->id,
            'contact_type' => 'marketing_email',
            'consent_status' => 'opt_in',
            'source' => 'profile',
        ]);
    }

    public function test_client_consent_opt_out_updates_existing_row(): void
    {
        $customer = $this->makeClient();
        $user = $customer->user;

        $this->actingAs($user)
            ->post(route('client.profile.consent'), ['consent' => '1', 'contact_type' => 'marketing_email']);

        $this->actingAs($user)
            ->post(route('client.profile.consent'), ['contact_type' => 'marketing_email'])
            ->assertRedirect(route('client.profile'))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('marketing_consent_log', 1);
        $this->assertDatabaseHas('marketing_consent_log', [
            'customer_id' => $customer->id,
            'contact_type' => 'marketing_email',
            'consent_status' => 'opt_out',
        ]);
        $this->assertDatabaseMissing('marketing_consent_log', [
            'customer_id' => $customer->id,
            'contact_type' => 'marketing_email',
            'consent_status' => 'opt_in',
        ]);
    }
}
