<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductModule;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Modules\ModuleManager;
use App\Services\Provisioning\HypervTemplateCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Admin per-product module management (show page "Modules" tab).
 *
 * The `product_module` pivot holds one row per product-module pair with an
 * `enabled` flag and the per-product `config` JSON (values encrypted where
 * the schema marks them so). Links are now keyed by module_slug (VARCHAR)
 * instead of module_id; builtins resolve via IntegrationRegistry, plugins via
 * ModuleManager.
 */
class ProductModuleController extends Controller
{
    public function __construct(
        private readonly IntegrationRegistry $registry,
        private readonly ModuleManager $manager,
    ) {
    }

    /**
     * Resolve a module slug to its display name and whether it is linkable.
     * Builtins are always linkable; plugins must be active.
     *
     * @return array{exists: bool, name: string, isBuiltin: bool, isActivePlugin: bool}
     */
    private function resolveTarget(string $slug): array
    {
        if ($this->registry->has($slug)) {
            return [
                'exists' => true,
                'name' => $this->registry->nameFor($slug),
                'isBuiltin' => true,
                'isActivePlugin' => false,
            ];
        }

        $module = $this->manager->find($slug);

        if ($module === null) {
            return ['exists' => false, 'name' => $slug, 'isBuiltin' => false, 'isActivePlugin' => false];
        }

        $isActive = $module->status === \App\Models\Module::STATUS_ACTIVE;

        return [
            'exists' => true,
            'name' => $module->name,
            'isBuiltin' => false,
            'isActivePlugin' => $isActive,
        ];
    }

    public function toggle(Product $product, string $moduleSlug, Request $request): RedirectResponse
    {
        $target = $this->resolveTarget($moduleSlug);
        abort_unless($target['exists'], 404, 'Module not found.');

        // Builtins are always allowed; plugins must be active
        if (! $target['isBuiltin'] && ! $target['isActivePlugin']) {
            abort(403, 'Only active modules can be enabled on a product.');
        }

        $pivot = ProductModule::query()->firstOrNew([
            'product_id' => $product->id,
            'module_slug' => $moduleSlug,
        ]);

        if ($pivot->exists && $pivot->enabled) {
            $pivot->update(['enabled' => false]);
            $message = "Module {$target['name']} disabled on product {$product->name}.";
        } else {
            $schema = $this->registry->configSchemaFor($moduleSlug);
            $config = $pivot->config ?? [];

            foreach ($schema['fields'] as $field) {
                if (array_key_exists('default', $field) && ! array_key_exists($field['key'], $config)) {
                    $config[$field['key']] = $field['default'];
                }
            }

            $attachedKeys = $product->optionLinks()->with('group')->get()
                ->map(fn ($link) => strtolower(trim((string) ($link->group?->key ?? ''))))
                ->filter()
                ->values()
                ->all();

            $missing = \App\Services\Provisioning\ModuleRequiredOptions::missingKeysFor($moduleSlug, $attachedKeys);

            if ($missing !== []) {
                return back()->withErrors([
                    'module' => "Cannot enable {$target['name']}: the product is missing required Configuration Options (".implode(', ', $missing)."). Attach option groups with those keys on the Options tab first.",
                ]);
            }

            $pivot->fill([
                'enabled' => true,
                'provisioning_mode' => $pivot->provisioning_mode ?? ProductModule::PROVISIONING_MODE_AUTO,
                'config' => $config,
            ])->save();
            $message = "Module {$target['name']} enabled on product {$product->name}.";
        }

        return redirect()
            ->route('admin.products.show', [$product, 'tab' => 'modules'])
            ->with('success', $message);
    }

    /**
     * Per-link Auto/Manual switch. Only the link owner (this product) is
     * affected — other products using the same module keep their own mode.
     */
    public function updateMode(Product $product, string $moduleSlug, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'provisioning_mode' => ['required', 'string', 'in:auto,manual'],
        ]);

        $target = $this->resolveTarget($moduleSlug);
        abort_unless($target['exists'], 404, 'Module not found.');

        $pivot = ProductModule::query()
            ->where('product_id', $product->id)
            ->where('module_slug', $moduleSlug)
            ->first();

        abort_unless($pivot, 404, 'Module is not enabled on this product.');

        $pivot->update(['provisioning_mode' => $validated['provisioning_mode']]);

        return redirect()
            ->route('admin.products.show', [$product, 'tab' => 'modules'])
            ->with('success', "Module {$target['name']} is now {$validated['provisioning_mode']} on product {$product->name}.");
    }

    public function updateConfig(Product $product, string $moduleSlug, Request $request): RedirectResponse
    {
        $target = $this->resolveTarget($moduleSlug);
        abort_unless($target['exists'], 404, 'Module not found.');

        $pivot = ProductModule::query()
            ->where('product_id', $product->id)
            ->where('module_slug', $moduleSlug)
            ->first();

        abort_unless($pivot, 404, 'Module is not enabled on this product.');

        $schema = $this->registry->configSchemaFor($moduleSlug);

        // Build validation rules from the config schema
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

        $raw = $request->input('config', []);

        // Unchecked checkboxes send no payload; persist them explicitly so
        // the stored config always reflects the current form state (mirrors
        // ModuleController::updateConfig).
        foreach ($schema['fields'] as $field) {
            if (($field['type'] ?? '') === 'checkbox' && ! array_key_exists($field['key'], $raw)) {
                $raw[$field['key']] = false;
            }
        }

        $encrypted = $this->registry->encryptConfigFor($moduleSlug, $raw);

        $pivot->update(['config' => $encrypted]);

        return redirect()
            ->route('admin.products.show', [$product, 'tab' => 'modules'])
            ->with('success', "Configuration saved for module {$target['name']}.");
    }

    public function updateAllowedTemplates(Product $product, string $moduleSlug, Request $request): RedirectResponse
    {
        abort_unless($moduleSlug === 'hyperv', 404, 'Only hyperv supports template restrictions.');

        $target = $this->resolveTarget($moduleSlug);
        abort_unless($target['exists'], 404, 'Module not found.');

        $pivot = ProductModule::query()
            ->where('product_id', $product->id)
            ->where('module_slug', $moduleSlug)
            ->first();

        abort_unless($pivot, 404, 'Module is not enabled on this product.');

        $validated = $request->validate([
            'allowed_templates' => ['nullable', 'array', 'max:50'],
            'allowed_templates.*' => ['string', 'max:64'],
        ]);

        $rawAllowed = $validated['allowed_templates'] ?? $request->input('allowed_templates');

        if ($rawAllowed === null) {
            $rawAllowed = [];
        }

        $sanitized = HypervTemplateCatalog::sanitizeAllowed($rawAllowed);

        if ($sanitized !== []) {
            $unionNames = HypervTemplateCatalog::unionNames();
            $unionSet = array_flip($unionNames);
            $unknown = [];
            foreach ($sanitized as $name) {
                if (! isset($unionSet[$name])) {
                    $unknown[] = $name;
                }
            }
            if ($unknown !== []) {
                return back()
                    ->withInput()
                    ->withErrors(['allowed_templates' => 'Unknown templates: '.implode(', ', $unknown).'. Allowed: '.implode(', ', $unionNames)]);
            }
        }

        $existingRaw = is_array($pivot->config) ? $pivot->config : [];
        $decrypted = $this->registry->decryptConfigFor($moduleSlug, $existingRaw);

        if ($sanitized === []) {
            unset($decrypted['allowed_templates']);
        } else {
            $decrypted['allowed_templates'] = $sanitized;
        }

        $encrypted = $this->registry->encryptConfigFor($moduleSlug, $decrypted);
        $pivot->update(['config' => $encrypted]);

        return redirect()
            ->route('admin.products.edit', [$product, 'tab' => 'modules'])
            ->with('success', 'Hyper-V template restriction saved.')
            ->with('active_tab', 'modules');
    }
}
