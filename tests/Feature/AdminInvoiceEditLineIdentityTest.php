<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\GstSetting;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invoice line identity across an edit.
 *
 * The admin edit form deletes and recreates every line on save, so anything
 * the form does not post back is lost. It posted only description / quantity /
 * unit_price, which silently blanked each line's `product_id` — breaking the
 * product link AND, because GstTaxService::calculateItemTax() reads
 * `product_id` for per-product GST, quietly changing the tax on invoices under
 * the per_product or mixed tax modes.
 *
 * The fix is to post each existing line's id, so a line keeps its identity and
 * its detail can be restored from the row it came from.
 */
class AdminInvoiceEditLineIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        $user = User::factory()->create();

        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        foreach (['invoices.view', 'invoices.manage', 'invoices.edit'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->assignRole('admin');

        return $user;
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Line Identity Corp',
            'status' => 'active',
        ]);
    }

    private function makeProduct(string $name): Product
    {
        return Product::create([
            'name' => $name,
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'status' => 'active',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(string $ram): array
    {
        return [
            'billing_cycle' => 'monthly',
            'options' => [
                ['id' => 1, 'key' => 'ram', 'group' => 'RAM', 'unit' => 'GB', 'selected' => $ram, 'price_applied' => 100.0],
            ],
        ];
    }

    private function makeInvoice(Customer $customer, Product ...$products): Invoice
    {
        $items = [];

        foreach ($products as $index => $product) {
            $items[] = [
                'description' => $product->name.' - Monthly',
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 100.00,
                'total' => 100.00,
                'config_options' => $this->snapshot($index === 0 ? '8' : '16'),
            ];
        }

        return app(BillingService::class)->createWithItems([
            'customer_id' => $customer->id,
            'amount' => 100.00 * count($items),
            'status' => Invoice::STATUS_DRAFT,
            'due_date' => now()->addDays(7)->toDateString(),
        ], $items);
    }

    /**
     * The payload the edit form submits, now including each line's id.
     *
     * @param  list<array{id: int|null, description: string}>  $lines
     * @return array<string, mixed>
     */
    private function payload(Customer $customer, array $lines): array
    {
        return [
            'customer_id' => $customer->id,
            'status' => Invoice::STATUS_DRAFT,
            'due_date' => now()->addDays(7)->toDateString(),
            'notes' => 'Chased by phone.',
            'items' => array_map(fn (array $line) => array_filter([
                'id' => $line['id'],
                'description' => $line['description'],
                'quantity' => 1,
                'unit_price' => 100.00,
            ], fn ($value) => $value !== null), $lines),
        ];
    }

    public function test_the_edit_form_posts_each_lines_id(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, $this->makeProduct('Cloud VPS'));
        $item = $invoice->items()->sole();

        $this->actingAs($this->adminUser())
            ->get(route('admin.invoices.edit', $invoice))
            ->assertOk()
            ->assertSee('name="items[0][id]"', false)
            ->assertSee('value="'.$item->id.'"', false);
    }

    public function test_editing_an_invoice_keeps_each_lines_product(): void
    {
        $customer = $this->makeCustomer();
        $vps = $this->makeProduct('Cloud VPS');
        $backup = $this->makeProduct('Backup Add-on');
        $invoice = $this->makeInvoice($customer, $vps, $backup);

        [$firstId, $secondId] = $invoice->items()->orderBy('id')->pluck('id')->all();

        $this->actingAs($this->adminUser())
            ->put(route('admin.invoices.update', $invoice), $this->payload($customer, [
                ['id' => $firstId, 'description' => 'Cloud VPS - Monthly'],
                ['id' => $secondId, 'description' => 'Backup Add-on - Monthly'],
            ]))
            ->assertRedirect(route('admin.invoices.show', $invoice));

        $items = $invoice->fresh()->items()->orderBy('id')->get();

        $this->assertSame($vps->id, (int) $items[0]->product_id, 'The line kept its product link.');
        $this->assertSame($backup->id, (int) $items[1]->product_id);
    }

    public function test_editing_an_invoice_keeps_each_lines_configuration(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, $this->makeProduct('Cloud VPS'), $this->makeProduct('Backup Add-on'));

        [$firstId, $secondId] = $invoice->items()->orderBy('id')->pluck('id')->all();

        $this->actingAs($this->adminUser())
            ->put(route('admin.invoices.update', $invoice), $this->payload($customer, [
                ['id' => $firstId, 'description' => 'Cloud VPS - Monthly'],
                ['id' => $secondId, 'description' => 'Backup Add-on - Monthly'],
            ]))
            ->assertRedirect(route('admin.invoices.show', $invoice));

        $items = $invoice->fresh()->items()->orderBy('id')->get();

        $this->assertSame('8', $items[0]->config_options['options'][0]['selected']);
        $this->assertSame('16', $items[1]->config_options['options'][0]['selected']);
    }

    public function test_an_id_beats_a_retyped_description(): void
    {
        // Renaming a line is a relabelling, not a replacement: the id says it
        // is the same line, so its product and configuration follow it. The
        // description fallback could never have done this.
        $customer = $this->makeCustomer();
        $vps = $this->makeProduct('Cloud VPS');
        $invoice = $this->makeInvoice($customer, $vps);
        $itemId = $invoice->items()->value('id');

        $this->actingAs($this->adminUser())
            ->put(route('admin.invoices.update', $invoice), $this->payload($customer, [
                ['id' => $itemId, 'description' => 'Cloud VPS - Monthly (renewed)'],
            ]))
            ->assertRedirect(route('admin.invoices.show', $invoice));

        $item = $invoice->fresh()->items()->sole();

        $this->assertSame('Cloud VPS - Monthly (renewed)', $item->description);
        $this->assertSame($vps->id, (int) $item->product_id);
        $this->assertSame('8', $item->config_options['options'][0]['selected']);
    }

    public function test_identically_described_lines_keep_their_own_detail_when_ids_are_posted(): void
    {
        // Two units of the same product read identically, which the
        // description fallback has to give up on. Their ids tell them apart.
        $customer = $this->makeCustomer();
        $vps = $this->makeProduct('Cloud VPS');
        $invoice = $this->makeInvoice($customer, $vps, $vps);

        [$firstId, $secondId] = $invoice->items()->orderBy('id')->pluck('id')->all();

        $this->actingAs($this->adminUser())
            ->put(route('admin.invoices.update', $invoice), $this->payload($customer, [
                ['id' => $firstId, 'description' => 'Cloud VPS - Monthly'],
                ['id' => $secondId, 'description' => 'Cloud VPS - Monthly'],
            ]))
            ->assertRedirect(route('admin.invoices.show', $invoice));

        $items = $invoice->fresh()->items()->orderBy('id')->get();

        $this->assertSame('8', $items[0]->config_options['options'][0]['selected']);
        $this->assertSame('16', $items[1]->config_options['options'][0]['selected'], 'Each unit kept its own memory.');
    }

    public function test_reordering_lines_carries_each_lines_detail_with_it(): void
    {
        $customer = $this->makeCustomer();
        $vps = $this->makeProduct('Cloud VPS');
        $backup = $this->makeProduct('Backup Add-on');
        $invoice = $this->makeInvoice($customer, $vps, $backup);

        [$firstId, $secondId] = $invoice->items()->orderBy('id')->pluck('id')->all();

        $this->actingAs($this->adminUser())
            ->put(route('admin.invoices.update', $invoice), $this->payload($customer, [
                ['id' => $secondId, 'description' => 'Backup Add-on - Monthly'],
                ['id' => $firstId, 'description' => 'Cloud VPS - Monthly'],
            ]))
            ->assertRedirect(route('admin.invoices.show', $invoice));

        $items = $invoice->fresh()->items()->orderBy('id')->get();

        $this->assertSame($backup->id, (int) $items[0]->product_id);
        $this->assertSame('16', $items[0]->config_options['options'][0]['selected']);
        $this->assertSame($vps->id, (int) $items[1]->product_id);
        $this->assertSame('8', $items[1]->config_options['options'][0]['selected']);
    }

    public function test_a_new_line_carries_nothing(): void
    {
        $customer = $this->makeCustomer();
        $vps = $this->makeProduct('Cloud VPS');
        $invoice = $this->makeInvoice($customer, $vps);
        $itemId = $invoice->items()->value('id');

        $this->actingAs($this->adminUser())
            ->put(route('admin.invoices.update', $invoice), $this->payload($customer, [
                ['id' => $itemId, 'description' => 'Cloud VPS - Monthly'],
                ['id' => null, 'description' => 'Setup fee'],
            ]))
            ->assertRedirect(route('admin.invoices.show', $invoice));

        $items = $invoice->fresh()->items()->orderBy('id')->get();

        $this->assertSame($vps->id, (int) $items[0]->product_id);
        $this->assertNull($items[1]->product_id);
        $this->assertNull($items[1]->config_options);
    }

    /**
     * Per-product GST: tax is charged only on products that opt in, so two
     * lines on one invoice can be taxed differently. That is exactly what a
     * lost product_id destroys — GstTaxService::calculateItemTax() reads it to
     * find the product's own GST settings.
     */
    private function enablePerProductGst(): void
    {
        // The migration seeds the id=1 row; `id` is not fillable, so update it.
        GstSetting::where('id', 1)->update([
            'state_code' => '27',
            'state_name' => 'Maharashtra',
            'cgst_rate' => 9,
            'sgst_rate' => 9,
            'igst_rate' => 18,
            'enabled' => 1,
            'tax_mode' => GstSetting::TAX_MODE_PER_PRODUCT,
        ]);
    }

    public function test_reordering_lines_preserves_each_lines_tax(): void
    {
        $this->enablePerProductGst();

        $customer = $this->makeCustomer();
        $taxed = $this->makeProduct('Cloud VPS');
        $taxed->update(['gst_enabled' => 1, 'gst_type' => 'standard']);

        $exempt = $this->makeProduct('Exempt Service');
        $exempt->update(['gst_enabled' => 0]);

        $invoice = $this->makeInvoice($customer, $taxed, $exempt);
        [$taxedId, $exemptId] = $invoice->items()->orderBy('id')->pluck('id')->all();

        $originalTax = (float) $invoice->fresh()->tax;
        $this->assertGreaterThan(0.0, $originalTax, 'The taxed line must actually be taxed to start with.');

        // Drag the exempt line above the taxed one and save.
        $this->actingAs($this->adminUser())
            ->put(route('admin.invoices.update', $invoice), $this->payload($customer, [
                ['id' => $exemptId, 'description' => 'Exempt Service - Monthly'],
                ['id' => $taxedId, 'description' => 'Cloud VPS - Monthly'],
            ]))
            ->assertRedirect(route('admin.invoices.show', $invoice));

        $fresh = $invoice->fresh();
        $items = $fresh->items()->orderBy('id')->get();

        $this->assertSame($originalTax, (float) $fresh->tax, 'The invoice tax is unchanged by reordering.');
        $this->assertSame(0, (int) $items[0]->gst_enabled, 'The exempt line is still exempt.');
        $this->assertSame(1, (int) $items[1]->gst_enabled, 'The taxed line is still taxed.');
        $this->assertGreaterThan(0.0, (float) $items[1]->cgst_amount + (float) $items[1]->igst_amount);
    }

    public function test_renaming_a_line_preserves_its_tax(): void
    {
        $this->enablePerProductGst();

        $customer = $this->makeCustomer();
        $taxed = $this->makeProduct('Cloud VPS');
        $taxed->update(['gst_enabled' => 1, 'gst_type' => 'standard']);

        $invoice = $this->makeInvoice($customer, $taxed);
        $itemId = $invoice->items()->value('id');
        $originalTax = (float) $invoice->fresh()->tax;

        $this->assertGreaterThan(0.0, $originalTax);

        $this->actingAs($this->adminUser())
            ->put(route('admin.invoices.update', $invoice), $this->payload($customer, [
                ['id' => $itemId, 'description' => 'Cloud VPS - Monthly (renewed)'],
            ]))
            ->assertRedirect(route('admin.invoices.show', $invoice));

        $fresh = $invoice->fresh();

        $this->assertSame($originalTax, (float) $fresh->tax, 'Relabelling a line does not change what it is taxed.');
        $this->assertSame(1, (int) $fresh->items()->sole()->gst_enabled);
    }

    public function test_the_same_line_id_cannot_be_claimed_twice(): void
    {
        // Two submitted lines naming one stored line: only one of them can be
        // that line. Accepting it would give both the same product — doubling
        // its tax treatment onto a line that is not it.
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, $this->makeProduct('Cloud VPS'));
        $itemId = $invoice->items()->value('id');

        $this->actingAs($this->adminUser())
            ->from(route('admin.invoices.edit', $invoice))
            ->put(route('admin.invoices.update', $invoice), $this->payload($customer, [
                ['id' => $itemId, 'description' => 'Cloud VPS - Monthly'],
                ['id' => $itemId, 'description' => 'Cloud VPS - Monthly (copy)'],
            ]))
            ->assertSessionHasErrors('items.0.id');

        $this->assertSame(1, $invoice->fresh()->items()->count(), 'The invoice is left alone.');
    }

    public function test_a_line_id_from_another_invoice_is_rejected(): void
    {
        // Line ids arrive from the browser. One belonging to somebody else's
        // invoice must not be able to pull that invoice's product and
        // configuration onto this one.
        $customer = $this->makeCustomer();
        $mine = $this->makeInvoice($customer, $this->makeProduct('Cloud VPS'));

        $otherCustomer = $this->makeCustomer();
        $theirs = $this->makeInvoice($otherCustomer, $this->makeProduct('Someone Elses VPS'));
        $theirItemId = $theirs->items()->value('id');

        $this->actingAs($this->adminUser())
            ->from(route('admin.invoices.edit', $mine))
            ->put(route('admin.invoices.update', $mine), $this->payload($customer, [
                ['id' => $theirItemId, 'description' => 'Cloud VPS - Monthly'],
            ]))
            ->assertSessionHasErrors('items.0.id');

        $this->assertSame(1, $theirs->fresh()->items()->count(), 'The other invoice is untouched.');
    }
}
