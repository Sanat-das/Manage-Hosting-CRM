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
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');

        $statuses = ['active' => 'Active', 'maintenance' => 'Maintenance', 'decommissioned' => 'Decommissioned'];

        $query = Datacenter::withCount('racks');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('state', 'like', "%{$search}%")
                    ->orWhere('country', 'like', "%{$search}%");
            });
        }

        if ($status !== null && $status !== '' && array_key_exists($status, $statuses)) {
            $query->where('status', $status);
        }

        $datacenters = $query
            ->gridSort([
                'name' => 'name',
                'code' => 'code',
                'location' => 'city',
                'racks' => 'racks_count',
                'status' => 'status',
                'id' => 'id',
                'created_at' => 'created_at',
            ])
            ->orderByDesc('id')->paginate(20)->withQueryString();

        return view('admin.datacenters.index', compact('datacenters', 'search', 'status', 'statuses'));
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
