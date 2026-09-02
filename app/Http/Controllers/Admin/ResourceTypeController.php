<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ResourceType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ResourceTypeController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));

        $query = ResourceType::query();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $types = $query
            ->gridSort([
                'name' => 'name',
                'slug' => 'slug',
                'category' => 'category',
                'unit' => 'unit',
                'description' => 'description',
            ])
            ->orderBy('name')->paginate(20)->withQueryString();

        return view('admin.resource_types.index', compact('types', 'search'));
    }

    public function create(): View
    {
        return view('admin.resource_types.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:resource_types,name'],
            'slug' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'unit' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $validated['slug'] = $validated['slug'] ?: \Str::slug($validated['name']);
        ResourceType::create($validated);

        return redirect()->route('admin.resource-types.index')->with('success', 'Resource type created.');
    }

    public function show(ResourceType $resourceType): View
    {
        return view('admin.resource_types.show', ['type' => $resourceType]);
    }

    public function edit(ResourceType $resourceType): View
    {
        return view('admin.resource_types.edit', ['type' => $resourceType]);
    }

    public function update(Request $request, ResourceType $resourceType): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', 'unique:resource_types,name,'.$resourceType->id],
            'category' => ['nullable', 'string', 'max:100'],
            'unit' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $resourceType->update($validated);

        return redirect()->route('admin.resource-types.show', $resourceType)->with('success', 'Resource type updated.');
    }

    public function destroy(ResourceType $resourceType): RedirectResponse
    {
        $resourceType->delete();

        return redirect()->route('admin.resource-types.index')->with('success', 'Resource type deleted.');
    }
}
