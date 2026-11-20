<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\NormalizesInventoryItemInput;
use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Services\Inventory\InventoryItemDeletionService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sanctum-protected inventory items REST API (full CRUD).
 *
 * Mirrors the Api\DeviceController shape (paginated index with
 * filters, full resource presentation shared between list and detail) so
 * the API and the panel agree on what a valid inventory item is.
 */
class InventoryItemController extends Controller
{
    use NormalizesInventoryItemInput;

    private const PER_PAGE = 20;

    public function __construct(
        private readonly InventoryItemDeletionService $itemDeletionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search'));

        $items = InventoryItem::query()
            ->with(['device:id,asset_tag', 'manufacturer:id,name'])
            ->withCount('children')
            ->when($request->filled('device_id'), fn ($query) => $query->where('device_id', $request->integer('device_id')))
            ->when($request->filled('manufacturer_id'), fn ($query) => $query->where('manufacturer_id', $request->integer('manufacturer_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('serial_number', 'like', "%{$search}%")
                        ->orWhere('asset_tag', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('part_number', 'like', "%{$search}%")
                        ->orWhere('label', 'like', "%{$search}%")
                        ->orWhereHas('manufacturer', fn ($q) => $q->where('name', 'like', "%{$search}%"));
                });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(min(max((int) $request->query('per_page', self::PER_PAGE), 1), 100));

        return response()->json([
            'data' => $items->map(fn (InventoryItem $item) => $this->present($item)),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->normalizeInventoryItemEmpties($request->validate($this->storeRules($request)));

        $item = InventoryItem::create($validated);

        return response()->json(['data' => $this->present($item->load(['device:id,asset_tag', 'manufacturer:id,name'])->loadCount('children'))], 201);
    }

    public function show(InventoryItem $inventoryItem): JsonResponse
    {
        $inventoryItem->load(['device:id,asset_tag', 'manufacturer:id,name'])->loadCount('children');

        return response()->json(['data' => $this->present($inventoryItem)]);
    }

    public function update(Request $request, InventoryItem $inventoryItem): JsonResponse
    {
        $validated = $this->normalizeInventoryItemEmpties($request->validate($this->updateRules($request, $inventoryItem)));

        $inventoryItem->update($validated);

        return response()->json(['data' => $this->present($inventoryItem->fresh()->load(['device:id,asset_tag', 'manufacturer:id,name'])->loadCount('children'))]);
    }

    public function destroy(InventoryItem $inventoryItem): JsonResponse
    {
        $this->itemDeletionService->delete($inventoryItem);

        return response()->json(['data' => null, 'message' => 'Inventory item deleted.'], 200);
    }

    /**
     * API resource shape.
     *
     * @return array<string, mixed>
     */
    private function present(InventoryItem $item): array
    {
        return [
            'id' => $item->id,
            'device_id' => $item->device_id,
            'device' => $item->device !== null
                ? [
                    'id' => $item->device->id,
                    'asset_tag' => $item->device->asset_tag,
                ]
                : null,
            'manufacturer' => $item->manufacturer !== null
                ? [
                    'id' => $item->manufacturer->id,
                    'name' => $item->manufacturer->name,
                ]
                : null,
            'parent_item_id' => $item->parent_item_id,
            'name' => $item->name,
            'label' => $item->label,
            'sku' => $item->sku,
            'part_number' => $item->part_number,
            'serial_number' => $item->serial_number,
            'asset_tag' => $item->asset_tag,
            'status' => $item->status,
            'discovered' => (bool) $item->discovered,
            'description' => $item->description,
            'notes' => $item->notes,
            'children_count' => $item->children_count ?? $item->children()->count(),
            'created_at' => $item->created_at?->toIso8601String(),
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Store rules. Update mirrors these with every attribute optional so a
     * client can send only the fields it wants to change.
     *
     * @return array<string, array<int, mixed>>
     */
    private function storeRules(Request $request): array
    {
        return [
            'device_id' => ['required', 'integer', Rule::exists('devices', 'id')->whereNull('deleted_at')],
            'parent_item_id' => ['nullable', 'integer', Rule::exists('inventory_items', 'id')->whereNull('deleted_at'), $this->parentItemRule($request)],
            'name' => ['required', 'string', 'max:255', $this->siblingNameRule($request)],
            'label' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:255'],
            'part_number' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'asset_tag' => ['nullable', 'string', 'max:255', Rule::unique('inventory_items', 'asset_tag')->whereNull('deleted_at')],
            'manufacturer_id' => ['nullable', 'integer', 'exists:manufacturers,id'],
            'status' => ['sometimes', 'string', Rule::in(InventoryItem::STATUSES)],
            'discovered' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function updateRules(Request $request, InventoryItem $item): array
    {
        return [
            'device_id' => ['sometimes', 'integer', Rule::exists('devices', 'id')->whereNull('deleted_at'), $this->deviceChangeRule($request, $item)],
            'parent_item_id' => ['nullable', 'integer', Rule::exists('inventory_items', 'id')->whereNull('deleted_at'), $this->parentItemRule($request, $item)],
            'name' => ['sometimes', 'string', 'max:255', $this->siblingNameRule($request, $item)],
            'label' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:255'],
            'part_number' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'asset_tag' => ['nullable', 'string', 'max:255', Rule::unique('inventory_items', 'asset_tag')->whereNull('deleted_at')->ignore($item->id)],
            'manufacturer_id' => ['nullable', 'integer', 'exists:manufacturers,id'],
            'status' => ['sometimes', 'string', Rule::in(InventoryItem::STATUSES)],
            'discovered' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * A parent item must live on the same device as the item being saved, an
     * item cannot be its own parent, and walking up from the proposed parent
     * may never reach the item itself (that would create a cycle).
     */
    private function parentItemRule(Request $request, ?InventoryItem $item = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($request, $item): void {
            if (! is_numeric($value)) {
                return;
            }

            $parent = InventoryItem::find((int) $value);
            if ($parent === null) {
                return;
            }

            $effectiveDeviceId = $request->input('device_id', $item?->device_id);

            if (is_numeric($effectiveDeviceId) && (int) $parent->device_id !== (int) $effectiveDeviceId) {
                $fail('The parent item must belong to the same device.');

                return;
            }

            if ($item === null) {
                return;
            }

            if ((int) $parent->getKey() === (int) $item->getKey()) {
                $fail('An item cannot be its own parent.');

                return;
            }

            $ancestor = $parent;
            $seen = [];
            while ($ancestor->parent_item_id !== null && ! isset($seen[$ancestor->getKey()])) {
                $seen[$ancestor->getKey()] = true;
                $ancestor = InventoryItem::withTrashed()->find((int) $ancestor->parent_item_id);

                if ($ancestor === null) {
                    break;
                }

                if ((int) $ancestor->getKey() === (int) $item->getKey()) {
                    $fail('An item cannot be moved under one of its own descendants.');

                    return;
                }
            }
        };
    }

    /**
     * Moving an item to a different device is allowed only while the item
     * has no children, and a parented item only if the caller makes an
     * explicit parent decision in the same payload (clear it, or supply a
     * new parent — both validated by the parentItemRule). Without that
     * decision the update would strand the parentage across devices, the
     * same-device invariant every other rule enforces.
     */
    private function deviceChangeRule(Request $request, InventoryItem $item): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($request, $item): void {
            if (! is_numeric($value) || (int) $value === (int) $item->device_id) {
                return;
            }

            if ($item->children()->exists()) {
                $fail('Move the child items to another device (or clear their parent) before moving this item.');

                return;
            }

            if ($item->parent_item_id !== null && ! $request->has('parent_item_id')) {
                $fail('Change or clear this item\'s parent first before moving it to another device.');
            }
        };
    }

    /**
     * Sibling uniqueness: live items sharing the same device and parent may
     * not share a name. The item being updated is excluded from its own
     * sibling check.
     */
    private function siblingNameRule(Request $request, ?InventoryItem $item = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($request, $item): void {
            $name = trim((string) $value);

            if ($name === '') {
                return;
            }

            $effectiveDeviceId = $request->input('device_id', $item?->device_id);

            if (! is_numeric($effectiveDeviceId)) {
                return;
            }

            $effectiveParentId = $request->input('parent_item_id', $item?->parent_item_id);

            $query = InventoryItem::query()
                ->where('device_id', (int) $effectiveDeviceId)
                ->where('name', $name);

            if (blank($effectiveParentId)) {
                $query->whereNull('parent_item_id');
            } else {
                $query->where('parent_item_id', (int) $effectiveParentId);
            }

            if ($item !== null) {
                $query->where('id', '!=', $item->getKey());
            }

            if ($query->exists()) {
                $fail('An item with this name already exists for the same device and parent.');
            }
        };
    }
}
