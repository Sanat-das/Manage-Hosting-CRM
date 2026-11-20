<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Device;
use App\Models\DeviceType;
use App\Models\Rack;
use App\Services\Inventory\InventoryChangeLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validation rules for devices shared by the admin panel controller
 * and the REST API, so both agree on what a valid device is. The base rules are
 * the ones the admin controller always used; the admin controller layers its
 * web-only IP-picker rules on top, while the API has no IP fields.
 */
trait ValidatesDeviceRules
{
    /**
     * Base store rules, shared by the admin create form and the API store
     * endpoint.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function deviceStoreRules(Request $request): array
    {
        return [
            'asset_tag' => ['required', 'string', 'max:255', Rule::unique('devices', 'asset_tag')->whereNull('deleted_at')],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'device_type_id' => ['required', 'integer', 'exists:device_types,id'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['integer', 'exists:tags,id'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'datacenter_id' => ['nullable', 'integer', 'exists:datacenters,id'],
            'rack_id' => ['nullable', 'integer', 'exists:racks,id'],
            'rack_u_position' => $this->rackPositionRules($request),
            'face' => ['sometimes', 'string', 'in:front,rear'],
            'purchase_date' => ['nullable', 'date'],
            'purchase_cost' => ['nullable', 'numeric', 'min:0'],
            'warranty_expiry' => ['nullable', 'date'],
            'status' => ['sometimes', 'string', Rule::in(Device::STATUSES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Base update rules, shared by the admin edit form and the API update
     * endpoint. Every attribute is optional so a client can send only the
     * fields it wants to change — except device_type_id, which is required
     * (like NetBox's required Device Type) so a device never ends up without
     * a catalog classification.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function deviceUpdateRules(Request $request, Device $asset): array
    {
        return [
            'asset_tag' => ['sometimes', 'string', 'max:255', Rule::unique('devices', 'asset_tag')->ignore($asset->id)->whereNull('deleted_at')],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'device_type_id' => ['required', 'integer', 'exists:device_types,id'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['integer', 'exists:tags,id'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'purchase_date' => ['nullable', 'date'],
            'purchase_cost' => ['nullable', 'numeric', 'min:0'],
            'warranty_expiry' => ['nullable', 'date'],
            'datacenter_id' => ['nullable', 'integer', 'exists:datacenters,id'],
            'rack_id' => ['nullable', 'integer', 'exists:racks,id'],
            'rack_u_position' => $this->rackPositionRules($request, $asset),
            'face' => ['sometimes', 'string', 'in:front,rear'],
            'status' => ['sometimes', 'string', Rule::in(Device::STATUSES)],
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
    protected function rackPositionRules(Request $request, ?Device $asset = null): array
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

                $occupied = Device::query()
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
     * Write the chosen catalog device type through to the free-text
     * manufacturer and model columns, so the panel and the API agree on which
     * value wins. A blank or absent device_type_id is a no-op: manual
     * manufacturer/model entry keeps working (validation requires a type, so
     * this only guards defensive calls).
     *
     * @param  array<string, mixed>  $validated
     */
    protected function applyDeviceType(array &$validated): void
    {
        if (blank($validated['device_type_id'] ?? null)) {
            return;
        }

        $deviceType = DeviceType::query()
            ->with('manufacturer')
            ->find($validated['device_type_id']);

        if ($deviceType === null) {
            return;
        }

        $validated['manufacturer'] = $deviceType->manufacturer?->name;
        $validated['model'] = $deviceType->model;
    }

    /**
     * Sync the asset's tag assignments only when the caller submitted tag state:
     * the admin form's hidden `tags_submitted` marker, or an actual `tags`
     * array in the payload. A request with neither preserves the stored
     * assignments; an empty submitted set clears them. The marker is honoured
     * only on browser form posts, never on JSON API requests.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function syncAssetTags(Device $asset, array $validated, Request $request): void
    {
        $markerApplies = $request->boolean('tags_submitted') && ! $request->expectsJson();

        if (! $markerApplies && ! is_array($validated['tags'] ?? null)) {
            return;
        }

        $before = $asset->tags()->pluck('tags.id')->map(static fn ($id): int => (int) $id)->all();
        $after = is_array($validated['tags'] ?? null)
            ? array_values(array_map(static fn ($id): int => (int) $id, $validated['tags']))
            : [];

        $asset->tags()->sync($after);

        $written = app(InventoryChangeLogger::class)->recordTagChange($asset, $before, $after);

        if (! $written) {
            throw new \RuntimeException("Audit log write failed while recording the tag change for asset {$asset->getKey()}.");
        }
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
    protected function validateRackTransition(Device $asset, array &$validated): void
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

        $occupied = Device::query()
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
