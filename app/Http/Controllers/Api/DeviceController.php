<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ValidatesDeviceRules;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Tag;
use App\Services\Inventory\DeviceDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Sanctum-protected devices REST API (full CRUD).
 *
 * Mirrors the Api\SslController shape (paginated index with filters, full
 * resource presentation) and reuses the admin DeviceController's
 * validation semantics — including the shared rack U-position closure — so
 * the API and the panel agree on what a valid device is.
 */
class DeviceController extends Controller
{
    use ValidatesDeviceRules;

    private const PER_PAGE = 20;

    public function __construct(private readonly DeviceDeletionService $deviceDeletionService) {}

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search'));

        $assets = Device::query()
            ->with(['datacenter:id,name', 'rack:id,name', 'deviceType.manufacturer', 'tags'])
            ->when($request->filled('category'), fn ($query) => $query->whereHas('deviceType', fn ($deviceType) => $deviceType->where('category', $request->query('category'))))
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
            'data' => $assets->map(fn (Device $asset) => $this->present($asset)),
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
        $validated = $request->validate($this->deviceStoreRules($request));
        $validated['status'] = $validated['status'] ?? 'in_stock';
        $this->applyDeviceType($validated);

        $asset = DB::transaction(function () use ($validated, $request) {
            $asset = Device::create(Arr::except($validated, ['tags']));
            $this->syncAssetTags($asset, $validated, $request);

            return $asset;
        });

        return response()->json(['data' => $this->present($asset->load(['datacenter:id,name', 'rack:id,name', 'deviceType.manufacturer', 'tags']))], 201);
    }

    public function show(Device $device): JsonResponse
    {
        $device->load(['datacenter:id,name', 'rack:id,name', 'deviceType.manufacturer', 'tags']);

        return response()->json(['data' => $this->present($device, true)]);
    }

    public function update(Request $request, Device $device): JsonResponse
    {
        $validated = $request->validate($this->deviceUpdateRules($request, $device));

        $this->applyDeviceType($validated);
        $this->validateRackTransition($device, $validated);

        DB::transaction(function () use ($device, $validated, $request): void {
            $device->update(Arr::except($validated, ['tags']));
            $this->syncAssetTags($device, $validated, $request);
        });

        return response()->json(['data' => $this->present($device->fresh()->load(['datacenter:id,name', 'rack:id,name', 'deviceType.manufacturer', 'tags']))]);
    }

    public function destroy(Device $device): JsonResponse
    {
        $this->deviceDeletionService->delete($device);

        return response()->json(['data' => null, 'message' => 'Device deleted.'], 200);
    }

    /**
     * API resource shape.
     *
     * @return array<string, mixed>
     */
    private function present(Device $asset, bool $detailed = false): array
    {
        $data = [
            'id' => $asset->id,
            'asset_tag' => $asset->asset_tag,
            'serial_number' => $asset->serial_number,
            'manufacturer' => $asset->manufacturer,
            'model' => $asset->model,
            'vendor' => $asset->vendor,
            'datacenter_id' => $asset->datacenter_id,
            'datacenter_name' => $asset->datacenter?->name,
            'rack_id' => $asset->rack_id,
            'rack_name' => $asset->rack?->name,
            'rack_u_position' => $asset->rack_u_position,
            'purchase_date' => $asset->purchase_date?->toDateString(),
            'purchase_cost' => $asset->purchase_cost !== null ? (float) $asset->purchase_cost : null,
            'warranty_expiry' => $asset->warranty_expiry?->toDateString(),
            'status' => $asset->status,
            'notes' => $asset->notes,
            'device_type_id' => $asset->device_type_id,
            'device_type' => $asset->deviceType !== null
                ? [
                    'id' => $asset->deviceType->id,
                    'model' => $asset->deviceType->model,
                    'manufacturer' => $asset->deviceType->manufacturer?->name,
                    'u_height' => $asset->deviceType->u_height,
                ]
                : null,
            'tags' => $asset->tags->map(fn (Tag $tag): array => [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'color' => $tag->color,
            ])->values()->all(),
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
