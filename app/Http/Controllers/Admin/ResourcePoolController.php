<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ResourcePool;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ResourcePoolController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));

        $query = ResourcePool::with('server');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('pool_type', 'like', "%{$search}%")
                    ->orWhere('unit', 'like', "%{$search}%");
            });
        }

        $pools = $query
            ->gridSort([
                'name' => 'name',
                'pool_type' => 'pool_type',
                'server' => 'server.name',
                'capacity' => 'total_capacity',
                'status' => 'status',
            ])
            ->orderBy('name')->paginate(20)->withQueryString();

        return view('admin.resource_pools.index', compact('pools', 'search'));
    }

    public function create(): View
    {
        $servers = Server::orderBy('name')->get();

        return view('admin.resource_pools.create', compact('servers'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'pool_type' => ['required', 'string', 'max:100'],
            'total_capacity' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:50'],
            'server_id' => ['nullable', 'integer', 'exists:servers,id'],
            'status' => ['sometimes', 'string', 'in:active,disabled'],
        ]);
        $validated['status'] = $validated['status'] ?? 'active';
        ResourcePool::create($validated);

        return redirect()->route('admin.resource-pools.index')->with('success', 'Resource pool created.');
    }

    public function show(ResourcePool $resourcePool): View
    {
        $resourcePool->load('server');

        return view('admin.resource_pools.show', compact('resourcePool'));
    }

    public function edit(ResourcePool $resourcePool): View
    {
        $servers = Server::orderBy('name')->get();

        return view('admin.resource_pools.edit', compact('resourcePool', 'servers'));
    }

    public function update(Request $request, ResourcePool $resourcePool): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'total_capacity' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:50'],
            'status' => ['sometimes', 'string', 'in:active,disabled'],
        ]);
        $resourcePool->update($validated);

        return redirect()->route('admin.resource-pools.show', $resourcePool)->with('success', 'Resource pool updated.');
    }

    public function destroy(ResourcePool $resourcePool): RedirectResponse
    {
        $resourcePool->delete();

        return redirect()->route('admin.resource-pools.index')->with('success', 'Resource pool deleted.');
    }
}
