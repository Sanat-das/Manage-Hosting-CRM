<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\GstSetting;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Manual admin invoices must bill with the customer's place of supply, not
 * the IGST-by-default the null state code used to produce.
 */
class ManualInvoicePlaceOfSupplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_invoice_for_same_state_customer_bills_cgst_sgst(): void
    {
        $this->gst(['state_code' => '27']);
        $customer = $this->customer('27');

        $this->actingAs($this->admin())->post(route('admin.invoices.store'), $this->payload($customer->id))
            ->assertRedirect();

        $invoice = Invoice::where('customer_id', $customer->id)->sole();

        $this->assertGreaterThan(0, (float) $invoice->cgst_amount);
        $this->assertGreaterThan(0, (float) $invoice->sgst_amount);
        $this->assertSame(0.0, (float) $invoice->igst_amount);
    }

    public function test_manual_invoice_for_other_state_customer_bills_igst(): void
    {
        $this->gst(['state_code' => '27']);
        $customer = $this->customer('29');

        $this->actingAs($this->admin())->post(route('admin.invoices.store'), $this->payload($customer->id))
            ->assertRedirect();

        $invoice = Invoice::where('customer_id', $customer->id)->sole();

        $this->assertGreaterThan(0, (float) $invoice->igst_amount);
        $this->assertSame(0.0, (float) $invoice->cgst_amount);
        $this->assertSame(0.0, (float) $invoice->sgst_amount);
    }

    public function test_manual_invoice_update_recomputes_gst_with_customer_state(): void
    {
        $this->gst(['state_code' => '27']);
        $admin = $this->admin();
        $customer = $this->customer('27');

        $draft = $this->payload($customer->id);
        $draft['status'] = 'draft';

        $this->actingAs($admin)->post(route('admin.invoices.store'), $draft)
            ->assertRedirect();

        $invoice = Invoice::where('customer_id', $customer->id)->sole();
        $lineId = $invoice->items()->value('id');

        $this->actingAs($admin)->put(route('admin.invoices.update', $invoice), [
            'customer_id' => $customer->id,
            'status' => 'sent',
            'due_date' => now()->addDays(7)->toDateString(),
            'items' => [
                ['id' => $lineId, 'description' => 'Manual service', 'quantity' => 1, 'unit_price' => 100.00],
            ],
        ])->assertRedirect();

        $fresh = $invoice->fresh();

        $this->assertGreaterThan(0, (float) $fresh->cgst_amount);
        $this->assertGreaterThan(0, (float) $fresh->sgst_amount);
        $this->assertSame(0.0, (float) $fresh->igst_amount);
    }

    // ─────────────────────────────── helpers ───────────────────────────────

    private function gst(array $overrides = []): GstSetting
    {
        $gst = GstSetting::firstOrNew([]);

        $gst->fill(array_replace([
            'gstin' => '27AAAAA0000A1Z5',
            'legal_name' => 'Acme Hosting Pvt Ltd',
            'state_code' => '27',
            'state_name' => 'Maharashtra',
            'cgst_rate' => 9.00,
            'sgst_rate' => 9.00,
            'igst_rate' => 18.00,
            'enabled' => true,
            'tax_mode' => 'global',
        ], $overrides))->save();

        return $gst;
    }

    private function customer(string $stateCode): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Place of Supply Corp',
            'state_code' => $stateCode,
            'status' => 'active',
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        foreach (['invoices.view', 'invoices.create', 'invoices.edit'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => ucfirst($name)]);
            $adminRole->permissions()->syncWithoutDetaching($permission->id);
        }

        $user->assignRole('admin');

        return $user;
    }

    private function payload(int $customerId): array
    {
        return [
            'customer_id' => $customerId,
            'amount' => 100.00,
            'status' => 'sent',
            'due_date' => now()->addDays(7)->toDateString(),
            'items' => [
                ['description' => 'Manual service', 'quantity' => 1, 'unit_price' => 100.00],
            ],
        ];
    }
}
