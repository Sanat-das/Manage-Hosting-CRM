<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * S2/T2.1 — `invoices.place_of_supply_code`: an additive nullable snapshot
 * column, written from the state code the billing service already resolves.
 */
class AddOnInvoiceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_place_of_supply_column_exists_and_is_nullable(): void
    {
        $this->assertTrue(Schema::hasColumn('invoices', 'place_of_supply_code'));

        $column = collect(Schema::getColumns('invoices'))->firstWhere('name', 'place_of_supply_code');

        $this->assertNotNull($column, 'invoices.place_of_supply_code must exist');
        $this->assertTrue((bool) $column['nullable'], 'invoices.place_of_supply_code must be nullable');
    }

    public function test_create_with_items_persists_the_state_code(): void
    {
        $customer = $this->makeCustomer();

        $invoice = app(BillingService::class)->createWithItems([
            'customer_id' => $customer->id,
            'amount' => 100.00,
            'status' => Invoice::STATUS_DRAFT,
            'due_date' => now()->addDays(7)->toDateString(),
        ], [
            ['description' => 'Manual service', 'quantity' => 1, 'unit_price' => 100.00, 'total' => 100.00],
        ], '29');

        $this->assertSame('29', $invoice->fresh()->place_of_supply_code);
    }

    public function test_create_with_items_leaves_the_column_null_without_a_state_code(): void
    {
        $customer = $this->makeCustomer();

        $invoice = app(BillingService::class)->createWithItems([
            'customer_id' => $customer->id,
            'amount' => 100.00,
            'status' => Invoice::STATUS_DRAFT,
            'due_date' => now()->addDays(7)->toDateString(),
        ], [
            ['description' => 'Manual service', 'quantity' => 1, 'unit_price' => 100.00, 'total' => 100.00],
        ]);

        $this->assertNull($invoice->fresh()->place_of_supply_code);
    }

    public function test_update_with_items_updates_the_stored_code(): void
    {
        $customer = $this->makeCustomer();
        $service = app(BillingService::class);

        $invoice = $service->createWithItems([
            'customer_id' => $customer->id,
            'amount' => 100.00,
            'status' => Invoice::STATUS_DRAFT,
            'due_date' => now()->addDays(7)->toDateString(),
        ], [
            ['description' => 'Manual service', 'quantity' => 1, 'unit_price' => 100.00, 'total' => 100.00],
        ], '29');

        $service->updateWithItems($invoice, [
            'customer_id' => $customer->id,
            'amount' => 100.00,
            'status' => Invoice::STATUS_SENT,
            'due_date' => now()->addDays(7)->toDateString(),
        ], [
            ['description' => 'Manual service', 'quantity' => 1, 'unit_price' => 100.00, 'total' => 100.00],
        ], '27');

        $this->assertSame('27', $invoice->fresh()->place_of_supply_code);
    }

    public function test_update_with_items_keeps_the_stored_code_when_none_is_passed(): void
    {
        $customer = $this->makeCustomer();
        $service = app(BillingService::class);

        $invoice = $service->createWithItems([
            'customer_id' => $customer->id,
            'amount' => 100.00,
            'status' => Invoice::STATUS_DRAFT,
            'due_date' => now()->addDays(7)->toDateString(),
        ], [
            ['description' => 'Manual service', 'quantity' => 1, 'unit_price' => 100.00, 'total' => 100.00],
        ], '29');

        $service->updateWithItems($invoice, [
            'customer_id' => $customer->id,
            'amount' => 100.00,
            'status' => Invoice::STATUS_SENT,
            'due_date' => now()->addDays(7)->toDateString(),
        ], [
            ['description' => 'Manual service', 'quantity' => 1, 'unit_price' => 100.00, 'total' => 100.00],
        ]);

        $this->assertSame('29', $invoice->fresh()->place_of_supply_code);
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Invoice Schema Corp',
            'status' => 'active',
        ]);
    }
}
