<?php

namespace App\Services\Inventory;

use App\Models\AssetRelationship;
use App\Models\InventoryAsset;
use App\Models\IpAddress;
use App\Models\PortConnection;
use App\Services\IpAssignmentService;
use Illuminate\Support\Facades\DB;

/**
 * The teardown of an inventory asset, shared by the admin panel and the API.
 *
 * A bare `$asset->delete()` leaves dangling state behind: leased IPs stay
 * marked as assigned to a vanished owner, cables keep pointing at ports of a
 * soft-deleted asset, and the relationship graph keeps edges to it. Every
 * linked IP is released through IpAssignmentService (returning it to the pool
 * with a history row) and every cable is disconnected through
 * PortConnectionService (writing its ledger event) before the asset itself is
 * soft-deleted. The asset's own device_ports rows are intentionally left in
 * place, so the history survives.
 */
final class InventoryAssetDeletionService
{
    /**
     * The asset_relationships kind string inventory assets use. Relationships
     * are stored per kind pair, and inventory assets are one kind among several.
     */
    private const ASSET_KIND = 'inventory_asset';

    public function __construct(
        private readonly IpAssignmentService $ipAssignmentService,
        private readonly PortConnectionService $portConnectionService,
    ) {}

    public function delete(InventoryAsset $asset): void
    {
        DB::transaction(function () use ($asset): void {
            $this->releaseLinkedIps($asset);
            $this->disconnectPorts($asset);
            $this->deleteRelationships($asset);

            $asset->delete();
        });
    }

    /**
     * Return every IP linked to the asset to the available pool, each with its
     * own allocation-history row. Legacy rows that carry only inventory_asset_id
     * are handled too, since the service clears that pointer either way.
     */
    private function releaseLinkedIps(InventoryAsset $asset): void
    {
        IpAddress::query()
            ->where(function ($query) use ($asset): void {
                $query->where('inventory_asset_id', $asset->getKey())
                    ->orWhere(function ($poly) use ($asset): void {
                        $poly->where('assigned_to_type', 'inventory')
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
    private function disconnectPorts(InventoryAsset $asset): void
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

    /**
     * Remove every relationship edge where this asset is either the parent or
     * the child, matching on the inventory-asset kind string.
     */
    private function deleteRelationships(InventoryAsset $asset): void
    {
        AssetRelationship::query()
            ->where(function ($query) use ($asset): void {
                $query->where(function ($asParent) use ($asset): void {
                    $asParent->where('parent_kind', self::ASSET_KIND)
                        ->where('parent_id', $asset->getKey());
                })->orWhere(function ($asChild) use ($asset): void {
                    $asChild->where('child_kind', self::ASSET_KIND)
                        ->where('child_id', $asset->getKey());
                });
            })
            ->delete();
    }
}
