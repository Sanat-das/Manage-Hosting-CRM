<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What happens to a line's option snapshot when an invoice is edited.
 *
 * BillingService::updateWithItems() replaces every line wholesale, and the
 * admin invoice form posts only description / quantity / unit_price — no line
 * id and no product_id. So a line has no identity across the edit, and the
 * snapshot describing what it bills for has to be matched back by hand.
 *
 * Two failure modes matter, and they pull in opposite directions: losing the
 * configuration on an unrelated edit (a notes change wipes what every line was
 * for), and — far worse — attaching one line's configuration to another line.
 * These tests pin both, including the case where two lines are genuinely
 * indistinguishable and the only safe answer is to carry nothing.
 */
class InvoiceOptionSnapshotCarryOverTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Carry Corp',
            'status' => 'active',
        ]);
    }

    /**
     * A minimal option snapshot in the shape OrderConfigSnapshot writes.
     *
     * @return array<string, mixed>
     */
    private function snapshot(string $ram): array
    {
        return [
            'billing_cycle' => 'monthly',
            'options' => [
                [
                    'id' => 1,
                    'key' => 'ram',
                    'group' => 'RAM',
                    'unit' => 'GB',
                    'selected' => $ram,
                    'price_applied' => 100.0,
                ],
            ],
        ];
    }

    /**
     * An invoice shaped like one BillingService::createInvoiceForOrder raises:
     * every line carries a product_id, which is exactly what the edit form
     * then fails to post back.
     *
     * @param  list<array{description: string, config: string|null}>  $lines
     */
    private function makeInvoice(Customer $customer, array $lines): Invoice
    {
        $product = Product::create([
            'name' => 'Cloud VPS',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'status' => 'active',
        ]);

        $items = array_map(fn (array $line) => array_filter([
            'description' => $line['description'],
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 100.00,
            'total' => 100.00,
            'config_options' => $line['config'] !== null ? $this->snapshot($line['config']) : null,
        ], fn ($value) => $value !== null), $lines);

        return app(BillingService::class)->createWithItems([
            'customer_id' => $customer->id,
            'amount' => 100.00 * count($lines),
            'status' => Invoice::STATUS_DRAFT,
            'due_date' => now()->addDays(7)->toDateString(),
        ], $items);
    }

    /**
     * The payload the admin invoice form actually submits — note it carries
     * neither a line id nor a product_id.
     *
     * @param  list<string>  $descriptions
     * @return list<array<string, mixed>>
     */
    private function editPayload(array $descriptions): array
    {
        return array_map(fn (string $description) => [
            'description' => $description,
            'quantity' => 1,
            'unit_price' => 100.00,
            'total' => 100.00,
        ], $descriptions);
    }

    private function ramOf(Invoice $invoice, int $index): ?string
    {
        $item = $invoice->fresh()->items()->orderBy('id')->get()[$index] ?? null;

        return $item?->config_options['options'][0]['selected'] ?? null;
    }

    public function test_an_unrelated_edit_keeps_each_lines_configuration(): void
    {
        // Changing the notes must not erase what the lines bill for.
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, [
            ['description' => 'Cloud VPS - Monthly', 'config' => '8'],
            ['description' => 'Backup Add-on - Monthly', 'config' => '16'],
        ]);

        app(BillingService::class)->updateWithItems($invoice, [
            'customer_id' => $customer->id,
            'amount' => 200.00,
            'status' => Invoice::STATUS_DRAFT,
            'due_date' => now()->addDays(7)->toDateString(),
            'notes' => 'Chased by phone.',
        ], $this->editPayload(['Cloud VPS - Monthly', 'Backup Add-on - Monthly']));

        $this->assertSame('8', $this->ramOf($invoice, 0));
        $this->assertSame('16', $this->ramOf($invoice, 1));
    }

    public function test_reordering_lines_moves_each_configuration_with_its_line(): void
    {
        // The admin drags the second line above the first. Each configuration
        // must follow its own line — matching by position would swap them.
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, [
            ['description' => 'Cloud VPS - Monthly', 'config' => '8'],
            ['description' => 'Backup Add-on - Monthly', 'config' => '16'],
        ]);

        app(BillingService::class)->updateWithItems($invoice, [
            'customer_id' => $customer->id,
            'amount' => 200.00,
            'status' => Invoice::STATUS_DRAFT,
            'due_date' => now()->addDays(7)->toDateString(),
        ], $this->editPayload(['Backup Add-on - Monthly', 'Cloud VPS - Monthly']));

        $this->assertSame('16', $this->ramOf($invoice, 0), 'The backup line kept its own configuration.');
        $this->assertSame('8', $this->ramOf($invoice, 1), 'The VPS line kept its own configuration.');
    }

    public function test_indistinguishable_lines_drop_the_configuration_rather_than_guess(): void
    {
        // Two units of the same product, configured differently, read
        // identically on an invoice ("Cloud VPS - Monthly" twice). Nothing in
        // the payload says which is which, so carrying either one over would
        // be a coin flip that silently bills 8 GB as 16 GB. Dropping both is
        // the only honest answer.
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, [
            ['description' => 'Cloud VPS - Monthly', 'config' => '8'],
            ['description' => 'Cloud VPS - Monthly', 'config' => '16'],
        ]);

        app(BillingService::class)->updateWithItems($invoice, [
            'customer_id' => $customer->id,
            'amount' => 200.00,
            'status' => Invoice::STATUS_DRAFT,
            'due_date' => now()->addDays(7)->toDateString(),
        ], $this->editPayload(['Cloud VPS - Monthly', 'Cloud VPS - Monthly']));

        $this->assertNull($this->ramOf($invoice, 0));
        $this->assertNull($this->ramOf($invoice, 1));
    }

    public function test_a_retyped_description_drops_the_configuration(): void
    {
        // The line was rewritten by hand; it may no longer describe the same
        // thing at all, so its old configuration is not carried forward.
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, [
            ['description' => 'Cloud VPS - Monthly', 'config' => '8'],
        ]);

        app(BillingService::class)->updateWithItems($invoice, [
            'customer_id' => $customer->id,
            'amount' => 100.00,
            'status' => Invoice::STATUS_DRAFT,
            'due_date' => now()->addDays(7)->toDateString(),
        ], $this->editPayload(['Consultancy - one off']));

        $this->assertNull($this->ramOf($invoice, 0));
    }

    public function test_a_new_line_never_inherits_another_lines_configuration(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, [
            ['description' => 'Cloud VPS - Monthly', 'config' => '8'],
        ]);

        app(BillingService::class)->updateWithItems($invoice, [
            'customer_id' => $customer->id,
            'amount' => 200.00,
            'status' => Invoice::STATUS_DRAFT,
            'due_date' => now()->addDays(7)->toDateString(),
        ], $this->editPayload(['Cloud VPS - Monthly', 'Setup fee']));

        $this->assertSame('8', $this->ramOf($invoice, 0), 'The untouched line keeps its configuration.');
        $this->assertNull($this->ramOf($invoice, 1), 'The added line starts with none.');
    }

    public function test_duplicating_a_line_does_not_copy_the_configuration_onto_both(): void
    {
        // The admin adds a second unit of the same product, so the invoice now
        // reads "Cloud VPS - Monthly" twice. Matching purely on the description
        // would hand the original's 8 GB to both lines — including the new one,
        // which has been configured with nothing at all. Neither may claim it.
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, [
            ['description' => 'Cloud VPS - Monthly', 'config' => '8'],
        ]);

        app(BillingService::class)->updateWithItems($invoice, [
            'customer_id' => $customer->id,
            'amount' => 200.00,
            'status' => Invoice::STATUS_DRAFT,
            'due_date' => now()->addDays(7)->toDateString(),
        ], $this->editPayload(['Cloud VPS - Monthly', 'Cloud VPS - Monthly']));

        $this->assertNull($this->ramOf($invoice, 0));
        $this->assertNull($this->ramOf($invoice, 1));
    }

    public function test_a_repeated_line_id_claims_the_stored_line_only_once(): void
    {
        // updateWithItems() is a public service method, so it cannot rely on
        // the controller's `distinct` rule alone. Two submitted lines naming
        // one stored line: the first is that line, the second is not, and must
        // inherit neither its configuration nor its product (which would carry
        // its per-product GST treatment with it).
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, [
            ['description' => 'Cloud VPS - Monthly', 'config' => '8'],
        ]);

        $itemId = (int) $invoice->items()->value('id');

        app(BillingService::class)->updateWithItems($invoice, [
            'customer_id' => $customer->id,
            'amount' => 200.00,
            'status' => Invoice::STATUS_DRAFT,
            'due_date' => now()->addDays(7)->toDateString(),
        ], [
            ['id' => $itemId, 'description' => 'Cloud VPS - Monthly', 'quantity' => 1, 'unit_price' => 100.00, 'total' => 100.00],
            ['id' => $itemId, 'description' => 'Cloud VPS - Monthly (copy)', 'quantity' => 1, 'unit_price' => 100.00, 'total' => 100.00],
        ]);

        $items = $invoice->fresh()->items()->orderBy('id')->get();

        $this->assertSame('8', $this->ramOf($invoice, 0), 'The first claim wins.');
        $this->assertNull($this->ramOf($invoice, 1), 'The second reference inherits nothing.');
        $this->assertNotNull($items[0]->product_id);
        $this->assertNull($items[1]->product_id, 'And no product, so no borrowed tax treatment.');
    }

    public function test_deleting_a_line_leaves_the_survivors_configuration_intact(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, [
            ['description' => 'Cloud VPS - Monthly', 'config' => '8'],
            ['description' => 'Backup Add-on - Monthly', 'config' => '16'],
        ]);

        app(BillingService::class)->updateWithItems($invoice, [
            'customer_id' => $customer->id,
            'amount' => 100.00,
            'status' => Invoice::STATUS_DRAFT,
            'due_date' => now()->addDays(7)->toDateString(),
        ], $this->editPayload(['Backup Add-on - Monthly']));

        $this->assertSame('16', $this->ramOf($invoice, 0), 'The surviving line keeps its own configuration, not the deleted one.');
    }
}
