<?php

namespace App\Services\Inventory;

use App\Models\Device;
use App\Models\InventoryItem;
use App\Models\IpAddress;
use App\Models\PortConnection;
use App\Services\IpAssignmentService;
use Illuminate\Support\Facades\DB;

/**
 * The teardown of a device, shared by the admin panel and the API.
 *
 * A bare `$asset->delete()` leaves dangling state behind: leased IPs stay
 * marked as assigned to a vanished owner, cables keep pointing at ports of a
 * soft-deleted asset. Every linked IP is released through
 * IpAssignmentService (returning it to the pool
 * with a history row) and every cable is disconnected through
 * PortConnectionService (writing its ledger event) before the asset itself is
 * soft-deleted. The asset's own device_ports rows are intentionally left in
 * place, so the history survives. Items installed in the device are soft-
 * deleted too, with one inventory_item audit row per item.
 */
final class DeviceDeletionService
{
    public function __construct(
        private readonly IpAssignmentService $ipAssignmentService,
        private readonly PortConnectionService $portConnectionService,
    ) {}

    public function delete(Device $asset): void
    {
        DB::transaction(function () use ($asset): void {
            $this->releaseLinkedIps($asset);
            $this->disconnectPorts($asset);
            $this->deleteItems($asset);

            $asset->delete();
        });
    }

    /**
     * Soft-delete every item installed in the device and write one
     * inventory_item audit row per item (a snapshot, like the asset's own
     * "deleted" row). A failed audit write rolls the whole deletion back.
     */
    private function deleteItems(Device $asset): void
    {
        $items = InventoryItem::query()
            ->where('device_id', $asset->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $changeLog = app(InventoryChangeLogger::class);

        foreach ($items as $item) {
            $snapshot = $this->itemSnapshot($item);
            // InventoryItemObserver also records deletions; the snapshot writer
            // below stays the only source for this path so the fail-closed
            // rollback (throw → transaction aborts) cannot silently fall back
            // to the observer's best-effort security-channel-only handler.
            InventoryItem::withoutEvents(fn () => $item->delete());

            $written = $changeLog->record($item, 'deleted', ['snapshot' => $snapshot]);

            if (! $written) {
                throw new \RuntimeException("Audit log write failed for inventory item {$item->getKey()}; the device was not deleted.");
            }
        }
    }

    /**
     * Every non-null persisted attribute, minus the noisy ones and the
     * virtual generated column.
     *
     * @return array<string, mixed>
     */
    private function itemSnapshot(InventoryItem $item): array
    {
        $hidden = ['created_at', 'updated_at', 'deleted_at', 'live_item_asset_tag'];
        $snapshot = [];

        foreach ($item->getAttributes() as $field => $value) {
            if ($value === null || in_array($field, $hidden, true)) {
                continue;
            }

            $snapshot[$field] = $value;
        }

        return $snapshot;
    }

    /**
     * Return every IP linked to the asset to the available pool, each with its
     * own allocation-history row. Legacy rows that carry only device_id
     * are handled too, since the service clears that pointer either way.
     */
    private function releaseLinkedIps(Device $asset): void
    {
        IpAddress::query()
            ->where(function ($query) use ($asset): void {
                $query->where('device_id', $asset->getKey())
                    ->orWhere(function ($poly) use ($asset): void {
                        $poly->where('assigned_to_type', 'device')
                            ->where('assigned_to_id', $asset->getKey());
                    });
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->each(fn (IpAddress $ip) => $this->ipAssignmentService->releaseFromAsset($ip));
    }

    /**
     * Disconnect every cable with an endpoint on one of the asset's ports, so
     * the ledger records the disconnection (the PortConnection row is deleted).
     */
    private function disconnectPorts(Device $asset): void
    {
        $portIds = $asset->ports()->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($portIds === []) {
            return;
        }

        $connections = PortConnection::query()
            ->where(function ($query) use ($portIds): void {
                $query->whereIn('port_a_id', $portIds)->orWhereIn('port_b_id', $portIds);
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($connections as $connection) {
            $this->portConnectionService->disconnect($connection, auth()->user());
        }
    }
}
