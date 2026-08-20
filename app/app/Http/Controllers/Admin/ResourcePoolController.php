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
    public function index(): View
    {
        $pools = ResourcePool::with('server')->orderBy('name')->paginate(20);

        return view('admin.resource_pools.index', compact('pools'));
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
