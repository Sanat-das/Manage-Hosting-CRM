<?php

namespace App\Services;

use App\Exceptions\NoAvailableIpException;
use App\Models\HostingAccount;
use App\Models\InventoryAsset;
use App\Models\IpAddress;
use App\Models\IpAllocationHistory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Leases IP addresses from the existing IPAM pool to hosting accounts.
 *
 * An address is available while its `assigned_to_type` is NULL. Every
 * lease/release runs in a transaction and re-selects the target row under
 * a FOR UPDATE lock, so two concurrent leases cannot hand out the same
 * address. Every mutation is recorded in `ip_allocation_history` with a
 * JSON snapshot of the row as it was before the change.
 */
class IpAssignmentService
{
    /**
     * Lease the lowest-id available IP, optionally scoped to a subnet,
     * a datacenter (resolved through the owning subnet), and/or a subnet
     * network type (public / private / management / storage / dmz).
     *
     * Public vs. private is a property of the owning SUBNET (network_type),
     * not of the address row, so the network-type filter rides through the
     * subnet relation.
     *
     * @throws NoAvailableIpException when no free address exists in scope
     */
    public function assignNextAvailable(HostingAccount $account, ?int $subnetId = null, ?int $datacenterId = null, ?string $networkType = null): ?IpAddress
    {
        return DB::transaction(function () use ($account, $subnetId, $datacenterId, $networkType) {
            $ip = IpAddress::query()
                ->whereNull('assigned_to_type')
                ->where('type', 'available')
                ->when($subnetId !== null, fn ($query) => $query->where('subnet_id', $subnetId))
                ->when($datacenterId !== null, fn ($query) => $query->whereHas('subnet', fn ($subnet) => $subnet->where('datacenter_id', $datacenterId)))
                ->when($networkType !== null, fn ($query) => $query->whereHas('subnet', fn ($subnet) => $subnet->where('network_type', $networkType)))
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($ip === null) {
                throw new NoAvailableIpException('No available IP address found in the requested scope.');
            }

            return $this->lease($account, $ip, "Assigned next available IP {$ip->ip_address} to hosting account {$account->id}");
        });
    }

    /**
     * Lease the next N available IPs (up to $count) from the pool, each with
     * its own history row. Stops early when the pool runs out; throws when no
     * address could be assigned at all.
     *
     * @return Collection<int, IpAddress>
     *
     * @throws NoAvailableIpException when no free address exists in scope
     */
    public function assignNextAvailableMany(HostingAccount $account, int $count = 1, ?int $subnetId = null, ?int $datacenterId = null, ?string $networkType = null): Collection
    {
        $assigned = collect();

        for ($i = 0; $i < max(1, $count); $i++) {
            try {
                $assigned->push($this->assignNextAvailable($account, $subnetId, $datacenterId, $networkType));
            } catch (NoAvailableIpException) {
                break; // pool exhausted in scope
            }
        }

        if ($assigned->isEmpty()) {
            throw new NoAvailableIpException('No available IP address found in the requested scope.');
        }

        return $assigned;
    }

    /**
     * Lease a specific, currently unassigned IP to the account.
     *
     * @throws NoAvailableIpException when the address does not exist or is already assigned
     */
    public function assignSpecific(HostingAccount $account, int $ipAddressId): IpAddress
    {
        return DB::transaction(function () use ($account, $ipAddressId) {
            $ip = IpAddress::query()
                ->whereKey($ipAddressId)
                ->lockForUpdate()
                ->first();

            if ($ip === null) {
                throw new NoAvailableIpException("IP address {$ipAddressId} does not exist.");
            }

            if ($ip->assigned_to_type !== null) {
                throw new NoAvailableIpException("IP address {$ip->ip_address} is already assigned.");
            }

            // Mirror the safe assignNextAvailable() availability condition:
            // a special-type row (gateway / broadcast / network / reserved /
            // floating / nat) is not part of the leasable pool, and leasing it
            // would overwrite its type. Only an unassigned `available` row may
            // be leased.
            if ($ip->type !== 'available') {
                throw new NoAvailableIpException("IP address {$ip->ip_address} is not available for assignment.");
            }

            return $this->lease($account, $ip, "Assigned IP {$ip->ip_address} to hosting account {$account->id}");
        });
    }

    /**
     * Lease several specific IPs in one call. Each address is attempted
     * independently (one transaction each, under a row lock), so an
     * already-assigned address does not roll back the others.
     *
     * @param  list<int>  $ipAddressIds
     * @return array{assigned: Collection<int, IpAddress>, failed: array<int, string>} failed keyed by ip id
     */
    public function assignMany(HostingAccount $account, array $ipAddressIds): array
    {
        $assigned = collect();
        $failed = [];

        foreach ($ipAddressIds as $id) {
            try {
                $assigned->push($this->assignSpecific($account, $id));
            } catch (NoAvailableIpException $e) {
                $failed[$id] = $e->getMessage();
            }
        }

        return ['assigned' => $assigned, 'failed' => $failed];
    }

    /**
     * Release every IP currently leased to the account back to the pool.
     * No-op when the account holds no leases. An account can hold a public
     * and a private lease at once (products with both flags), so all of
     * them are released, each with its own history row. Pass $ipAddressId
     * to release a single lease instead.
     */
    public function release(HostingAccount $account, ?string $reason = null, ?int $ipAddressId = null): void
    {
        DB::transaction(function () use ($account, $reason, $ipAddressId) {
            $ips = IpAddress::query()
                ->where('assigned_to_type', HostingAccount::class)
                ->where('assigned_to_id', $account->id)
                ->when($ipAddressId !== null, fn ($query) => $query->where('id', $ipAddressId))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($ips as $ip) {
                $snapshot = json_encode($ip->getAttributes());

                $ip->assigned_to_type = null;
                $ip->assigned_to_id = null;
                $ip->type = 'available';
                $ip->save();

                $this->writeHistory(
                    $ip,
                    'released',
                    HostingAccount::class,
                    $account->id,
                    null,
                    null,
                    $snapshot,
                    $reason ?? "Released IP {$ip->ip_address} from hosting account {$account->id}",
                );
            }
        });
    }

    /**
     * Link an IP address to an inventory asset through the polymorphic
     * assignment columns, so IPAM reads the address as assigned and the
     * hosting leasing pool (scopeAvailable) skips it.
     *
     * The row is re-selected under a FOR UPDATE lock inside a transaction.
     * A conflicting owner throws; re-linking the same asset is a no-op so a
     * re-saved picker chip does not write a duplicate history row. The
     * `type` column is promoted to assigned only from available, preserving
     * reserved / gateway / etc.
     */
    public function assignToAsset(IpAddress $ip, InventoryAsset $asset): void
    {
        DB::transaction(function () use ($ip, $asset): void {
            $fresh = IpAddress::query()->whereKey($ip->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->assigned_to_type !== null
                && ! ($fresh->assigned_to_type === 'inventory' && (int) $fresh->assigned_to_id === (int) $asset->id)) {
                throw new \RuntimeException(
                    $this->linkConflictMessage($fresh, (int) $asset->id)
                        ?? "IP {$fresh->ip_address} is already assigned.",
                );
            }

            if ($fresh->assigned_to_type === 'inventory'
                && (int) $fresh->assigned_to_id === (int) $asset->id
                && (int) $fresh->inventory_asset_id === (int) $asset->id) {
                return;
            }

            $snapshot = json_encode($fresh->getAttributes());
            $previousType = $fresh->assigned_to_type;
            $previousId = $fresh->assigned_to_id;

            $fresh->assigned_to_type = 'inventory';
            $fresh->assigned_to_id = $asset->id;
            $fresh->inventory_asset_id = $asset->id;

            if ($fresh->type === 'available') {
                $fresh->type = 'assigned';
            }

            $fresh->save();

            $this->writeHistory(
                $fresh,
                'assigned',
                $previousType,
                $previousId,
                'inventory',
                $asset->id,
                $snapshot,
                "Assigned IP {$fresh->ip_address} to asset {$asset->asset_tag}",
            );
        });
    }

    /**
     * Unlink an inventory asset from an IP address, clearing the polymorphic
     * assignment when it points at inventory and returning the address to the
     * pool. A no-op when nothing is linked, so it is safe to call for every
     * row that is no longer chosen.
     */
    public function releaseFromAsset(IpAddress $ip, ?string $reason = null): void
    {
        DB::transaction(function () use ($ip, $reason): void {
            $fresh = IpAddress::query()->whereKey($ip->getKey())->lockForUpdate()->firstOrFail();

            $wasInventory = $fresh->assigned_to_type === 'inventory';

            if (! $wasInventory && $fresh->inventory_asset_id === null) {
                return;
            }

            $tag = $fresh->inventory_asset_id !== null
                ? InventoryAsset::query()->whereKey($fresh->inventory_asset_id)->value('asset_tag')
                : null;

            $snapshot = json_encode($fresh->getAttributes());
            $previousType = $fresh->assigned_to_type;
            $previousId = $fresh->assigned_to_id;
            $assetLabel = $tag ?? $fresh->inventory_asset_id ?? $previousId ?? $fresh->id;

            $fresh->inventory_asset_id = null;

            if ($wasInventory) {
                $fresh->assigned_to_type = null;
                $fresh->assigned_to_id = null;
            }

            if ($fresh->assigned_to_type === null && $fresh->type === 'assigned') {
                $fresh->type = 'available';
            }

            $fresh->save();

            $this->writeHistory(
                $fresh,
                'released',
                $previousType,
                $previousId,
                null,
                null,
                $snapshot,
                $reason ?? "Unlinked IP {$fresh->ip_address} from asset {$assetLabel}",
            );
        });
    }

    /**
     * Human-readable reason the given IP cannot be linked to the asset, or
     * null when it can. Shared by the inventory-asset and IP form validation
     * closures so both reject the same conflicts with the same wording.
     */
    public function linkConflictMessage(IpAddress $ip, int $assetId): ?string
    {
        $type = $ip->assigned_to_type;

        if ($type === 'inventory') {
            return (int) $ip->assigned_to_id === $assetId
                ? null
                : 'This IP is already linked to another asset.';
        }

        if ($ip->inventory_asset_id !== null) {
            return (int) $ip->inventory_asset_id === $assetId
                ? null
                : 'This IP is already linked to another asset.';
        }

        if ($type !== null && $type !== '') {
            return 'This IP is already assigned to '.$this->assignmentLabel($type).'.';
        }

        return null;
    }

    /**
     * The readable owner kind for a stored morph value (short code or the
     * HostingAccount class-string).
     */
    private function assignmentLabel(string $type): string
    {
        return match ($type) {
            HostingAccount::class => 'hosting account',
            'customer' => 'customer',
            'server' => 'server',
            'service' => 'service',
            default => class_basename($type),
        };
    }

    /**
     * Perform the lease mutation plus its history row. Caller holds the
     * row lock and runs inside the wrapping transaction.
     */
    private function lease(HostingAccount $account, IpAddress $ip, string $notes): IpAddress
    {
        $snapshot = json_encode($ip->getAttributes());

        $ip->assigned_to_type = HostingAccount::class;
        $ip->assigned_to_id = $account->id;
        $ip->type = 'assigned';
        $ip->save();

        $this->writeHistory(
            $ip,
            'assigned',
            null,
            null,
            HostingAccount::class,
            $account->id,
            $snapshot,
            $notes,
        );

        return $ip;
    }

    private function writeHistory(
        IpAddress $ip,
        string $action,
        ?string $previousType,
        ?int $previousId,
        ?string $newType,
        ?int $newId,
        string $snapshot,
        string $notes,
    ): void {
        IpAllocationHistory::create([
            'ip_address_id' => $ip->id,
            'action' => $action,
            'previous_assigned_to_type' => $previousType,
            'previous_assigned_to_id' => $previousId,
            'new_assigned_to_type' => $newType,
            'new_assigned_to_id' => $newId,
            'changed_by_user_id' => auth()->id(),
            'ip_address_snapshot' => $snapshot,
            'changed_at' => now(),
            'notes' => $notes,
        ]);
    }
}
