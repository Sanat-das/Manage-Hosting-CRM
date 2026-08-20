<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AssetRelationship;
use App\Models\Datacenter;
use App\Models\HostingAccount;
use App\Models\IpSubnet;
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
 * Read-only admin report: "product hosted-on product". Lists every
 * asset_relationships row where a product is the child of a `hosted_on`
 * link and shows the parent assets. No write/update/delete endpoints.
 */
class ProductHostedOnController extends Controller
{
    /**
     * Map of asset kind => [model class, display column] used to resolve a
     * relationship's parent entity to its display name. Kinds without a
     * resolvable name (e.g. license) fall back to null.
     */
    private const PARENT_DISPLAY = [
        'product' => [Product::class, 'name'],
        'server' => [Server::class, 'name'],
        'hosting_account' => [HostingAccount::class, 'username'],
        'datacenter' => [Datacenter::class, 'name'],
        'rack' => [Rack::class, 'name'],
        'ip_subnet' => [IpSubnet::class, 'name'],
        'vlan' => [Vlan::class, 'name'],
        'resource_pool' => [ResourcePool::class, 'name'],
    ];

    public function index(Request $request): View|StreamedResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
        ]);

        $product = Product::findOrFail((int) $validated['product_id']);

        $relationships = AssetRelationship::query()
            ->where('child_kind', 'product')
            ->where('child_id', $product->id)
            ->where('relationship_type', 'hosted_on')
            ->orderBy('parent_kind')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $parentNames = $this->resolveParentNames($relationships);

        if ($request->query('csv') === '1') {
            return $this->downloadCsv($relationships, $parentNames);
        }

        return view('admin.product-hosted-on.index', compact('relationships', 'product', 'parentNames'));
    }

    /**
     * Resolve display names for every distinct parent asset in one query per
     * kind (no N+1), keyed by relationship id.
     *
     * @param  Collection<int, AssetRelationship>  $relationships
     * @return array<int, string|null>
     */
    private function resolveParentNames(Collection $relationships): array
    {
        $names = [];

        $parentsByKind = [];
        foreach ($relationships as $relationship) {
            $kind = $relationship->parent_kind;

            if (! array_key_exists($kind, self::PARENT_DISPLAY)) {
                $names[$relationship->id] = null;

                continue;
            }

            $parentsByKind[$kind][$relationship->parent_id] = $relationship->id;
        }

        foreach ($parentsByKind as $kind => $parentIds) {
            [$model, $column] = self::PARENT_DISPLAY[$kind];
            $rows = $model::query()->whereIn('id', array_keys($parentIds))->get(['id', $column]);

            foreach ($rows as $row) {
                $names[$parentIds[$row->id]] = $row->{$column};
            }
        }

        return $names;
    }

    /**
     * @param  Collection<int, AssetRelationship>  $relationships
     * @param  array<int, string|null>  $parentNames
     */
    private function downloadCsv(Collection $relationships, array $parentNames): StreamedResponse
    {
        return response()->streamDownload(function () use ($relationships, $parentNames) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['relationship_type', 'parent_kind', 'parent_id', 'parent_name', 'label', 'notes']);

            foreach ($relationships as $relationship) {
                fputcsv($handle, [
                    $relationship->relationship_type,
                    $relationship->parent_kind,
                    $relationship->parent_id,
                    $parentNames[$relationship->id] ?? '',
                    $relationship->label ?? '',
                    $relationship->notes ?? '',
                ]);
            }

            fclose($handle);
        }, 'product-hosted-on.csv', ['Content-Type' => 'text/csv']);
    }
}
