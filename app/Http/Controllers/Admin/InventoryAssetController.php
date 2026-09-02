<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Datacenter;
use App\Models\InventoryAsset;
use App\Models\Rack;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InventoryAssetController extends Controller
{
    public function index(Request $request): View
    {
        $query = InventoryAsset::with(['datacenter', 'rack']);
        if ($request->filled('datacenter_id')) {
            $query->where('datacenter_id', $request->query('datacenter_id'));
        }
        if ($request->filled('rack_id')) {
            $query->where('rack_id', $request->query('rack_id'));
        }
        if ($request->filled('asset_type')) {
            $query->where('asset_type', $request->query('asset_type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        $search = trim((string) $request->query('search'));
        if ($search !== '') {
            $query->where(function ($q2) use ($search) {
                $q2->where('asset_tag', 'like', "%{$search}%")
                    ->orWhere('serial_number', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%");
            });
        }
        $assets = $query
            ->gridSort([
                'asset_tag' => 'asset_tag',
                'asset_type' => 'asset_type',
                'serial_number' => 'serial_number',
                'model' => 'model',
                'datacenter' => 'datacenter.name',
                'rack' => 'rack.name',
                'status' => 'status',
            ])
            ->orderByDesc('id')->paginate(25)->withQueryString();
        $datacenters = Datacenter::orderBy('name')->get();
        $racks = Rack::orderBy('name')->get();

        return view('admin.inventory_assets.index', [
            'assets' => $assets,
            'datacenters' => $datacenters,
            'racks' => $racks,
            'assetTypes' => InventoryAsset::ASSET_TYPES,
            'statuses' => InventoryAsset::STATUSES,
            'search' => $search,
            'status' => trim((string) $request->query('status')),
        ]);
    }

    public function create(): View
    {
        $datacenters = Datacenter::orderBy('name')->get();
        $racks = Rack::orderBy('name')->get();

        return view('admin.inventory_assets.create', $this->formOptions($datacenters, $racks));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'asset_tag' => ['required', 'string', 'max:255', Rule::unique('inventory_assets', 'asset_tag')],
            'asset_type' => ['required', 'string', Rule::in(InventoryAsset::ASSET_TYPES)],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'datacenter_id' => ['nullable', 'integer', 'exists:datacenters,id'],
            'rack_id' => ['nullable', 'integer', 'exists:racks,id'],
            'rack_u_position' => ['nullable', 'integer', 'min:1'],
            'purchase_date' => ['nullable', 'date'],
            'warranty_expiry' => ['nullable', 'date'],
            'status' => ['sometimes', 'string', Rule::in(InventoryAsset::STATUSES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $validated['status'] = $validated['status'] ?? 'in_stock';
        InventoryAsset::create($validated);

        return redirect()->route('admin.inventory-assets.index')->with('success', 'Inventory asset created.');
    }

    public function show(InventoryAsset $inventoryAsset): View
    {
        $inventoryAsset->load(['datacenter', 'rack', 'parent', 'children']);

        return view('admin.inventory_assets.show', compact('inventoryAsset'));
    }

    public function edit(InventoryAsset $inventoryAsset): View
    {
        $datacenters = Datacenter::orderBy('name')->get();
        $racks = Rack::orderBy('name')->get();

        return view('admin.inventory_assets.edit', ['inventoryAsset' => $inventoryAsset] + $this->formOptions($datacenters, $racks));
    }

    public function update(Request $request, InventoryAsset $inventoryAsset): RedirectResponse
    {
        $validated = $request->validate([
            'asset_tag' => ['sometimes', 'string', 'max:255', Rule::unique('inventory_assets', 'asset_tag')->ignore($inventoryAsset->id)],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'datacenter_id' => ['nullable', 'integer', 'exists:datacenters,id'],
            'rack_id' => ['nullable', 'integer', 'exists:racks,id'],
            'rack_u_position' => ['nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', Rule::in(InventoryAsset::STATUSES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $inventoryAsset->update($validated);

        return redirect()->route('admin.inventory-assets.show', $inventoryAsset)->with('success', 'Inventory asset updated.');
    }

    public function destroy(InventoryAsset $inventoryAsset): RedirectResponse
    {
        $inventoryAsset->delete();

        return redirect()->route('admin.inventory-assets.index')->with('success', 'Inventory asset deleted.');
    }

    /**
     * Shared view options for the create/edit forms.
     *
     * @param  \Illuminate\Support\Collection<int, Datacenter>  $datacenters
     * @param  \Illuminate\Support\Collection<int, Rack>  $racks
     * @return array<string, mixed>
     */
    private function formOptions($datacenters, $racks): array
    {
        return [
            'datacenters' => $datacenters,
            'racks' => $racks,
            'assetTypes' => InventoryAsset::ASSET_TYPES,
            'statuses' => InventoryAsset::STATUSES,
        ];
    }
}
