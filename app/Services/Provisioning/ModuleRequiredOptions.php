<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

use App\Models\Product;

/**
 * Per-module-type required option keys.
 *
 * Pivot `product_option_group_product.required` is checkout-required
 * (whether the order form blocks submit) — this map is module-required
 * (whether the provisioner can run without the key). Do not conflate.
 */
final class ModuleRequiredOptions
{
    /**
     * Canonical module type => required option keys (all lowercase).
     *
     * @var array<string, list<string>>
     */
    public const MAP = [
        'hyperv' => ['cpu', 'ram', 'disk'],
        'virtualizor' => ['cpu', 'ram', 'disk'],
        'proxmox' => ['cpu', 'ram', 'disk'],
        'cpanel' => ['plan'],
        'plesk' => ['plan'],
        'directadmin' => ['plan'],
        'ssh-console' => [],
        'rdp-console' => [],
        'snmp-monitor' => [],
        'manual' => [],
        'custom' => [],
    ];

    /**
     * Required keys for a single module slug.
     *
     * @return list<string>
     */
    public static function requiredFor(string $module): array
    {
        $key = strtolower(trim($module));

        return self::MAP[$key] ?? [];
    }

    /**
     * Required keys for a product (union over provisioning_module + enabled moduleLinks).
     *
     * @return list<string>
     */
    public static function requiredForProduct(?Product $product): array
    {
        if ($product === null) {
            return [];
        }

        $slugs = [];

        $provisioningModule = strtolower(trim((string) ($product->provisioning_module ?? '')));
        if ($provisioningModule !== '') {
            $slugs[] = $provisioningModule;
        }

        // Enabled module links (per-product wiring). Load relation if not already.
        $links = $product->relationLoaded('moduleLinks')
            ? $product->moduleLinks
            : $product->moduleLinks()->with('module')->get();

        foreach ($links as $link) {
            if (! $link->enabled) {
                continue;
            }

            $slug = $link->module_slug ?? null;
            if (is_string($slug) && trim($slug) !== '') {
                $slugs[] = strtolower(trim($slug));
            }
        }

        $slugs = array_values(array_unique($slugs));

        /** @var list<string> $required */
        $required = [];
        foreach ($slugs as $slug) {
            foreach (self::requiredFor($slug) as $key) {
                if (! in_array($key, $required, true)) {
                    $required[] = $key;
                }
            }
        }

        return $required;
    }

    /**
     * Keys required by the product's module type(s) that have no matching option group attached.
     *
     * Reads product->options keys (ProductOptionGroup.key) and the product's
     * provisioning_module + enabled moduleLinks. Comparison is case-insensitive
     * on canonical lowercase keys (cpu, ram, disk, bandwidth, plan).
     *
     * @return list<string>
     */
    public static function missingKeys(?Product $product): array
    {
        if ($product === null) {
            return [];
        }

        $required = self::requiredForProduct($product);

        if ($required === []) {
            return [];
        }

        $existing = $product->relationLoaded('options')
            ? $product->options
            : $product->options()->get();

        /** @var list<string> $existingKeys */
        $existingKeys = $existing
            ->map(fn ($group) => strtolower(trim((string) ($group->key ?? ''))))
            ->filter(fn ($k) => $k !== '')
            ->values()
            ->all();

        $missing = [];
        foreach ($required as $key) {
            if (! in_array($key, $existingKeys, true)) {
                $missing[] = $key;
            }
        }

        return array_values($missing);
    }

    /**
     * Missing keys for an explicit module slug + attached key list.
     *
     * Controller reuse point: ProductController/ProductOptionLinkController
     * call this with ($product->provisioning_module, $attachedKeys) to avoid
     * reloading relations. Comparison is case-insensitive.
     *
     * @param list<string> $attachedKeys
     * @return list<string>
     */
    public static function missingKeysFor(string $moduleSlug, array $attachedKeys): array
    {
        $required = self::requiredFor($moduleSlug);

        if ($required === []) {
            return [];
        }

        $set = [];
        foreach ($attachedKeys as $k) {
            $ck = strtolower(trim((string) $k));
            if ($ck !== '') {
                $set[$ck] = true;
            }
        }

        $missing = [];
        foreach ($required as $key) {
            if (! isset($set[$key])) {
                $missing[] = $key;
            }
        }

        return array_values($missing);
    }

    /**
     * Missing required keys when checked against a merged config array.
     *
     * A key is missing when it is absent or blank (empty string after trim).
     * Comparison is case-insensitive; canonical keys are lowercase.
     *
     * @param array<string, mixed> $config
     * @return list<string>
     */
    public static function missingConfigKeys(array $config, string $moduleSlug): array
    {
        $required = self::requiredFor($moduleSlug);

        if ($required === []) {
            return [];
        }

        // Normalize config to lowercase keys for case-insensitive check.
        /** @var array<string, mixed> $normalized */
        $normalized = [];
        foreach ($config as $k => $v) {
            $normalized[strtolower(trim((string) $k))] = $v;
        }

        $missing = [];
        foreach ($required as $key) {
            $value = $normalized[$key] ?? null;

            if ($value === null) {
                $missing[] = $key;
                continue;
            }

            if (is_string($value) && trim($value) === '') {
                $missing[] = $key;
                continue;
            }

            if (is_array($value) && $value === []) {
                $missing[] = $key;
            }
        }

        return array_values($missing);
    }
}
