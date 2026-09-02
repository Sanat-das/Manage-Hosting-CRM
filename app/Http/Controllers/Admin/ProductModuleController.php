<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\Product;
use App\Models\ProductModule;
use App\Services\Modules\ModuleManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Admin per-product module management (show page "Modules" tab).
 *
 * The `product_module` pivot holds one row per product-module pair with an
 * `enabled` flag and the per-product `config` JSON (values encrypted by the
 * module manager where the schema marks them so).
 *
 * Only active modules can be attached to a product — toggling a module that
 * is not currently active is rejected with 403.
 */
class ProductModuleController extends Controller
{
    public function __construct(private readonly ModuleManager $manager)
    {
    }

    public function toggle(Product $product, Module $module, Request $request): RedirectResponse
    {
        // Only active modules can be attached to a product.
        $activeSlugs = $this->manager->active()->pluck('slug')->all();

        if (! in_array($module->slug, $activeSlugs, true)) {
            abort(403, 'Only active modules can be enabled on a product.');
        }

        $pivot = ProductModule::query()->firstOrNew([
            'product_id' => $product->id,
            'module_id' => $module->id,
        ]);

        if ($pivot->exists && $pivot->enabled) {
            $pivot->update(['enabled' => false]);
            $message = "Module {$module->name} disabled on product {$product->name}.";
        } else {
            // Initialize per-product config from the module's schema defaults.
            // Existing keys are preserved (re-enabling a disabled module keeps
            // its saved config); only missing keys take the schema default.
            $schema = $this->manager->resolve($module)?->configSchema() ?? ['fields' => []];
            $config = $pivot->config ?? [];

            foreach ($schema['fields'] as $field) {
                if (array_key_exists('default', $field) && ! array_key_exists($field['key'], $config)) {
                    $config[$field['key']] = $field['default'];
                }
            }

            $pivot->fill(['enabled' => true, 'config' => $config])->save();
            $message = "Module {$module->name} enabled on product {$product->name}.";
        }

        return redirect()
            ->route('admin.products.show', [$product, 'tab' => 'modules'])
            ->with('success', $message);
    }

    public function updateConfig(Product $product, Module $module, Request $request): RedirectResponse
    {
        $pivot = ProductModule::query()
            ->where('product_id', $product->id)
            ->where('module_id', $module->id)
            ->first();

        abort_unless($pivot, 404, 'Module is not enabled on this product.');

        $schema = $this->manager->resolve($module)?->configSchema() ?? ['fields' => []];

        // Build validation rules from the module's config schema: required
        // fields must be present; number fields must be numeric; checkboxes
        // are boolean switches and are never validated.
        $rules = [];

        foreach ($schema['fields'] as $field) {
            if (($field['type'] ?? 'text') === 'checkbox') {
                continue;
            }

            $fieldRules = [];

            if (! empty($field['required'])) {
                $fieldRules[] = 'required';
            }

            if (($field['type'] ?? 'text') === 'number') {
                $fieldRules[] = 'numeric';
            }

            if ($fieldRules !== []) {
                $rules['config.'.$field['key']] = $fieldRules;
            }
        }

        $request->validate($rules);

        $encrypted = $this->manager->encryptConfig($module, $request->input('config', []));

        $pivot->update(['config' => $encrypted]);

        return redirect()
            ->route('admin.products.show', [$product, 'tab' => 'modules'])
            ->with('success', "Configuration saved for module {$module->name}.");
    }
}
