<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\InventoryItem;
use App\Models\Manufacturer;
use App\Services\Inventory\InventoryChangeLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ManufacturerController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));

        $query = Manufacturer::query();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // devices/inventory_items are counted withTrashed to match the real
        // blast radius: the FK nullOnDelete clears device_type_id /
        // manufacturer_id on every row, soft-deleted included.
        $manufacturers = $query
            ->withCount('deviceTypes')
            ->addSelect([
                'devices_affected' => Device::withTrashed()
                    ->join('device_types', 'device_types.id', '=', 'devices.device_type_id')
                    ->whereColumn('device_types.manufacturer_id', 'manufacturers.id')
                    ->selectRaw('COUNT(*)'),
                'items_affected' => InventoryItem::withTrashed()
                    ->whereColumn('inventory_items.manufacturer_id', 'manufacturers.id')
                    ->selectRaw('COUNT(*)'),
            ])
            ->gridSort([
                'name' => 'name',
                'slug' => 'slug',
                'description' => 'description',
                'created_at' => 'created_at',
            ])
            ->orderBy('name')->paginate(30)->withQueryString();

        return view('admin.manufacturers.index', compact('manufacturers', 'search'));
    }

    public function create(): View
    {
        return view('admin.manufacturers.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:manufacturers,name'],
            'slug' => ['nullable', 'string', 'alpha_dash', 'max:255', 'unique:manufacturers,slug'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        if (blank($validated['slug'] ?? null)) {
            $validated['slug'] = $this->uniqueSlug(Str::slug($validated['name']));
        }

        Manufacturer::create($validated);

        return redirect()->route('admin.manufacturers.index')->with('success', 'Manufacturer created.');
    }

    public function edit(Manufacturer $manufacturer): View
    {
        return view('admin.manufacturers.edit', compact('manufacturer'));
    }

    public function update(Request $request, Manufacturer $manufacturer): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('manufacturers', 'name')->ignore($manufacturer->id)],
            'slug' => ['nullable', 'string', 'alpha_dash', 'max:255', Rule::unique('manufacturers', 'slug')->ignore($manufacturer->id)],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        if (array_key_exists('slug', $validated) && $validated['slug'] === null) {
            $validated['slug'] = $this->uniqueSlug(
                Str::slug($validated['name'] ?? $manufacturer->name),
                $manufacturer->id,
            );
        }

        $manufacturer->update($validated);

        return redirect()->route('admin.manufacturers.index')->with('success', 'Manufacturer updated.');
    }

    public function destroy(Manufacturer $manufacturer): RedirectResponse
    {
        $changeLog = app(InventoryChangeLogger::class);

        DB::transaction(function () use ($manufacturer, $changeLog): void {
            $assets = Device::withTrashed()
                ->whereIn('device_type_id', $manufacturer->deviceTypes()->pluck('id'))
                ->lockForUpdate()
                ->get(['id', 'device_type_id']);

            $manufacturer->delete();
            foreach ($assets as $asset) {
                $written = $changeLog->record($asset, 'updated', [
                    'device_type_id' => ['old' => $asset->device_type_id, 'new' => null],
                ]);

                if (! $written) {
                    throw new \RuntimeException("Audit log write failed for asset {$asset->id}; the manufacturer was not deleted.");
                }
            }
        });

        return redirect()->route('admin.manufacturers.index')->with('success', 'Manufacturer deleted.');
    }

    /**
     * Resolve a slug that is not already taken by another manufacturer.
     */
    private function uniqueSlug(string $slug, ?int $ignoreId = null): string
    {
        $base = $slug !== '' ? $slug : 'manufacturer';
        $candidate = $base;
        $suffix = 2;

        while (Manufacturer::query()
            ->where('slug', $candidate)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }
}
