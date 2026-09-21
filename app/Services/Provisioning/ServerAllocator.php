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
 * handed one server and talks to it.
 *
 * Selection order:
 *  1. servers in the product's server group, honouring the member `priority`
 *     column (lower first), then least-loaded within that priority tier,
 *     filtered by server_type matching the product's provisioning_module;
 *  2. failing that (no group, or every group server full/inactive/wrong type),
 *     any active server of the right server_type, least-loaded.
 *
 * A server at its `max_accounts` ceiling is skipped; `max_accounts = 0` means
 * unlimited. Group coherence (server_group.allowed_server_type == product
 * provisioning_module) is enforced at product save time, but allocation
 * double-checks so a legacy mismatch never provisions to the wrong infra.
 */
class ServerAllocator
{
    public function allocate(?Product $product, ?string $panelType = null): ?Server
    {
        $serverType = $this->resolveServerType($product, $panelType);

        if ($product?->server_group_id !== null) {
            $grouped = $this->fromGroup((int) $product->server_group_id, $serverType);

            if ($grouped !== null) {
                return $grouped;
            }
        }

        return $this->leastLoaded(
            Server::query()
                ->where('status', 'active')
                ->when($serverType !== null, fn ($q) => $q->where('server_type', $serverType))
                ->get()
        );
    }

    /**
     * Resolve the canonical server_type to filter by.
     * Explicit $panelType param wins (backward compat for callers passing
     * provisioning_module). Otherwise derive from product->provisioning_module
     * when it is a real automatable type; 'manual'/'custom' map to null => any.
     */
    private function resolveServerType(?Product $product, ?string $panelType): ?string
    {
        $raw = $panelType !== null && trim($panelType) !== '' ? trim($panelType) : null;

        if ($raw === null && $product !== null) {
            $raw = trim((string) ($product->provisioning_module ?? ''));
            if ($raw === '' || in_array($raw, ['manual', 'custom'], true)) {
                return null;
            }
        }

        if ($raw === null || $raw === '' || $raw === 'manual' || $raw === 'custom') {
            return null;
        }

        return $raw;
    }

    /**
     * Group members ordered by priority; the first one with capacity wins.
     * Ties on priority fall through to least-loaded so a group of equals is
     * still balanced rather than always hitting the lowest id.
     * Filters by server_type when a type is required.
     */
    private function fromGroup(int $groupId, ?string $serverType): ?Server
    {
        $members = ServerGroupMember::query()
            ->where('server_group_id', $groupId)
            ->orderBy('priority')
            ->with('server')
            ->get();

        $byPriority = $members
            ->filter(fn (ServerGroupMember $m) => $m->server !== null
                && $m->server->status === 'active'
                && ($serverType === null || ($m->server->server_type ?? $m->server->panel_type) === $serverType))
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
