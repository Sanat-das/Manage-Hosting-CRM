<?php

namespace App\Services;

use App\Exceptions\NoAvailableIpException;
use App\Models\HostingAccount;
use App\Models\IpAddress;
use App\Models\IpAllocationHistory;
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
     * Lease the lowest-id available IP, optionally scoped to a subnet
     * and/or a datacenter (resolved through the owning subnet).
     *
     * @throws NoAvailableIpException when no free address exists in scope
     */
    public function assignNextAvailable(HostingAccount $account, ?int $subnetId = null, ?int $datacenterId = null): ?IpAddress
    {
        return DB::transaction(function () use ($account, $subnetId, $datacenterId) {
            $ip = IpAddress::query()
                ->whereNull('assigned_to_type')
                ->when($subnetId !== null, fn ($query) => $query->where('subnet_id', $subnetId))
                ->when($datacenterId !== null, fn ($query) => $query->whereHas('subnet', fn ($subnet) => $subnet->where('datacenter_id', $datacenterId)))
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

            return $this->lease($account, $ip, "Assigned IP {$ip->ip_address} to hosting account {$account->id}");
        });
    }

    /**
     * Release the IP currently leased to the account, if any.
     * No-op when the account holds no lease.
     */
    public function release(HostingAccount $account, ?string $reason = null): void
    {
        DB::transaction(function () use ($account, $reason) {
            $ip = IpAddress::query()
                ->where('assigned_to_type', HostingAccount::class)
                ->where('assigned_to_id', $account->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($ip === null) {
                return;
            }

            $snapshot = json_encode($ip->getAttributes());

            $ip->assigned_to_type = null;
            $ip->assigned_to_id = null;
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
        });
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
