<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ValidatesInventoryAssetRules;
use App\Http\Controllers\Controller;
use App\Models\InventoryAsset;
use App\Services\Inventory\InventoryAssetDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sanctum-protected inventory assets REST API (full CRUD).
 *
 * Mirrors the Api\SslController shape (paginated index with filters, full
 * resource presentation) and reuses the admin InventoryAssetController's
 * validation semantics — including the shared rack U-position closure — so
 * the API and the panel agree on what a valid asset is.
 */
class InventoryAssetController extends Controller
{
    use ValidatesInventoryAssetRules;

    private const PER_PAGE = 20;

    public function __construct(private readonly InventoryAssetDeletionService $inventoryAssetDeletionService) {}

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search'));

        $assets = InventoryAsset::query()
            ->with(['datacenter:id,name', 'rack:id,name'])
            ->when($request->filled('asset_type'), fn ($query) => $query->where('asset_type', $request->query('asset_type')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->when($request->filled('datacenter_id'), fn ($query) => $query->where('datacenter_id', $request->integer('datacenter_id')))
            ->when($request->filled('rack_id'), fn ($query) => $query->where('rack_id', $request->integer('rack_id')))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('asset_tag', 'like', "%{$search}%")
                        ->orWhere('serial_number', 'like', "%{$search}%")
                        ->orWhere('model', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('id')
            ->paginate(min(max((int) $request->query('per_page', self::PER_PAGE), 1), 100));

        return response()->json([
            'data' => $assets->map(fn (InventoryAsset $asset) => $this->present($asset)),
            'meta' => [
                'current_page' => $assets->currentPage(),
                'last_page' => $assets->lastPage(),
                'per_page' => $assets->perPage(),
                'total' => $assets->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->inventoryAssetStoreRules($request));
        $validated['status'] = $validated['status'] ?? 'in_stock';

        $asset = InventoryAsset::create($validated);

        return response()->json(['data' => $this->present($asset->load(['datacenter:id,name', 'rack:id,name']))], 201);
    }

    public function show(InventoryAsset $inventoryAsset): JsonResponse
    {
        $inventoryAsset->load(['datacenter:id,name', 'rack:id,name']);

        return response()->json(['data' => $this->present($inventoryAsset, true)]);
    }

    public function update(Request $request, InventoryAsset $inventoryAsset): JsonResponse
    {
        $validated = $request->validate($this->inventoryAssetUpdateRules($request, $inventoryAsset));

        $this->validateRackTransition($inventoryAsset, $validated);

        $inventoryAsset->update($validated);

        return response()->json(['data' => $this->present($inventoryAsset->fresh()->load(['datacenter:id,name', 'rack:id,name']))]);
    }

    public function destroy(InventoryAsset $inventoryAsset): JsonResponse
    {
        $this->inventoryAssetDeletionService->delete($inventoryAsset);

        return response()->json(['data' => null, 'message' => 'Inventory asset deleted.'], 200);
    }

    /**
     * API resource shape.
     *
     * @return array<string, mixed>
     */
    private function present(InventoryAsset $asset, bool $detailed = false): array
    {
        $data = [
            'id' => $asset->id,
            'asset_tag' => $asset->asset_tag,
            'serial_number' => $asset->serial_number,
            'asset_type' => $asset->asset_type,
            'manufacturer' => $asset->manufacturer,
            'model' => $asset->model,
            'vendor' => $asset->vendor,
            'datacenter_id' => $asset->datacenter_id,
            'datacenter_name' => $asset->datacenter?->name,
            'rack_id' => $asset->rack_id,
            'rack_name' => $asset->rack?->name,
            'rack_u_position' => $asset->rack_u_position,
            'parent_asset_id' => $asset->parent_asset_id,
            'purchase_date' => $asset->purchase_date?->toDateString(),
            'purchase_cost' => $asset->purchase_cost !== null ? (float) $asset->purchase_cost : null,
            'warranty_expiry' => $asset->warranty_expiry?->toDateString(),
            'status' => $asset->status,
            'notes' => $asset->notes,
            'created_at' => $asset->created_at?->toIso8601String(),
            'updated_at' => $asset->updated_at?->toIso8601String(),
        ];

        if ($detailed) {
            $data['datacenter'] = $asset->datacenter !== null
                ? ['id' => $asset->datacenter->id, 'name' => $asset->datacenter->name]
                : null;
            $data['rack'] = $asset->rack !== null
                ? ['id' => $asset->rack->id, 'name' => $asset->rack->name]
                : null;
        }

        return $data;
    }
}
