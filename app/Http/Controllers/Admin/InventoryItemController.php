<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\NormalizesInventoryItemInput;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\InventoryItem;
use App\Models\Manufacturer;
use App\Services\Inventory\InventoryItemDeletionService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InventoryItemController extends Controller
{
    use NormalizesInventoryItemInput;

    public function __construct(
        private readonly InventoryItemDeletionService $itemDeletionService,
    ) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = trim((string) $request->query('status'));

        $query = InventoryItem::with(['device', 'manufacturer']);

        if ($request->filled('device_id')) {
            $query->where('device_id', $request->query('device_id'));
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('serial_number', 'like', "%{$search}%")
                    ->orWhere('asset_tag', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('part_number', 'like', "%{$search}%")
                    ->orWhere('label', 'like', "%{$search}%")
                    ->orWhereHas('manufacturer', function ($manufacturer) use ($search): void {
                        $manufacturer->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $items = $query
            ->gridSort([
                'name' => 'name',
                'sku' => 'sku',
                'serial_number' => 'serial_number',
                'asset_tag' => 'asset_tag',
                'status' => 'status',
            ])
            ->orderBy('name')->paginate(25)->withQueryString();

        $devices = Device::orderBy('asset_tag')->get(['id', 'asset_tag', 'rack_u_position']);

        return view('admin.inventory_items.index', [
            'items' => $items,
            'devices' => $devices,
            'statuses' => Device::STATUSES,
            'search' => $search,
            'status' => $status,
            'deviceId' => trim((string) $request->query('device_id')),
        ]);
    }

    public function create(): View
    {
        $selectedDeviceId = request()->integer('device_id') ?: null;

        if ($selectedDeviceId !== null && ! Device::whereKey($selectedDeviceId)->exists()) {
            $selectedDeviceId = null;
        }

        return view('admin.inventory_items.create', $this->formOptions($selectedDeviceId, null));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->normalizeInventoryItemEmpties($request->validate($this->itemRules($request, null)));

        $item = InventoryItem::create($validated);

        return redirect()
            ->route('admin.devices.show', $item->device_id)
            ->with('success', 'Inventory item created.');
    }

    public function show(InventoryItem $inventoryItem): View
    {
        $inventoryItem->load(['device', 'manufacturer', 'parent']);
        $children = $inventoryItem->children()->with('device')->orderBy('name')->get();

        return view('admin.inventory_items.show', ['item' => $inventoryItem, 'children' => $children]);
    }

    public function edit(InventoryItem $inventoryItem): View
    {
        return view(
            'admin.inventory_items.edit',
            $this->formOptions((int) $inventoryItem->device_id, $inventoryItem),
        );
    }

    public function update(Request $request, InventoryItem $inventoryItem): RedirectResponse
    {
        $validated = $this->normalizeInventoryItemEmpties($request->validate($this->itemRules($request, $inventoryItem)));

        $inventoryItem->update($validated);

        return redirect()
            ->route('admin.inventory-items.show', $inventoryItem)
            ->with('success', 'Inventory item updated.');
    }

    public function destroy(InventoryItem $inventoryItem): RedirectResponse
    {
        $device = $inventoryItem->device;
        $this->itemDeletionService->delete($inventoryItem);

        if ($device !== null) {
            return redirect()
                ->route('admin.devices.show', $device)
                ->with('success', 'Inventory item deleted.');
        }

        return redirect()
            ->route('admin.inventory-items.index')
            ->with('success', 'Inventory item deleted.');
    }

    /**
     * Validation rules for the item form, shared by store() and update().
     * $item is the row being edited (null on create) and is used to scope
     * the sibling-name uniqueness and the parent-chain checks to other rows.
     *
     * @return array<string, array<int, mixed>>
     */
    private function itemRules(Request $request, ?InventoryItem $item): array
    {
        return [
            'device_id' => [
                'required',
                'integer',
                Rule::exists('devices', 'id')->whereNull('deleted_at'),
                function (string $attribute, mixed $value, Closure $fail) use ($request, $item): void {
                    if ($item === null || ! is_numeric($value) || (int) $value === (int) $item->device_id) {
                        return; // unchanged device, or a new item (no children yet)
                    }

                    if ($item->children()->exists()) {
                        $fail('Move the child items to another device (or clear their parent) before moving this item.');

                        return;
                    }

                    // A parented item can only move with an explicit parent
                    // decision in the same payload (clear it, or supply a new
                    // same-device parent); otherwise parentage strands.
                    if ($item->parent_item_id !== null && ! $request->has('parent_item_id')) {
                        $fail('Change or clear this item\'s parent first before moving it to another device.');
                    }
                },
            ],
            'parent_item_id' => [
                'nullable',
                'integer',
                Rule::exists('inventory_items', 'id')->whereNull('deleted_at'),
                function (string $attribute, mixed $value, Closure $fail) use ($request, $item): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    $parent = InventoryItem::find((int) $value);

                    if ($parent === null) {
                        return; // the exists rule reports the missing row
                    }

                    if ((int) $parent->device_id !== (int) $request->input('device_id')) {
                        $fail('The parent item belongs to a different device.');

                        return;
                    }

                    if ($item !== null && (int) $parent->id === (int) $item->id) {
                        $fail('An item cannot be its own parent.');

                        return;
                    }

                    // Walk up the ancestry chain starting at the candidate
                    // parent; if the item itself appears in it, the assignment
                    // would create a cycle. Bounded so a pre-existing cycle in
                    // legacy data cannot hang the request.
                    $cursor = $parent;
                    $hops = 0;

                    while ($cursor !== null && $hops < 50) {
                        if ($item !== null && (int) $cursor->id === (int) $item->id) {
                            $fail('The parent item is a descendant of this item.');

                            return;
                        }

                        $hops++;
                        $parentId = $cursor->parent_item_id;
                        $cursor = $parentId !== null ? InventoryItem::withTrashed()->find($parentId) : null;
                    }
                },
            ],
            'name' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail) use ($request, $item): void {
                    $deviceId = $request->input('device_id');

                    if (blank($deviceId) || ! is_string($value)) {
                        return;
                    }

                    $parentId = $request->input('parent_item_id');
                    $parentId = blank($parentId) ? null : (int) $parentId;

                    $query = InventoryItem::query()
                        ->where('device_id', $deviceId)
                        ->where('name', $value);

                    if ($parentId === null) {
                        $query->whereNull('parent_item_id');
                    } else {
                        $query->where('parent_item_id', $parentId);
                    }

                    if ($item !== null) {
                        $query->whereKeyNot($item->getKey());
                    }

                    if ($query->exists()) {
                        $fail('Another item with this name already exists in the same device and parent scope.');
                    }
                },
            ],
            'label' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:255'],
            'part_number' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'manufacturer_id' => ['nullable', 'integer', 'exists:manufacturers,id'],
            'asset_tag' => ['nullable', 'string', 'max:255', Rule::unique('inventory_items', 'asset_tag')->whereNull('deleted_at')->ignore($item?->id)],
            'status' => ['sometimes', 'string', Rule::in(Device::STATUSES)],
            'discovered' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * View options for the create/edit forms: live devices, manufacturers,
     * every live item (the form filters the parent dropdown per selected
     * device client-side) and the status list.
     *
     * @return array<string, mixed>
     */
    private function formOptions(?int $selectedDeviceId, ?InventoryItem $item): array
    {
        $parentItems = InventoryItem::query()
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'device_id']);

        return [
            'item' => $item,
            'selectedDeviceId' => $selectedDeviceId,
            'devices' => Device::orderBy('asset_tag')->get(['id', 'asset_tag', 'rack_u_position']),
            'manufacturers' => Manufacturer::orderBy('name')->get(['id', 'name']),
            'parentItems' => $parentItems,
            'statuses' => Device::STATUSES,
        ];
    }
}
