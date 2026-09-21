<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductOptionLinkRequest;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionGroupProduct;
use App\Services\ProductOptionLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Admin product option links (product ↔ option group attachments).
 *
 * Attaching a group to a product creates a `product_option_group_product`
 * pivot row and copies the group's catalog values + per-cycle pricing into
 * the product-scoped snapshot tables (product_option_link_values /
 * product_option_link_value_pricing) so the product owns a copy that can
 * diverge from the catalog group.
 */
class ProductOptionLinkController extends Controller
{
    public function __construct(private readonly ProductOptionLinkService $optionLinks)
    {
    }

    public function attach(ProductOptionLinkRequest $request, Product $product): RedirectResponse
    {
        $validated = $request->validated();
        $optionGroupId = (int) $validated['option_group_id'];

        // The pivot has a unique (product_id, option_group_id) constraint —
        // guard the duplicate up front so the user gets a flash, not a 500.
        $alreadyAttached = ProductOptionGroupProduct::query()
            ->where('product_id', $product->id)
            ->where('option_group_id', $optionGroupId)
            ->exists();

        if ($alreadyAttached) {
            return back()->with('error', 'Option group is already attached to this product.');
        }

        try {
            $link = DB::transaction(function () use ($product, $optionGroupId, $validated) {
                return $this->optionLinks->attachGroup(
                    $product,
                    ProductOptionGroup::query()->findOrFail($optionGroupId),
                    (bool) ($validated['customer_editable'] ?? false)
                );
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not attach option group: '.$e->getMessage()]);
        }

        $this->logActivity('option_group_attached', "Option group attached to product {$product->name}", [
            'product_id' => $product->id,
            'option_group_id' => $optionGroupId,
            'link_id' => $link->id,
        ]);

        // Warning only — do not block attach. Compute remaining missing keys for the
        // product's effective provisioning module and surface as a flash.
        $missing = $this->missingRequiredKeys($product->fresh()->load('optionLinks.group'));

        if (! empty($missing)) {
            return back()->with('warning', 'Option group attached. Still missing required option groups for '.$product->provisioning_module.': '.implode(', ', $missing).'. Attach groups with those keys to satisfy the provisioning module (warning only).');
        }

        return back()->with('success', 'Option group attached. All required options for '.$product->provisioning_module.' are now covered.');
    }

    public function destroy(Product $product, ProductOptionGroupProduct $link): RedirectResponse
    {
        $link->delete(); // FK cascade removes link values + pricing

        $this->logActivity('option_group_detached', "Option group detached from product {$product->name}", [
            'product_id' => $product->id,
            'link_id' => $link->id,
        ]);

        return back()->with('success', 'Option group detached.');
    }

    /**
     * Re-snapshot the product's option values from the catalog group, replacing
     * the stale copy (values added to / removed from the group after attach).
     *
     * Continuous groups are priced per unit and their (legacy) values are never
     * part of the per-product config, so there is nothing to sync — a wholesale
     * replace could destroy legacy rows.
     */
    public function sync(Product $product, ProductOptionGroupProduct $link): RedirectResponse
    {
        if (ProductOptionGroup::isContinuousType($link->group?->type)) {
            return back()->with('error', 'Continuous option groups use per-unit pricing and have no values to sync.');
        }

        $this->optionLinks->syncValuesFromGroup($link);

        $this->logActivity('option_group_synced', "Option group values synced from catalog on product {$product->name}", [
            'product_id' => $product->id,
            'link_id' => $link->id,
        ]);

        return back()->with('success', 'Option values synced from the group.');
    }

    /**
     * Missing required group keys for the product's provisioning module (warning only).
     *
     * Single source of truth is ModuleRequiredOptions::missingKeysFor().
     *
     * @return list<string>
     */
    private function missingRequiredKeys(Product $product): array
    {
        $attachedKeys = $product->optionLinks
            ->map(fn ($link) => strtolower(trim((string) ($link->group?->key ?? ''))))
            ->filter()
            ->values()
            ->all();

        if (class_exists(\App\Services\Provisioning\ModuleRequiredOptions::class)) {
            if (method_exists(\App\Services\Provisioning\ModuleRequiredOptions::class, 'missingKeysFor')) {
                return \App\Services\Provisioning\ModuleRequiredOptions::missingKeysFor(
                    (string) ($product->provisioning_module ?? ''),
                    $attachedKeys
                );
            }

            if (method_exists(\App\Services\Provisioning\ModuleRequiredOptions::class, 'missingKeys')) {
                return \App\Services\Provisioning\ModuleRequiredOptions::missingKeys($product);
            }
        }

        $module = strtolower(trim((string) ($product->provisioning_module ?? '')));

        $requiredByModule = [
            'hyperv' => ['cpu', 'ram', 'disk'],
            'virtualizor' => ['cpu', 'ram', 'disk'],
            'proxmox' => ['cpu', 'ram', 'disk'],
            'cpanel' => ['plan'],
            'plesk' => ['plan'],
            'directadmin' => ['plan'],
        ];

        $required = $requiredByModule[$module] ?? [];

        if ($required === []) {
            return [];
        }

        $attachedSet = array_flip($attachedKeys);

        return array_values(array_filter($required, fn (string $key) => ! isset($attachedSet[$key])));
    }

    /**
     * Write an admin activity-log entry (product-scoped, no customer).
     *
     * @param  array<string, mixed>  $metadata
     */
    private function logActivity(string $action, string $description, array $metadata = []): void
    {
        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'description' => $description,
            'metadata' => $metadata ?: null,
            'ip_address' => request()->ip(),
        ]);
    }
}
