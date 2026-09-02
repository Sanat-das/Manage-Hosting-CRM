<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Datacenter;
use App\Models\Rack;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RackController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));

        $query = Rack::with('datacenter');

        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }
        if ($request->filled('datacenter_id')) {
            $query->where('datacenter_id', $request->query('datacenter_id'));
        }

        $racks = $query
            ->gridSort([
                'id' => 'id',
                'name' => 'name',
                'datacenter' => 'datacenter.name',
                'u_height' => 'u_height',
                'u_available' => 'u_available',
                'power' => 'power_capacity_watts',
                'status' => 'status',
            ])
            ->orderByDesc('id')->paginate(20)->withQueryString();
        $datacenters = Datacenter::orderBy('name')->get();

        return view('admin.racks.index', compact('racks', 'datacenters', 'search'));
    }

    public function create(): View
    {
        $datacenters = Datacenter::orderBy('name')->get();

        return view('admin.racks.create', compact('datacenters'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'datacenter_id' => ['required', 'integer', 'exists:datacenters,id'],
            'name' => ['required', 'string', 'max:255'],
            'u_height' => ['nullable', 'integer', 'min:1'],
            'u_available' => ['nullable', 'integer', 'min:0'],
            'power_capacity_watts' => ['nullable', 'integer', 'min:0'],
            'status' => ['sometimes', 'string', 'in:active,maintenance,decommissioned'],
        ]);
        $validated['status'] = $validated['status'] ?? 'active';
        Rack::create($validated);

        return redirect()->route('admin.racks.index')->with('success', 'Rack created.');
    }

    public function show(Rack $rack): View
    {
        $rack->load('datacenter');

        return view('admin.racks.show', compact('rack'));
    }

    public function edit(Rack $rack): View
    {
        $datacenters = Datacenter::orderBy('name')->get();

        return view('admin.racks.edit', compact('rack', 'datacenters'));
    }

    public function update(Request $request, Rack $rack): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'u_height' => ['nullable', 'integer', 'min:1'],
            'u_available' => ['nullable', 'integer', 'min:0'],
            'power_capacity_watts' => ['nullable', 'integer', 'min:0'],
            'status' => ['sometimes', 'string', 'in:active,maintenance,decommissioned'],
        ]);
        $rack->update($validated);

        return redirect()->route('admin.racks.show', $rack)->with('success', 'Rack updated.');
    }

    public function destroy(Rack $rack): RedirectResponse
    {
        $rack->delete();

        return redirect()->route('admin.racks.index')->with('success', 'Rack deleted.');
    }
}
