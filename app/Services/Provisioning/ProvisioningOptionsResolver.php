<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

use App\Models\Order;

/**
 * Resolves VM resource values (cpu/ram/disk/…) from an order's Configuration
 * Options snapshot.
 *
 * WHY this exists: the product_module link config holds fallback defaults,
 * but the customer actually chose concrete resources at order time. Those
 * choices are snapshotted onto order_items.config_options['options'] and must
 * win over the link config (order snapshot > link config > module defaults).
 * Fixed (customer_editable=0) continuous groups snapshot as human strings
 * like "1024 MB", hence the leading-number parser.
 */
final class ProvisioningOptionsResolver
{
    /**
     * Canonical resource keys this resolver may override.
     *
     * @var list<string>
     */
    public const RESOURCE_KEYS = ['cpu', 'ram', 'disk', 'bandwidth', 'plan'];

    /**
     * Keys whose snapshot values are parsed down to a leading number.
     *
     * @var list<string>
     */
    private const NUMERIC_KEYS = ['cpu', 'ram', 'disk', 'bandwidth'];

    /**
     * Flatten an order's item option snapshots to key => value.
     *
     * First value wins per key. Numeric keys store the parsed number when the
     * snapshot string starts with one (e.g. "1024 MB" => 1024), otherwise the
     * raw scalar is kept so nothing is silently dropped.
     *
     * @return array<string, int|float|string>
     */
    public static function fromOrder(?Order $order): array
    {
        if ($order === null) {
            return [];
        }

        // Items may not be eager-loaded on hosting-account flows.
        $items = $order->relationLoaded('items') ? $order->items : $order->loadMissing('items')->items;

        /** @var array<string, int|float|string> $resolved */
        $resolved = [];

        foreach ($items as $item) {
            $raw = $item->config_options ?? null;

            if (! is_array($raw)) {
                continue;
            }

            $options = $raw['options'] ?? null;

            if (! is_array($options)) {
                continue;
            }

            foreach ($options as $option) {
                if (! is_array($option)) {
                    continue;
                }

                $rawKey = $option['key'] ?? null;

                if (! is_string($rawKey) && ! is_int($rawKey)) {
                    continue;
                }

                $key = strtolower(trim((string) $rawKey));

                if ($key === '') {
                    continue;
                }

                $selected = $option['selected'] ?? null;

                if ($selected === null || is_array($selected)) {
                    continue;
                }

                if (! is_scalar($selected)) {
                    continue;
                }

                if (array_key_exists($key, $resolved)) {
                    continue;
                }

                if (in_array($key, self::NUMERIC_KEYS, true)) {
                    $parsed = self::parseNumeric($selected);
                    $resolved[$key] = $parsed ?? $selected;
                } else {
                    $resolved[$key] = $selected;
                }
            }
        }

        return $resolved;
    }

    /**
     * Extract the leading number from a human snapshot value.
     *
     * "2 Cores" => 2, "1024 MB" => 1024, "20 GB" => 20, "1.5" => 1.5,
     * 2 => 2, "abc" => null, "" => null. Whole numbers return int so the
     * WinRM scripts render "$cpu = 2" rather than "$cpu = 2.0".
     */
    public static function parseNumeric(mixed $value): int|float|null
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if (preg_match('/-?\d+(?:\.\d+)?/', $text, $matches) !== 1) {
            return null;
        }

        $number = $matches[0];

        if (str_contains($number, '.')) {
            return (float) $number;
        }

        return (int) $number;
    }

    /**
     * Overlay resolved resource values onto a module config array.
     *
     * Only RESOURCE_KEYS present in $resourceValues are overwritten, so
     * unrelated link config (switch, generation, templates, …) survives.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, int|float|string>  $resourceValues
     * @return array<string, mixed>
     */
    public static function applyToConfig(array $config, array $resourceValues): array
    {
        foreach (self::RESOURCE_KEYS as $key) {
            if (array_key_exists($key, $resourceValues)) {
                $config[$key] = $resourceValues[$key];
            }
        }

        return $config;
    }
}
