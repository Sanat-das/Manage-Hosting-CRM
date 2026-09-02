<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Datacenter;
use App\Models\Vlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VlanController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));

        $query = Vlan::with('datacenter');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('vlan_id', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $vlans = $query
            ->gridSort([
                'name' => 'name',
                'vlan_id' => 'vlan_id',
                'datacenter' => 'datacenter.name',
                'description' => 'description',
                'created_at' => 'created_at',
            ])
            ->orderBy('vlan_id')->paginate(30)->withQueryString();
        $datacenters = Datacenter::orderBy('name')->get();

        return view('admin.vlans.index', compact('vlans', 'datacenters', 'search'));
    }

    public function create(): View
    {
        $datacenters = Datacenter::orderBy('name')->get();

        return view('admin.vlans.create', compact('datacenters'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'vlan_id' => ['required', 'integer', 'min:1', 'max:4094'],
            'description' => ['nullable', 'string', 'max:500'],
            'datacenter_id' => ['nullable', 'integer', 'exists:datacenters,id'],
        ]);
        Vlan::create($validated);

        return redirect()->route('admin.vlans.index')->with('success', 'VLAN created.');
    }

    public function show(Vlan $vlan): View
    {
        $vlan->load('datacenter');

        return view('admin.vlans.show', compact('vlan'));
    }

    public function edit(Vlan $vlan): View
    {
        $datacenters = Datacenter::orderBy('name')->get();

        return view('admin.vlans.edit', compact('vlan', 'datacenters'));
    }

    public function update(Request $request, Vlan $vlan): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'vlan_id' => ['sometimes', 'integer', 'min:1', 'max:4094'],
            'description' => ['nullable', 'string', 'max:500'],
            'datacenter_id' => ['nullable', 'integer', 'exists:datacenters,id'],
        ]);
        $vlan->update($validated);

        return redirect()->route('admin.vlans.show', $vlan)->with('success', 'VLAN updated.');
    }

    public function destroy(Vlan $vlan): RedirectResponse
    {
        $vlan->delete();

        return redirect()->route('admin.vlans.index')->with('success', 'VLAN deleted.');
    }
}
