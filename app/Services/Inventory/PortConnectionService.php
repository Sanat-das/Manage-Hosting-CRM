<?php

namespace App\Services\Inventory;

use App\Exceptions\PortConnectionConflictException;
use App\Models\DevicePort;
use App\Models\PortConnection;
use App\Models\PortConnectionEvent;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Writes the one-cable-per-port connections and the append-only audit ledger.
 *
 * Every mutation runs inside a transaction and re-selects the affected port
 * rows under a FOR UPDATE lock (mirroring IpAssignmentService), so two
 * concurrent connects for the same port cannot both pass the free-port check.
 * The model's saving hook is the second layer of the invariant; the DB uniques
 * are the third.
 */
final class PortConnectionService
{
    /**
     * The connection columns that make up the cable metadata — the only fields
     * connect() and updateCable() may write, and the ones snapshotted to the
     * ledger.
     */
    private const CABLE_FIELDS = ['cable_label', 'cable_type', 'cable_length_m', 'cable_color', 'notes'];

    public function connect(DevicePort $source, DevicePort $peer, array $cable = [], ?User $actor = null): PortConnection
    {
        return DB::transaction(function () use ($source, $peer, $cable, $actor): PortConnection {
            $sourceId = (int) $source->getKey();
            $peerId = (int) $peer->getKey();

            if ($sourceId === $peerId) {
                throw new PortConnectionConflictException('A port cannot be connected to itself.');
            }

            /** @var Collection<int, DevicePort> $locked */
            $locked = DevicePort::query()
                ->with('inventoryAsset')
                ->whereKey([$sourceId, $peerId])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lockedSource = $locked->get($sourceId);
            $lockedPeer = $locked->get($peerId);

            if ($lockedSource === null || $lockedPeer === null) {
                throw new PortConnectionConflictException('One of the selected ports no longer exists.');
            }

            // Canonical order: the smaller id is always port A, so a reversed
            // duplicate of the same cable is impossible and display is stable.
            [$portA, $portB] = $sourceId < $peerId
                ? [$lockedSource, $lockedPeer]
                : [$lockedPeer, $lockedSource];

            $taken = PortConnection::query()
                ->where(function ($query) use ($sourceId, $peerId): void {
                    $query->whereIn('port_a_id', [$sourceId, $peerId])
                        ->orWhereIn('port_b_id', [$sourceId, $peerId]);
                })
                ->exists();

            if ($taken) {
                throw new PortConnectionConflictException('One of the selected ports already has a cable.');
            }

            $connection = PortConnection::create(
                Arr::only($cable, self::CABLE_FIELDS) + [
                    'port_a_id' => $portA->getKey(),
                    'port_b_id' => $portB->getKey(),
                ],
            );

            $this->writeEvent($portA, $portB, 'connected', $connection, $actor);

            return $connection;
        });
    }

    public function updateCable(PortConnection $connection, array $cable, ?User $actor = null): PortConnection
    {
        return DB::transaction(function () use ($connection, $cable, $actor): PortConnection {
            $fresh = PortConnection::query()->whereKey($connection->getKey())->lockForUpdate()->firstOrFail();

            $fresh->fill(Arr::only($cable, self::CABLE_FIELDS))->save();

            $portA = DevicePort::query()->with('inventoryAsset')->findOrFail($fresh->port_a_id);
            $portB = DevicePort::query()->with('inventoryAsset')->findOrFail($fresh->port_b_id);

            $this->writeEvent($portA, $portB, 'cable_updated', $fresh, $actor);

            return $fresh;
        });
    }

    public function disconnect(PortConnection $connection, ?User $actor = null): void
    {
        DB::transaction(function () use ($connection, $actor): void {
            $fresh = PortConnection::query()->whereKey($connection->getKey())->lockForUpdate()->firstOrFail();

            $portA = DevicePort::query()->with('inventoryAsset')->findOrFail($fresh->port_a_id);
            $portB = DevicePort::query()->with('inventoryAsset')->findOrFail($fresh->port_b_id);

            // The ledger keeps the connection id even though the row is about to
            // be deleted (the events table has no foreign key).
            $this->writeEvent($portA, $portB, 'disconnected', $fresh, $actor);

            $fresh->delete();
        });
    }

    /**
     * Append one ledger row, snapshotting the endpoint asset tags and port
     * names so the history survives the ports and assets themselves.
     */
    private function writeEvent(
        DevicePort $portA,
        DevicePort $portB,
        string $action,
        PortConnection $connection,
        ?User $actor,
    ): void {
        PortConnectionEvent::create([
            'port_connection_id' => $connection->getKey(),
            'port_a_id' => $portA->getKey(),
            'port_b_id' => $portB->getKey(),
            'a_asset_tag' => (string) ($portA->inventoryAsset?->asset_tag ?? ''),
            'a_port_name' => (string) $portA->name,
            'b_asset_tag' => (string) ($portB->inventoryAsset?->asset_tag ?? ''),
            'b_port_name' => (string) $portB->name,
            'action' => $action,
            'cable_label' => $connection->cable_label,
            'cable_type' => $connection->cable_type,
            'cable_length_m' => $connection->cable_length_m,
            'cable_color' => $connection->cable_color,
            'changed_by_user_id' => $actor?->id ?? auth()->id(),
            'changed_at' => now(),
            'notes' => $connection->notes,
        ]);
    }
}
