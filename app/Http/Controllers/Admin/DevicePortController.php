<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DevicePort;
use App\Models\InventoryAsset;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DevicePortController extends Controller
{
    /**
     * Typeahead search for the peer-port picker, used by the connect flow.
     *
     * Defaults to free ports only (no cable in either direction); pass
     * include_connected=1 to also return connected ports, ordered free first and
     * annotated with their peer. A blank query returns an empty result set
     * without touching the database, mirroring InventoryAssetController::search().
     * The label carries the owning asset tag and the port name; the meta line
     * sums up what a maintainer needs to tell two ports apart, ending with the
     * connection annotation for a cabled port. Every result carries a boolean
     * `disabled` flag — true for connected ports, which the picker renders
     * unselectable because a port holds at most one cable.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'exclude_port_id' => ['nullable', 'integer', 'min:1'],
            'exclude_asset_id' => ['nullable', 'integer', 'min:1'],
            'include_connected' => ['nullable', 'boolean'],
        ]);

        $q = trim((string) ($validated['q'] ?? ''));
        if ($q === '') {
            return response()->json(['results' => []]);
        }

        $like = '%'.$q.'%';
        $excludePortId = $validated['exclude_port_id'] ?? null;
        $excludeAssetId = $validated['exclude_asset_id'] ?? null;
        $includeConnected = $request->boolean('include_connected');

        $ports = DevicePort::query()
            ->with([
                'inventoryAsset:id,asset_tag,datacenter_id,rack_id',
                'inventoryAsset.datacenter:id,name',
                'inventoryAsset.rack:id,name',
                'inventoryAsset.ipAddresses:id,inventory_asset_id,ip_address',
                'connectionAsA.portB.inventoryAsset:id,asset_tag',
                'connectionAsB.portA.inventoryAsset:id,asset_tag',
            ])
            ->when(
                ! $includeConnected,
                fn (Builder $query) => $query->whereDoesntHave('connectionAsA')->whereDoesntHave('connectionAsB'),
            )
            ->when(
                $excludePortId !== null,
                fn (Builder $query) => $query->whereKeyNot((int) $excludePortId),
            )
            ->when(
                $excludeAssetId !== null,
                fn (Builder $query) => $query->where('inventory_asset_id', '<>', (int) $excludeAssetId),
            )
            ->where(function (Builder $query) use ($like): void {
                $query->where('name', 'like', $like)
                    ->orWhere('port_number', 'like', $like)
                    ->orWhere('mac_address', 'like', $like)
                    ->orWhereHas('inventoryAsset', fn (Builder $asset) => $asset->where('asset_tag', 'like', $like));
            })
            ->orderBy('name')
            ->limit(20)
            ->get();

        if ($includeConnected) {
            [$free, $connected] = $ports->partition(
                fn (DevicePort $port): bool => $port->connectionAsA === null && $port->connectionAsB === null,
            );
            $ports = $free->concat($connected)->values();
        }

        return response()->json([
            'results' => $ports->map(function (DevicePort $port): array {
                $isConnected = $port->connectionAsA !== null || $port->connectionAsB !== null;

                return [
                    'id' => $port->id,
                    'label' => $this->searchResultLabel($port),
                    'meta' => $this->searchResultMeta($port),
                    'disabled' => $isConnected,
                ];
            })->all(),
        ]);
    }

    public function store(Request $request, InventoryAsset $inventoryAsset): RedirectResponse
    {
        $validated = $this->validatePort($request, $inventoryAsset);

        $inventoryAsset->ports()->create($validated + [
            'source' => 'manual',
        ]);

        return back()->with('success', 'Port added.');
    }

    public function update(Request $request, InventoryAsset $inventoryAsset, DevicePort $devicePort): RedirectResponse
    {
        abort_unless((int) $devicePort->inventory_asset_id === (int) $inventoryAsset->id, 404);

        $validated = $this->validatePort($request, $inventoryAsset, $devicePort);

        // source and the SNMP linkage columns are owned by the importer and are
        // never touched by a manual edit.
        $devicePort->update($validated);

        return back()->with('success', 'Port updated.');
    }

    public function destroy(InventoryAsset $inventoryAsset, DevicePort $devicePort): RedirectResponse
    {
        abort_unless((int) $devicePort->inventory_asset_id === (int) $inventoryAsset->id, 404);

        if ($devicePort->connectionAsA()->exists() || $devicePort->connectionAsB()->exists()) {
            return back()->withErrors(['delete' => 'Disconnect the cable first.']);
        }

        $devicePort->delete();

        return back()->with('success', 'Port deleted.');
    }

    /**
     * Validation shared by store() and update().
     *
     * The name is unique per asset; on update the row itself is ignored. The MAC
     * is normalised to the UPPER colon-separated form the schema stores, and an
     * empty value becomes null.
     *
     * @return array<string, mixed>
     */
    private function validatePort(Request $request, InventoryAsset $inventoryAsset, ?DevicePort $devicePort = null): array
    {
        $uniqueName = Rule::unique('device_ports', 'name')
            ->where('inventory_asset_id', $inventoryAsset->id);
        if ($devicePort !== null) {
            $uniqueName->ignore($devicePort->id);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', $uniqueName],
            'port_number' => ['nullable', 'string', 'max:50'],
            'port_type' => ['required', 'string', Rule::in(DevicePort::PORT_TYPES)],
            'media' => ['nullable', 'string', Rule::in(DevicePort::MEDIA)],
            'speed_bps' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', Rule::in(DevicePort::STATUSES)],
            'mac_address' => ['nullable', 'string', 'max:17', ...$this->macAddressRule()],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $validated['status'] = $validated['status'] ?? 'unknown';
        $validated['mac_address'] = $this->normalizeMac($validated['mac_address'] ?? null);

        return $validated;
    }

    /**
     * A MAC is accepted in any common notation as long as it carries exactly
     * twelve hexadecimal digits; normalizeMac() then stores the canonical form.
     *
     * @return array<int, mixed>
     */
    private function macAddressRule(): array
    {
        return [
            function (string $attribute, mixed $value, Closure $fail): void {
                if ($value === null || $value === '') {
                    return;
                }

                if (strlen((string) preg_replace('/[^0-9A-Fa-f]/', '', (string) $value)) !== 12) {
                    $fail('The MAC address must contain 12 hexadecimal digits.');
                }
            },
        ];
    }

    private function normalizeMac(?string $mac): ?string
    {
        if ($mac === null || trim($mac) === '') {
            return null;
        }

        $hex = strtoupper((string) preg_replace('/[^0-9A-Fa-f]/', '', $mac));

        return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : $hex;
    }

    private function searchResultLabel(DevicePort $port): string
    {
        $tag = $port->inventoryAsset?->asset_tag;

        return ($tag !== null && $tag !== '') ? $tag.' · '.$port->name : $port->name;
    }

    /**
     * The compact one-line summary shown under a port's label in the picker:
     * type · status · mac · rack/datacenter · device IP. Empty parts are dropped.
     * A connected port appends `connected to <peer asset tag> <peer port name>`,
     * falling back to a bare `connected` when the peer is unavailable.
     */
    private function searchResultMeta(DevicePort $port): string
    {
        $asset = $port->inventoryAsset;

        $location = $asset !== null
            ? trim(implode(' / ', array_filter([
                (string) $asset->rack?->name,
                (string) $asset->datacenter?->name,
            ], fn (string $part): bool => trim($part) !== '')))
            : '';

        $parts = array_filter([
            (string) $port->port_type,
            (string) $port->status,
            (string) $port->mac_address,
            $location,
            (string) ($asset?->ipAddresses->first()?->ip_address),
        ], fn (string $part): bool => trim($part) !== '');

        if ($port->connectionAsA !== null || $port->connectionAsB !== null) {
            $peer = $port->connectionAsA?->portB ?? $port->connectionAsB?->portA;
            $peerTag = $peer?->inventoryAsset?->asset_tag;

            $parts[] = ($peer !== null && $peerTag !== null && $peerTag !== '')
                ? 'connected to '.$peerTag.' '.$peer->name
                : 'connected';
        }

        return implode(' · ', $parts);
    }
}
