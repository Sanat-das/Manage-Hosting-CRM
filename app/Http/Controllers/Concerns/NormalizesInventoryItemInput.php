<?php

namespace App\Http\Controllers\Concerns;

/**
 * Empty-string normalisation for inventory item input, shared by the admin
 * panel controller and the REST API so both persist '' as NULL for nullable
 * columns (asset_tag occupies a unique virtual index among live rows) and
 * both cast `discovered` to a real boolean.
 */
trait NormalizesInventoryItemInput
{
    /**
     * Convert empty submitted strings to null so nullable unique columns
     * (asset_tag) never collide on '' rows and live_item_asset_tag stays
     * NULL. `discovered` is normalised to a real boolean.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function normalizeInventoryItemEmpties(array $validated): array
    {
        foreach (['label', 'sku', 'part_number', 'serial_number', 'asset_tag', 'description', 'notes', 'parent_item_id', 'manufacturer_id'] as $field) {
            if (($validated[$field] ?? null) === '') {
                $validated[$field] = null;
            }
        }

        if (array_key_exists('discovered', $validated)) {
            $validated['discovered'] = filter_var($validated['discovered'], FILTER_VALIDATE_BOOLEAN);
        }

        return $validated;
    }
}
