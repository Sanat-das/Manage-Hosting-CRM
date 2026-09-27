<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

use App\Contracts\Integrations\Capabilities\ProvisioningModule;
use App\Models\Product;
use App\Models\ProductModule;
use App\Services\Integrations\IntegrationRegistry;

/**
 * Keeps the Details-tab provisioning module selection in step with the
 * `product_module` link every runtime consumer resolves through.
 *
 * The Details dropdown is the single switch: exactly one builtin provisioner
 * link stays enabled, the selected module's per-product config defaults are
 * seeded on first link, and the chosen auto/manual mode is stored on the link.
 * Manual means "never provision on order" — an operator builds the account or
 * VM later from the hosting page, with the product's template restriction and
 * optional template selection still enforced.
 */
final class ProvisioningModuleLinker
{
    public function __construct(private readonly IntegrationRegistry $registry) {}

    /**
     * @param  string|null  $mode  explicit auto|manual from the form; null keeps
     *                             the link's stored mode (or the module default)
     */
    public function sync(Product $product, ?string $mode = null): void
    {
        $slug = trim((string) $product->provisioning_module);

        if ($slug === '' || ! $this->registry->has($slug)) {
            return;
        }

        if (! $this->registry->instanceFor($slug) instanceof ProvisioningModule) {
            return;
        }

        $mode = in_array($mode, ProductModule::PROVISIONING_MODES, true) ? $mode : null;

        ProductModule::query()
            ->where('product_id', $product->id)
            ->where('enabled', true)
            ->whereIn('module_slug', $this->registry->slugs())
            ->where('module_slug', '!=', $slug)
            ->get()
            ->each(function (ProductModule $link): void {
                $link->update(['enabled' => false]);
            });

        $link = ProductModule::query()->firstOrNew([
            'product_id' => $product->id,
            'module_slug' => $slug,
        ]);

        if ($link->exists) {
            $updates = [];

            if (! $link->enabled) {
                $updates['enabled'] = true;
            }

            if ($mode !== null && (string) $link->provisioning_mode !== $mode) {
                $updates['provisioning_mode'] = $mode;
            }

            if ($updates !== []) {
                $link->update($updates);
            }

            return;
        }

        $config = [];

        foreach ($this->registry->configSchemaFor($slug)['fields'] as $field) {
            if (array_key_exists('default', $field) && ! array_key_exists($field['key'], $config)) {
                $config[$field['key']] = $field['default'];
            }
        }

        $link->fill([
            'enabled' => true,
            'provisioning_mode' => $mode ?? ProductModule::defaultModeFor($slug),
            'config' => $config,
        ])->save();
    }
}
