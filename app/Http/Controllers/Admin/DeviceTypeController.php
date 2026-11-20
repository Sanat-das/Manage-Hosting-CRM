<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceType;
use App\Models\Manufacturer;
use App\Services\Inventory\InventoryChangeLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DeviceTypeController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));

        $query = DeviceType::with('manufacturer')->withCount('devices');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('model', 'like', "%{$search}%")
                    ->orWhere('part_number', 'like', "%{$search}%")
                    ->orWhereHas('manufacturer', fn ($manufacturer) => $manufacturer->where('name', 'like', "%{$search}%"));
            });
        }

        $deviceTypes = $query
            ->gridSort([
                'manufacturer' => 'manufacturer.name',
                'model' => 'model',
                'part_number' => 'part_number',
                'category' => 'category',
                'u_height' => 'u_height',
                'created_at' => 'created_at',
            ])
            ->orderBy('model')->paginate(30)->withQueryString();

        return view('admin.device_types.index', compact('deviceTypes', 'search'));
    }

    public function show(DeviceType $deviceType): View
    {
        $deviceType->load('manufacturer');
        $devices = $deviceType->devices()
            ->with('rack:id,name')
            ->orderBy('asset_tag')
            ->paginate(15);

        return view('admin.device_types.show', compact('deviceType', 'devices'));
    }

    public function create(): View
    {
        $manufacturers = Manufacturer::orderBy('name')->get();

        return view('admin.device_types.create', compact('manufacturers'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'manufacturer_id' => ['required', 'integer', 'exists:manufacturers,id'],
            'model' => [
                'required',
                'string',
                'max:255',
                Rule::unique('device_types', 'model')
                    ->where(fn ($query) => $query->where('manufacturer_id', $request->input('manufacturer_id'))),
            ],
            'slug' => ['nullable', 'string', 'alpha_dash', 'max:255', 'unique:device_types,slug'],
            'part_number' => ['nullable', 'string', 'max:255'],
            'category' => ['required', 'string', Rule::in(array_keys(DeviceType::CATEGORIES))],
            'u_height' => ['nullable', 'integer', 'min:1', 'max:100'],
            'is_full_depth' => ['sometimes', 'boolean'],
            'power_draw_watts' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $validated['is_full_depth'] = $validated['is_full_depth'] ?? true;
        $validated['u_height'] = $validated['u_height'] ?? 1;

        if (blank($validated['slug'] ?? null)) {
            $manufacturer = Manufacturer::findOrFail($validated['manufacturer_id']);
            $validated['slug'] = $this->uniqueSlug(Str::slug($manufacturer->name.'-'.$validated['model']));
        }

        DeviceType::create($validated);

        return redirect()->route('admin.device-types.index')->with('success', 'Device type created.');
    }

    public function edit(DeviceType $deviceType): View
    {
        $manufacturers = Manufacturer::orderBy('name')->get();

        return view('admin.device_types.edit', compact('deviceType', 'manufacturers'));
    }

    public function update(Request $request, DeviceType $deviceType): RedirectResponse
    {
        $validated = $request->validate([
            'manufacturer_id' => ['sometimes', 'integer', 'exists:manufacturers,id'],
            'model' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('device_types', 'model')
                    ->where(fn ($query) => $query->where('manufacturer_id', $request->input('manufacturer_id', $deviceType->manufacturer_id)))
                    ->ignore($deviceType->id),
            ],
            'slug' => ['nullable', 'string', 'alpha_dash', 'max:255', Rule::unique('device_types', 'slug')->ignore($deviceType->id)],
            'part_number' => ['nullable', 'string', 'max:255'],
            'category' => ['required', 'string', Rule::in(array_keys(DeviceType::CATEGORIES))],
            'u_height' => ['nullable', 'integer', 'min:1', 'max:100'],
            'is_full_depth' => ['sometimes', 'boolean'],
            'power_draw_watts' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        if (array_key_exists('u_height', $validated) && $validated['u_height'] === null) {
            $validated['u_height'] = 1;
        }

        if (array_key_exists('slug', $validated) && $validated['slug'] === null) {
            $manufacturer = Manufacturer::findOrFail($validated['manufacturer_id'] ?? $deviceType->manufacturer_id);
            $validated['slug'] = $this->uniqueSlug(
                Str::slug($manufacturer->name.'-'.($validated['model'] ?? $deviceType->model)),
                $deviceType->id,
            );
        }

        $deviceType->update($validated);

        return redirect()->route('admin.device-types.index')->with('success', 'Device type updated.');
    }

    public function destroy(DeviceType $deviceType): RedirectResponse
    {
        $changeLog = app(InventoryChangeLogger::class);

        DB::transaction(function () use ($deviceType, $changeLog): void {
            $assets = Device::withTrashed()
                ->where('device_type_id', $deviceType->id)
                ->lockForUpdate()
                ->get(['id', 'device_type_id']);

            $deviceType->delete();

            foreach ($assets as $asset) {
                $written = $changeLog->record($asset, 'updated', [
                    'device_type_id' => ['old' => $asset->device_type_id, 'new' => null],
                ]);

                if (! $written) {
                    throw new \RuntimeException("Audit log write failed for asset {$asset->id}; the device type was not deleted.");
                }
            }
        });

        return redirect()->route('admin.device-types.index')->with('success', 'Device type deleted.');
    }

    /**
     * Resolve a slug that is not already taken by another device type.
     */
    private function uniqueSlug(string $slug, ?int $ignoreId = null): string
    {
        $base = $slug !== '' ? $slug : 'device-type';
        $candidate = $base;
        $suffix = 2;

        while (DeviceType::query()
            ->where('slug', $candidate)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }
}
