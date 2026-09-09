<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GstSetting;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GST configuration lives on the Settings page (Billing tab), not on a page of
 * its own, and a product says so when nothing on its GST panel can reach an
 * invoice.
 *
 * Context: three controls used to claim to switch GST on and only one of them
 * did anything. The other two were removed; this covers the survivor being where
 * it says it is, still writing gst_settings (the only table GstTaxService reads),
 * and the product form warning when that table makes its GST fields inert.
 */
class AdminGstSettingsLocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_gst_form_renders_on_the_billing_tab_and_posts_to_its_own_route(): void
    {
        $this->seedGst(['gstin' => '27AAAAA0000A1Z5', 'enabled' => true]);

        $html = $this->admin()->get(route('admin.settings.index', ['tab' => 'billing']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('GST &amp; Tax', $html);
        $this->assertStringContainsString('27AAAAA0000A1Z5', $html);

        // Forms cannot nest, so the fields ride the settings page visually while
        // belonging to their own form via the HTML5 form= attribute.
        $this->assertStringContainsString('id="gst-settings-form"', $html);
        $this->assertStringContainsString(route('admin.gst-settings.update'), $html);
        $this->assertMatchesRegularExpression('/name="gstin"[^>]*form="gst-settings-form"|form="gst-settings-form"[^>]*name="gstin"/', $html);

        // And it is NOT part of the settings[] payload — that is what made the
        // old duplicate toggles inert.
        $this->assertStringNotContainsString('name="settings[gstin]"', $html);
        $this->assertStringNotContainsString('name="settings[gst_enabled]"', $html);
    }

    public function test_saving_writes_gst_settings_and_returns_to_the_billing_tab(): void
    {
        $this->seedGst();

        $this->admin()->put(route('admin.gst-settings.update'), [
            'gstin' => '27BBBBB1111B2Z6',
            'legal_name' => 'Acme Hosting Pvt Ltd',
            'state_code' => '27',
            'state_name' => 'Maharashtra',
            'cgst_rate' => 9,
            'sgst_rate' => 9,
            'igst_rate' => 18,
            'tax_mode' => 'per_product',
            'enabled' => 1,
        ])->assertRedirect(route('admin.settings.index', ['tab' => 'billing']));

        $gst = GstSetting::first();
        $this->assertSame('27BBBBB1111B2Z6', $gst->gstin);
        $this->assertSame('per_product', $gst->tax_mode);
        $this->assertTrue((bool) $gst->enabled);
    }

    public function test_the_old_standalone_page_redirects_to_the_settings_page(): void
    {
        $this->admin()->get(route('admin.gst-settings.edit'))
            ->assertRedirect(route('admin.settings.index', ['tab' => 'billing']));

        // Visiting it also seeds the row, so the settings form always has
        // something to render.
        $this->assertNotNull(GstSetting::first());
    }

    public function test_a_product_warns_when_gst_is_switched_off_company_wide(): void
    {
        $this->seedGst(['enabled' => false]);
        $product = $this->product();

        $html = $this->admin()->get(route('admin.products.edit', $product))->assertOk()->getContent();

        $this->assertStringContainsString('GST is switched off company-wide', $html);
    }

    public function test_a_product_warns_when_global_tax_mode_ignores_its_gst_fields(): void
    {
        $this->seedGst(['enabled' => true, 'tax_mode' => 'global']);
        $product = $this->product();

        $html = $this->admin()->get(route('admin.products.edit', $product))->assertOk()->getContent();

        $this->assertStringContainsString('Tax mode is <strong>Global</strong>', $html);
        $this->assertStringNotContainsString('GST is switched off company-wide', $html);
    }

    public function test_a_configured_per_product_setup_shows_no_warning(): void
    {
        $this->seedGst(['enabled' => true, 'tax_mode' => 'per_product']);
        $product = $this->product();

        $html = $this->admin()->get(route('admin.products.edit', $product))->assertOk()->getContent();

        $this->assertStringNotContainsString('GST is switched off company-wide', $html);
        $this->assertStringNotContainsString('Tax mode is <strong>Global</strong>', $html);
    }

    // ─────────────────────────────── helpers ───────────────────────────────

    /**
     * The single gst_settings row already exists — the create_config_tables
     * migration inserts it (enabled 0, tax_mode global). Creating another here
     * would leave GstTaxService::loadSettings(), which reads id 1, looking at
     * the migration's row while the test configured a second one.
     */
    private function seedGst(array $overrides = []): GstSetting
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
            'enabled' => false,
            'tax_mode' => 'global',
        ], $overrides))->save();

        return $gst;
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'Shared Hosting Basic',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ]);
    }

    private function admin(): self
    {
        $user = User::factory()->create(['role' => 'admin']);

        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        foreach (['settings.view', 'settings.manage', 'settings.edit', 'products.view', 'products.edit'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => ucfirst($name)]);
            $role->permissions()->syncWithoutDetaching($permission->id);
        }

        $user->assignRole('admin');

        return $this->actingAs($user);
    }
}
