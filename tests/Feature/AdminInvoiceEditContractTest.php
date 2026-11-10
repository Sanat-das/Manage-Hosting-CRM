<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The invoice edit is a contract: one request class, one policy, an audit
 * row, and a form that knows when it has gone stale.
 */
class AdminInvoiceEditContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_without_the_edit_permission_is_refused(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer->id);

        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'viewer'], ['label' => 'Viewer']);
        $permission = Permission::firstOrCreate(['name' => 'invoices.view'], ['label' => 'View Invoices']);
        $role->permissions()->syncWithoutDetaching($permission->id);
        $user->assignRole('viewer');

        $this->actingAs($user)
            ->put(route('admin.invoices.update', $invoice), [
                'status' => 'sent',
                'items' => [
                    ['description' => 'Hacked', 'quantity' => 1, 'unit_price' => 1.0],
                ],
            ])
            ->assertForbidden();

        $this->assertSame('draft', $invoice->fresh()->status);
    }

    public function test_an_edit_writes_an_audit_row_with_the_money_fields_before_and_after(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer->id);

        $this->actingAsEditor()->put(route('admin.invoices.update', $invoice), [
            'status' => 'sent',
            'discount' => 10,
            'due_date' => now()->addDays(14)->toDateString(),
            'items' => [
                ['description' => 'Hosting', 'quantity' => 2, 'unit_price' => 100.0],
            ],
        ])->assertRedirect();

        $log = ActivityLog::where('action', 'invoice.updated')->latest('id')->first();

        $this->assertNotNull($log, 'An invoice edit must leave an audit row.');
        $this->assertSame($customer->id, (int) $log->customer_id);
        $this->assertSame(Invoice::class, $log->subject_type);
        $this->assertSame($invoice->id, (int) $log->subject_id);

        $metadata = is_array($log->metadata) ? $log->metadata : json_decode((string) $log->metadata, true);

        $this->assertSame('draft', $metadata['before']['status']);
        $this->assertSame('sent', $metadata['after']['status']);
        $this->assertSame(100.0, (float) $metadata['before']['amount']);
        $this->assertSame(200.0, (float) $metadata['after']['amount']);
        $this->assertSame(190.0, (float) $metadata['after']['total']);
    }

    public function test_a_form_opened_before_the_invoice_changed_is_refused_with_a_clear_message(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer->id);

        $staleTimestamp = $invoice->updated_at->copy()->subMinute()->toDateTimeString();

        $response = $this->actingAsEditor()->put(route('admin.invoices.update', $invoice), [
            'status' => 'sent',
            'updated_at' => $staleTimestamp,
            'due_date' => now()->addDays(14)->toDateString(),
            'items' => [
                ['description' => 'Hosting', 'quantity' => 2, 'unit_price' => 100.0],
            ],
        ]);

        $response->assertSessionHasErrors('updated_at');

        $invoice->refresh();

        $this->assertSame('draft', $invoice->status);
        $this->assertSame(100.0, (float) $invoice->amount);
    }

    public function test_a_form_posted_with_the_current_timestamp_is_accepted(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer->id);

        $this->actingAsEditor()->put(route('admin.invoices.update', $invoice), [
            'status' => 'sent',
            'updated_at' => $invoice->updated_at->toDateTimeString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'items' => [
                ['description' => 'Hosting', 'quantity' => 2, 'unit_price' => 100.0],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame('sent', $invoice->fresh()->status);
    }

    public function test_the_edit_form_shows_the_live_totals_and_the_line_totals(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer->id);

        $response = $this->actingAsEditor()->get(route('admin.invoices.edit', $invoice));

        $response->assertOk();
        $response->assertSee('id="edit-subtotal"', false);
        $response->assertSee('id="edit-discount"', false);
        $response->assertSee('id="edit-due"', false);
        $response->assertSee('line-total', false);
        $response->assertSee('name="items[0][description]"', false);
    }

    public function test_a_failed_save_keeps_the_lines_the_admin_added(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer->id);

        // A discount larger than the amount fails the save, and the admin is
        // sent back to the form with the rows they had typed — including the
        // one they added, which used to disappear.
        $this->actingAsEditor()->put(route('admin.invoices.update', $invoice), [
            'status' => 'sent',
            'discount' => 500,
            'due_date' => now()->addDays(14)->toDateString(),
            'items' => [
                ['id' => $invoice->items->first()->id, 'description' => 'Original', 'quantity' => 1, 'unit_price' => 100.0],
                ['description' => 'Added line', 'quantity' => 2, 'unit_price' => 50.0],
            ],
        ])->assertSessionHasErrors('discount');

        $response = $this->actingAsEditor()->get(route('admin.invoices.edit', $invoice));

        $response->assertOk();
        $response->assertSee('Added line', false);
        $response->assertSee('name="items[1][description]"', false);
    }

    private function actingAsEditor()
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        foreach (['invoices.view', 'invoices.edit'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => ucfirst($name)]);
            $adminRole->permissions()->syncWithoutDetaching($permission->id);
        }

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
    }

    private function makeInvoice(int $customerId): Invoice
    {
        $invoice = Invoice::create([
            'customer_id' => $customerId,
            'invoice_no' => 'INV-2026-'.random_int(10000, 99999),
            'amount' => 100.00,
            'tax' => 0,
            'total' => 100.00,
            'status' => Invoice::STATUS_DRAFT,
            'due_date' => now()->addDays(7),
        ]);

        $invoice->items()->create([
            'description' => 'Original',
            'quantity' => 1,
            'unit_price' => 100.0,
            'total' => 100.0,
        ]);

        return $invoice;
    }
}
