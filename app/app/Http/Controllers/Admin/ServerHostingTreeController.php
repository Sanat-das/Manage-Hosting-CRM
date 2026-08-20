<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AssetRelationship;
use App\Models\Datacenter;
use App\Models\HostingAccount;
use App\Models\IpSubnet;
use App\Models\License;
use App\Models\Product;
use App\Models\Rack;
use App\Models\ResourcePool;
use App\Models\Server;
use App\Models\Vlan;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only "Server hosting tree" report — lists every asset_relationships
 * row where a given server is the parent, with the child entity's display
 * name resolved by child_kind. Supports CSV export via ?csv=1.
 */
class ServerHostingTreeController extends Controller
{
    /**
     * child_kind => [model class, display column].
     *
     * @var array<string, array{class-string, string}>
     */
    private const CHILD_MODELS = [
        'product' => [Product::class, 'name'],
        'server' => [Server::class, 'name'],
        'hosting_account' => [HostingAccount::class, 'username'],
        'datacenter' => [Datacenter::class, 'name'],
        'rack' => [Rack::class, 'name'],
        'ip_subnet' => [IpSubnet::class, 'name'],
        'vlan' => [Vlan::class, 'name'],
        'license' => [License::class, 'license_key'],
        'resource_pool' => [ResourcePool::class, 'name'],
    ];

    public function index(Request $request): View|StreamedResponse
    {
        $validated = $request->validate([
            'server_id' => ['required', 'integer', 'exists:servers,id'],
        ]);

        $server = Server::findOrFail($validated['server_id']);

        $relationships = AssetRelationship::query()
            ->where('parent_kind', 'server')
            ->where('parent_id', $server->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $childNames = $this->resolveChildNames($relationships);

        if ($request->boolean('csv')) {
            return $this->csvResponse($relationships, $childNames);
        }

        return view('admin.server-hosting-tree.index', [
            'server' => $server,
            'relationships' => $relationships,
            'childNames' => $childNames,
            'kinds' => AssetRelationship::ASSET_KINDS,
        ]);
    }

    /**
     * Resolve the display name of each relationship's child entity without
     * N+1 lookups: batch-fetch the child rows per kind, then map by
     * relationship id. Unresolvable children fall back to null.
     *
     * @param  Collection<int, AssetRelationship>  $relationships
     * @return array<int, string|null>
     */
    private function resolveChildNames(Collection $relationships): array
    {
        $idsByKind = [];
        foreach ($relationships as $relationship) {
            $idsByKind[$relationship->child_kind][(int) $relationship->child_id] = true;
        }

        $namesByKind = [];
        foreach ($idsByKind as $kind => $ids) {
            $config = self::CHILD_MODELS[$kind] ?? null;

            if ($config === null) {
                continue;
            }

            [$model, $column] = $config;

            foreach ($model::query()->whereIn('id', array_keys($ids))->get(['id', $column]) as $row) {
                $namesByKind[$kind][(int) $row->id] = (string) $row->{$column};
            }
        }

        $names = [];
        foreach ($relationships as $relationship) {
            $names[$relationship->id] = $namesByKind[$relationship->child_kind][(int) $relationship->child_id] ?? null;
        }

        return $names;
    }

    /**
     * @param  Collection<int, AssetRelationship>  $relationships
     * @param  array<int, string|null>  $childNames
     */
    private function csvResponse(Collection $relationships, array $childNames): StreamedResponse
    {
        return response()->streamDownload(function () use ($relationships, $childNames): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['relationship_type', 'child_kind', 'child_id', 'child_name', 'label', 'notes']);

            foreach ($relationships as $relationship) {
                fputcsv($handle, [
                    $relationship->relationship_type,
                    $relationship->child_kind,
                    $relationship->child_id,
                    $childNames[$relationship->id] ?? '',
                    $relationship->label ?? '',
                    $relationship->notes ?? '',
                ]);
            }

            fclose($handle);
        }, 'server-hosting-tree.csv', ['Content-Type' => 'text/csv']);
    }
}
