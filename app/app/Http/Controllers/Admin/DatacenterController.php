<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Datacenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Datacenter CRUD — enterprise infrastructure module (Session 3B.2).
 */
class DatacenterController extends Controller
{
    public function index(): View
    {
        $datacenters = Datacenter::withCount('racks')->orderByDesc('id')->paginate(20);

        return view('admin.datacenters.index', compact('datacenters'));
    }

    public function create(): View
    {
        return view('admin.datacenters.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:datacenters,name'],
            'code' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'timezone' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'in:active,maintenance,decommissioned'],
        ]);
        $validated['status'] = $validated['status'] ?? 'active';

        Datacenter::create($validated);

        return redirect()->route('admin.datacenters.index')->with('success', 'Datacenter created.');
    }

    public function show(Datacenter $datacenter): View
    {
        $datacenter->load(['racks', 'subnets']);

        return view('admin.datacenters.show', compact('datacenter'));
    }

    public function edit(Datacenter $datacenter): View
    {
        return view('admin.datacenters.edit', compact('datacenter'));
    }

    public function update(Request $request, Datacenter $datacenter): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', 'unique:datacenters,name,'.$datacenter->id],
            'code' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'timezone' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'in:active,maintenance,decommissioned'],
        ]);
        $datacenter->update($validated);

        return redirect()->route('admin.datacenters.show', $datacenter)->with('success', 'Datacenter updated.');
    }

    public function destroy(Datacenter $datacenter): RedirectResponse
    {
        $datacenter->delete();

        return redirect()->route('admin.datacenters.index')->with('success', 'Datacenter deleted.');
    }
}
