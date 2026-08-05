<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryAsset;
use App\Models\Datacenter;
use App\Models\Rack;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        if ($request->filled('search')) {
            $q = $request->query('search');
            $query->where(function ($q2) use ($q) {
                $q2->where('name', 'like', "%{$q}%")
                    ->orWhere('serial_number', 'like', "%{$q}%")
                    ->orWhere('model', 'like', "%{$q}%");
            });
        }
        $assets = $query->orderByDesc('id')->paginate(25)->withQueryString();
        $datacenters = Datacenter::orderBy('name')->get();
        $racks = Rack::orderBy('name')->get();
        return view('admin.inventory_assets.index', compact('assets', 'datacenters', 'racks'));
    }

    public function create(): View
    {
        $datacenters = Datacenter::orderBy('name')->get();
        $racks = Rack::orderBy('name')->get();
        return view('admin.inventory_assets.create', compact('datacenters', 'racks'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'asset_type' => ['required', 'string', 'in:server,switch,router,pdu,nic,storage,other'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'datacenter_id' => ['nullable', 'integer', 'exists:datacenters,id'],
            'rack_id' => ['nullable', 'integer', 'exists:racks,id'],
            'u_position' => ['nullable', 'integer', 'min:1'],
            'purchase_date' => ['nullable', 'date'],
            'warranty_expiry' => ['nullable', 'date'],
            'status' => ['sometimes', 'string', 'in:active,maintenance,decommissioned,spare'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $validated['status'] = $validated['status'] ?? 'active';
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
        return view('admin.inventory_assets.edit', compact('inventoryAsset', 'datacenters', 'racks'));
    }

    public function update(Request $request, InventoryAsset $inventoryAsset): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'datacenter_id' => ['nullable', 'integer', 'exists:datacenters,id'],
            'rack_id' => ['nullable', 'integer', 'exists:racks,id'],
            'u_position' => ['nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'in:active,maintenance,decommissioned,spare'],
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
}
