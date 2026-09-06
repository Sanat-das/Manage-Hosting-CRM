<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

use App\Models\Product;
use App\Models\Server;
use App\Models\ServerGroupMember;
use App\Models\ServiceInstance;
use Illuminate\Support\Collection;

/**
 * Picks the server a service will be provisioned onto.
 *
 * Server choice is core's job, not the module's: a provisioning module is
 * handed one server and talks to it. Nothing in the app did this before —
 * `products.server_group_id` and `server_group_members.priority` existed but
 * were never read at provisioning time — so services had no server and a
 * module would have had nothing to connect to.
 *
 * Selection order:
 *  1. servers in the product's server group, honouring the member `priority`
 *     column (lower first), then least-loaded;
 *  2. failing that (no group, or every group server full/inactive/wrong panel),
 *     any active server of the right panel type, least-loaded.
 *
 * A server at its `max_accounts` ceiling is skipped; `max_accounts = 0` means
 * unlimited, which is the column's documented default.
 */
class ServerAllocator
{
    public function allocate(?Product $product, ?string $panelType = null): ?Server
    {
        $panelType = $panelType !== null && $panelType !== '' ? $panelType : null;

        if ($product?->server_group_id !== null) {
            $grouped = $this->fromGroup((int) $product->server_group_id, $panelType);

            if ($grouped !== null) {
                return $grouped;
            }
        }

        return $this->leastLoaded(
            Server::query()
                ->where('status', 'active')
                ->when($panelType !== null, fn ($q) => $q->where('panel_type', $panelType))
                ->get()
        );
    }

    /**
     * Group members ordered by priority; the first one with capacity wins.
     * Ties on priority fall through to least-loaded so a group of equals is
     * still balanced rather than always hitting the lowest id.
     */
    private function fromGroup(int $groupId, ?string $panelType): ?Server
    {
        $members = ServerGroupMember::query()
            ->where('server_group_id', $groupId)
            ->orderBy('priority')
            ->with('server')
            ->get();

        $byPriority = $members
            ->filter(fn (ServerGroupMember $m) => $m->server !== null
                && $m->server->status === 'active'
                && ($panelType === null || $m->server->panel_type === $panelType))
            ->groupBy('priority');

        foreach ($byPriority as $tier) {
            $candidate = $this->leastLoaded($tier->map(fn (ServerGroupMember $m) => $m->server));

            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The server with the most headroom, skipping any at its ceiling.
     *
     * @param  Collection<int, Server>  $servers
     */
    private function leastLoaded($servers): ?Server
    {
        return $servers
            ->filter(fn (Server $server) => $this->hasCapacity($server))
            ->sortBy(fn (Server $server) => $this->load($server))
            ->first();
    }

    private function hasCapacity(Server $server): bool
    {
        $max = (int) $server->max_accounts;

        return $max === 0 || $this->load($server) < $max;
    }

    /**
     * Accounts already on the server. Both tables are counted: hosting_accounts
     * is the storefront's record and service_instances is what modules
     * provision against — one order produces a row in each, so counting only
     * one would under-report by half on a mixed estate.
     */
    private function load(Server $server): int
    {
        return $server->hostingAccounts()->count()
            + ServiceInstance::query()
                ->where('server_id', $server->id)
                ->whereIn('status', ['pending', 'provisioning', 'active', 'suspended'])
                ->count();
    }
}
