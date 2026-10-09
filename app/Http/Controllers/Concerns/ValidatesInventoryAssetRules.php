<?php

namespace App\Http\Controllers\Concerns;

use App\Models\InventoryAsset;
use App\Models\Rack;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validation rules for inventory assets shared by the admin panel controller
 * and the REST API, so both agree on what a valid asset is. The base rules are
 * the ones the admin controller always used; the admin controller layers its
 * web-only IP-picker rules on top, while the API has no IP fields.
 */
trait ValidatesInventoryAssetRules
{
    /**
     * Base store rules, shared by the admin create form and the API store
     * endpoint.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function inventoryAssetStoreRules(Request $request): array
    {
        return [
            'asset_tag' => ['required', 'string', 'max:255', Rule::unique('inventory_assets', 'asset_tag')->whereNull('deleted_at')],
            'asset_type' => ['required', 'string', Rule::in(InventoryAsset::ASSET_TYPES)],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'datacenter_id' => ['nullable', 'integer', 'exists:datacenters,id'],
            'rack_id' => ['nullable', 'integer', 'exists:racks,id'],
            'rack_u_position' => $this->rackPositionRules($request),
            'purchase_date' => ['nullable', 'date'],
            'purchase_cost' => ['nullable', 'numeric', 'min:0'],
            'warranty_expiry' => ['nullable', 'date'],
            'status' => ['sometimes', 'string', Rule::in(InventoryAsset::STATUSES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Base update rules, shared by the admin edit form and the API update
     * endpoint. Every attribute is optional so a client can send only the
     * fields it wants to change.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function inventoryAssetUpdateRules(Request $request, InventoryAsset $asset): array
    {
        return [
            'asset_tag' => ['sometimes', 'string', 'max:255', Rule::unique('inventory_assets', 'asset_tag')->ignore($asset->id)->whereNull('deleted_at')],
            'asset_type' => ['sometimes', 'string', Rule::in(InventoryAsset::ASSET_TYPES)],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'purchase_date' => ['nullable', 'date'],
            'purchase_cost' => ['nullable', 'numeric', 'min:0'],
            'warranty_expiry' => ['nullable', 'date'],
            'datacenter_id' => ['nullable', 'integer', 'exists:datacenters,id'],
            'rack_id' => ['nullable', 'integer', 'exists:racks,id'],
            'rack_u_position' => $this->rackPositionRules($request, $asset),
            'status' => ['sometimes', 'string', Rule::in(InventoryAsset::STATUSES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Validation rules for rack_u_position, shared by store() and update().
     *
     * The exists:racks,id rule on rack_id already rejects a bogus rack, so this
     * closure reports only what that rule cannot see: a missing rack_id, a
     * position past the rack's height, and a position another live asset holds.
     * Soft-deleted assets are excluded by the model's global scope, so their
     * positions are reusable.
     *
     * @return array<int, mixed>
     */
    protected function rackPositionRules(Request $request, ?InventoryAsset $asset = null): array
    {
        return [
            'nullable',
            'integer',
            'min:1',
            function (string $attribute, mixed $value, Closure $fail) use ($request, $asset): void {
                if (! is_numeric($value)) {
                    return;
                }

                // A partial update may omit rack_id entirely, in which case the
                // asset's stored rack is the effective rack (Laravel's input()
                // default only applies when the key is ABSENT). An explicit
                // null/empty still means "clear the rack".
                $effectiveRackId = $request->input('rack_id', $asset?->rack_id);

                if (blank($effectiveRackId)) {
                    // Legacy rows may carry a U position with no rack (seeded or
                    // imported before the validation existed). Let the position
                    // through only when it is unchanged from the stored value;
                    // setting, moving, or orphaning a position still requires a
                    // rack.
                    $unchangedLegacyPosition = $asset !== null
                        && blank($asset->rack_id)
                        && (int) $value === (int) $asset->rack_u_position;

                    if (! $unchangedLegacyPosition) {
                        $fail('Select a rack before setting the U position.');
                    }

                    return;
                }

                $rack = Rack::find($effectiveRackId);
                if ($rack === null) {
                    return;
                }

                if ($value > $rack->u_height) {
                    $fail("The U position must not exceed the rack height of {$rack->u_height}.");

                    return;
                }

                $occupied = InventoryAsset::query()
                    ->where('rack_id', $rack->id)
                    ->where('rack_u_position', $value);
                if ($asset !== null) {
                    $occupied->where('id', '!=', $asset->getKey());
                }

                if ($occupied->exists()) {
                    $fail('That U position is already occupied in the selected rack.');
                }
            },
        ];
    }

    /**
     * Enforce rack-transition integrity on update, after validation has run.
     *
     * Only a request that explicitly sends a rack_id different from the stored
     * one is considered a move. Clearing to no rack drops a stale position, and
     * a move that carries an effective position into a new rack is rejected when
     * another live asset already holds it. Requests that leave rack_id alone
     * keep the legacy unchanged-position carve-out untouched. The (possibly
     * modified) $validated is updated in place so a cleared position persists.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException when the effective position is occupied
     */
    protected function validateRackTransition(InventoryAsset $asset, array &$validated): void
    {
        $request = request();

        if (! $request->has('rack_id') || (int) $request->input('rack_id') === (int) $asset->rack_id) {
            return;
        }

        $newRackId = $validated['rack_id'] ?? null;

        if ($newRackId === null) {
            if (! $request->has('rack_u_position')) {
                $validated['rack_u_position'] = null;
            }

            return;
        }

        $position = array_key_exists('rack_u_position', $validated)
            ? $validated['rack_u_position']
            : $asset->rack_u_position;

        if ($position === null) {
            return;
        }

        $occupied = InventoryAsset::query()
            ->where('rack_id', $newRackId)
            ->where('rack_u_position', $position)
            ->where('id', '!=', $asset->getKey())
            ->exists();

        if ($occupied) {
            throw ValidationException::withMessages([
                'rack_u_position' => 'That U position is already occupied in the selected rack.',
            ]);
        }
    }
}
