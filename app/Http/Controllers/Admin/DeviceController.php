<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ValidatesDeviceRules;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Datacenter;
use App\Models\Device;
use App\Models\DeviceType;
use App\Models\InventoryItem;
use App\Models\IpAddress;
use App\Models\IpAllocationHistory;
use App\Models\Manufacturer;
use App\Models\PortConnection;
use App\Models\PortConnectionEvent;
use App\Models\Rack;
use App\Models\Tag;
use App\Models\User;
use App\Services\Exports\CsvStreamService;
use App\Services\Inventory\DeviceDeletionService;
use App\Services\Inventory\InventoryChangeLogger;
use App\Services\IpAssignmentService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeviceController extends Controller
{
    use ValidatesDeviceRules;

    public function __construct(
        private readonly IpAssignmentService $ipAssignmentService,
        private readonly DeviceDeletionService $deviceDeletionService,
    ) {}

    public function index(Request $request): View
    {
        $assets = $this->filteredQuery($request)
            ->gridSort([
                'asset_tag' => 'asset_tag',
                'category' => 'deviceType.category',
                'serial_number' => 'serial_number',
                'model' => 'model',
                'datacenter' => 'datacenter.name',
                'rack' => 'rack.name',
                'status' => 'status',
            ])
            ->orderByDesc('id')->paginate(25)->withQueryString();
        $datacenters = Datacenter::orderBy('name')->get();
        $racks = Rack::orderBy('name')->get();
        $bulkEditDeviceTypes = DeviceType::query()
            ->select('device_types.*')
            ->with('manufacturer')
            ->join('manufacturers', 'manufacturers.id', '=', 'device_types.manufacturer_id')
            ->orderBy('manufacturers.name')
            ->orderBy('device_types.model')
            ->get();

        return view('admin.devices.index', [
            'assets' => $assets,
            'datacenters' => $datacenters,
            'racks' => $racks,
            'deviceTypes' => $bulkEditDeviceTypes,
            'tags' => Tag::orderBy('name')->get(),
            'statuses' => Device::STATUSES,
            'categories' => DeviceType::CATEGORIES,
            'search' => trim((string) $request->query('search')),
            'status' => trim((string) $request->query('status')),
        ]);
    }

    /**
     * The filtered, eager-loaded asset query shared by the index grid and the
     * streamed CSV export, so both honour the same filters.
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = Device::with(['datacenter', 'rack', 'tags', 'deviceType:id,manufacturer_id,model,category', 'deviceType.manufacturer:id,name']);
        if ($request->filled('datacenter_id')) {
            $query->where('datacenter_id', $request->query('datacenter_id'));
        }
        if ($request->filled('rack_id')) {
            $query->where('rack_id', $request->query('rack_id'));
        }
        if ($request->filled('category')) {
            $category = $request->query('category');
            $query->whereHas('deviceType', fn (Builder $deviceType) => $deviceType->where('category', $category));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        $tag = trim((string) $request->query('tag'));
        if ($tag !== '') {
            $query->whereHas('tags', function (Builder $tags) use ($tag): void {
                if (ctype_digit($tag)) {
                    $tags->whereKey((int) $tag);

                    return;
                }

                $tags->where('slug', $tag);
            });
        }
        $search = trim((string) $request->query('search'));
        if ($search !== '') {
            $query->where(function ($q2) use ($search) {
                $q2->where('asset_tag', 'like', "%{$search}%")
                    ->orWhere('serial_number', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    public function export(Request $request): StreamedResponse
    {
        $filename = 'devices-'.now()->format('Ymd-His').'.csv';
        $csvHeaders = [
            'asset_tag', 'category', 'serial_number', 'model', 'manufacturer',
            'vendor', 'status', 'datacenter', 'rack', 'rack_u_position',
            'purchase_date', 'purchase_cost', 'warranty_expiry',
            'device_type', 'tags',
        ];

        /** @var CsvStreamService $csv */
        $csv = app(CsvStreamService::class);

        return $csv->stream($filename, $csvHeaders, function ($handle) use ($request): void {
            $this->filteredQuery($request)->chunk(500, function ($assets) use ($handle): void {
                foreach ($assets as $asset) {
                    fputcsv($handle, [
                        $asset->asset_tag,
                        $asset->deviceType?->category,
                        $asset->serial_number,
                        $asset->model,
                        $asset->manufacturer,
                        $asset->vendor,
                        $asset->status,
                        $asset->datacenter?->name,
                        $asset->rack?->name,
                        $asset->rack_u_position,
                        $asset->purchase_date?->format('Y-m-d'),
                        $asset->purchase_cost,
                        $asset->warranty_expiry?->format('Y-m-d'),
                        $asset->deviceType !== null
                            ? trim(($asset->deviceType->manufacturer?->name ?? '').' '.$asset->deviceType->model)
                            : null,
                        $asset->tags->pluck('name')->implode('|'),
                    ]);
                }
            });
        });
    }

    public function bulkStatus(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'min:1', 'distinct', Rule::exists('devices', 'id')->whereNull('deleted_at')],
            'status' => ['required', 'string', Rule::in(Device::STATUSES)],
        ]);

        $newStatus = $validated['status'];

        $changed = DB::transaction(function () use ($validated, $newStatus) {
            $assets = Device::query()
                ->whereIn('id', $validated['ids'])
                ->where('status', '!=', $newStatus)
                ->lockForUpdate()
                ->get(['id', 'status']);

            if ($assets->isEmpty()) {
                return $assets;
            }

            Device::query()
                ->whereIn('id', $assets->pluck('id'))
                ->update(['status' => $newStatus]);

            $changeLog = app(InventoryChangeLogger::class);
            foreach ($assets as $asset) {
                $written = $changeLog->record($asset, 'updated', [
                    'status' => ['old' => $asset->status, 'new' => $newStatus],
                ]);

                if (! $written) {
                    throw new \RuntimeException("Audit log write failed for asset {$asset->id}; no status was changed.");
                }
            }

            return $assets;
        });

        $count = $changed->count();

        return redirect()->back()->with(
            'success',
            'Updated '.$count.' '.($count === 1 ? 'device' : 'devices').'.',
        );
    }

    public function bulkEdit(Request $request): RedirectResponse
    {
        $input = $request->all();

        // An untouched field reaches the server as an empty string; treat
        // those as absent so a blank control never overwrites a value. Some
        // clients post the same control twice (duplicate name in the form);
        // keep a lone surviving value, drop the duplicates.
        foreach (['status', 'datacenter_id', 'device_type_id', 'warranty_expiry'] as $field) {
            if (! array_key_exists($field, $input)) {
                continue;
            }
            $value = $input[$field];
            if (is_array($value)) {
                $kept = array_values(array_filter($value, fn ($item) => $item !== null && $item !== ''));
                if ($kept === []) {
                    unset($input[$field]);
                    continue;
                }
                $input[$field] = count($kept) === 1 ? $kept[0] : $kept;
                continue;
            }
            if ($value === '' || $value === null) {
                unset($input[$field]);
            }
        }

        $validator = Validator::make($input, [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct', Rule::exists('devices', 'id')->whereNull('deleted_at')],
            'status' => ['sometimes', 'string', Rule::in(Device::STATUSES)],
            'datacenter_id' => ['sometimes', 'nullable', 'integer', Rule::exists('datacenters', 'id')],
            'device_type_id' => ['sometimes', 'nullable', 'integer', Rule::exists('device_types', 'id')],
            'warranty_expiry' => ['sometimes', 'nullable', 'date'],
        ]);

        $validator->after(function ($validator) use ($input): void {
            $present = collect(['status', 'datacenter_id', 'device_type_id', 'warranty_expiry'])
                ->contains(fn (string $field): bool => array_key_exists($field, $input) && $input[$field] !== null);

            if (! $present) {
                $validator->errors()->add('bulk_edit', 'Select at least one field to update.');
            }
        });

        $validated = $validator->validate();

        $changed = DB::transaction(function () use ($validated) {
            $assets = Device::query()
                ->whereIn('id', $validated['ids'])
                ->lockForUpdate()
                ->get();

            $updates = [];
            foreach ($assets as $asset) {
                $newValues = [];
                $changes = [];

                foreach (['status', 'datacenter_id', 'device_type_id', 'warranty_expiry'] as $field) {
                    if (! array_key_exists($field, $validated)) {
                        continue;
                    }

                    $new = $validated[$field];

                    // An explicit null clears nothing; only real values are applied.
                    if ($new === null) {
                        continue;
                    }

                    if ($field === 'warranty_expiry') {
                        $old = $asset->warranty_expiry?->format('Y-m-d');
                        $newValue = Carbon::parse((string) $new)->format('Y-m-d');
                        if ($old === $newValue) {
                            continue;
                        }
                        $changes[$field] = ['old' => $old, 'new' => $newValue];
                        $newValues[$field] = $newValue;

                        continue;
                    }

                    if ($field === 'status') {
                        if ($asset->status === $new) {
                            continue;
                        }
                        $changes[$field] = ['old' => $asset->status, 'new' => $new];
                        $newValues[$field] = $new;

                        continue;
                    }

                    $old = (int) $asset->{$field};
                    if ($old === (int) $new) {
                        continue;
                    }
                    $changes[$field] = ['old' => $old, 'new' => (int) $new];
                    $newValues[$field] = (int) $new;
                }

                if ($changes !== []) {
                    $updates[] = ['asset' => $asset, 'newValues' => $newValues, 'changes' => $changes];
                }
            }

            if ($updates === []) {
                return collect();
            }

            $changeLog = app(InventoryChangeLogger::class);
            $changed = collect();
            foreach ($updates as $update) {
                Device::query()
                    ->where('id', $update['asset']->id)
                    ->update($update['newValues']);

                $written = $changeLog->record($update['asset'], 'updated', $update['changes']);

                if (! $written) {
                    throw new \RuntimeException("Audit log write failed for asset {$update['asset']->id}; no bulk-edit changes were saved.");
                }

                $changed->push($update['asset']);
            }

            return $changed;
        });

        $count = $changed->count();

        return redirect()->route('admin.devices.index')->with(
            'success',
            'Updated '.$count.' '.($count === 1 ? 'device' : 'devices').'.',
        );
    }

    public function create(): View
    {
        $datacenters = Datacenter::orderBy('name')->get();
        $racks = Rack::orderBy('name')->get();

        return view('admin.devices.create', $this->formOptions($datacenters, $racks) + [
            'linkedIps' => $this->linkedIpsForForm(null),
            'ipTrackingCategories' => Device::IP_TRACKING_TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->deviceStoreRules($request) + [
            'ip_picker_present' => ['sometimes', 'boolean'],
            'ip_address_ids' => ['nullable', 'array'],
            'ip_address_ids.*' => ['integer', 'exists:ip_addresses,id', ...$this->ipAddressIdRules()],
        ]);
        $validated['status'] = $validated['status'] ?? 'in_stock';
        $this->applyDeviceType($validated);

        $ipIds = (array) ($validated['ip_address_ids'] ?? []);
        $hasPicker = $request->boolean('ip_picker_present');
        unset($validated['ip_picker_present'], $validated['ip_address_ids']);

        DB::transaction(function () use ($validated, $request, $hasPicker, $ipIds): void {
            $asset = Device::create(Arr::except($validated, ['tags']));
            $this->syncAssetTags($asset, $validated, $request);

            if ($hasPicker) {
                $this->syncIpLinks($asset, $ipIds);
            }
        });

        return redirect()->route('admin.devices.index')->with('success', 'Device created.');
    }

    public function show(Device $device): View
    {
        $device->load(['datacenter', 'rack', 'ipAddresses.subnet.vlan', 'tags', 'deviceType.manufacturer']);

        $recentIpHistory = IpAllocationHistory::query()
            ->with('ipAddress')
            ->whereIn('ip_address_id', $device->ipAddresses->pluck('id'))
            ->latest('changed_at')
            ->limit(10)
            ->get();

        // Ports card: loaded only for port-bearing assets or assets that already
        // hold ports, so a pure-software asset pays nothing. The connection map
        // is built in PHP from one grouped query (no N+1 per row).
        $ports = collect();
        $connectionMap = [];
        $recentCableEvents = collect();
        $cableEventUsers = collect();

        if (in_array($device->deviceType?->category, Device::PORT_BEARING_TYPES, true) || $device->ports()->exists()) {
            $ports = $device->ports()->orderBy('name')->get();
            $portIds = $ports->pluck('id')->all();

            if ($portIds !== []) {
                $connections = PortConnection::query()
                    ->with([
                        'portA.device:id,asset_tag',
                        'portB.device:id,asset_tag',
                    ])
                    ->where(function (Builder $query) use ($portIds): void {
                        $query->whereIn('port_a_id', $portIds)->orWhereIn('port_b_id', $portIds);
                    })
                    ->get();

                foreach ($connections as $connection) {
                    if (in_array((int) $connection->port_a_id, $portIds, true)) {
                        $connectionMap[(int) $connection->port_a_id] = [
                            'connection' => $connection,
                            'peerPort' => $connection->portB,
                            'peerAssetTag' => $connection->portB?->device?->asset_tag,
                        ];
                    }

                    if (in_array((int) $connection->port_b_id, $portIds, true)) {
                        $connectionMap[(int) $connection->port_b_id] = [
                            'connection' => $connection,
                            'peerPort' => $connection->portA,
                            'peerAssetTag' => $connection->portA?->device?->asset_tag,
                        ];
                    }
                }

                $recentCableEvents = PortConnectionEvent::query()
                    ->where(function (Builder $query) use ($portIds): void {
                        $query->whereIn('port_a_id', $portIds)->orWhereIn('port_b_id', $portIds);
                    })
                    ->orderByDesc('changed_at')
                    ->limit(10)
                    ->get();

                $cableEventUsers = User::query()
                    ->whereIn('id', $recentCableEvents->pluck('changed_by_user_id')->filter()->unique()->all())
                    ->get()
                    ->mapWithKeys(fn (User $user): array => [$user->id => $user->name]);
            }
        }

        $itemIds = InventoryItem::withTrashed()
            ->where('device_id', $device->getKey())
            ->pluck('id');

        $history = AuditLog::with('user')
            ->where(function (Builder $query) use ($device, $itemIds): void {
                $query->where(fn (Builder $assets) => $assets->where('entity_type', 'device')->where('entity_id', $device->getKey()))
                    ->orWhere(fn (Builder $items) => $items->where('entity_type', 'inventory_item')->whereIn('entity_id', $itemIds));
            })
            ->latest('id')
            ->limit(15)
            ->get();

        // Inventory items: components installed in this device. Loaded here
        // rather than via a model relation because Device is frozen.
        $inventoryItems = InventoryItem::query()
            ->where('device_id', $device->getKey())
            ->with('manufacturer')
            ->orderBy('name')
            ->get();

        return view('admin.devices.show', compact(
            'device',
            'recentIpHistory',
            'ports',
            'connectionMap',
            'recentCableEvents',
            'cableEventUsers',
            'history',
            'inventoryItems',
        ));
    }

    public function edit(Device $device): View
    {
        $datacenters = Datacenter::orderBy('name')->get();
        $racks = Rack::orderBy('name')->get();

        return view('admin.devices.edit', [
            'device' => $device,
            'linkedIps' => $this->linkedIpsForForm($device),
            'ipTrackingCategories' => Device::IP_TRACKING_TYPES,
        ] + $this->formOptions($datacenters, $racks));
    }

    public function update(Request $request, Device $device): RedirectResponse
    {
        $validated = $request->validate($this->deviceUpdateRules($request, $device) + [
            'ip_picker_present' => ['sometimes', 'boolean'],
            'ip_address_ids' => ['nullable', 'array'],
            'ip_address_ids.*' => ['integer', 'exists:ip_addresses,id', ...$this->ipAddressIdRules($device)],
        ]);

        $this->applyDeviceType($validated);
        $this->validateRackTransition($device, $validated);

        $ipIds = (array) ($validated['ip_address_ids'] ?? []);
        $hasPicker = $request->boolean('ip_picker_present');
        unset($validated['ip_picker_present'], $validated['ip_address_ids']);

        DB::transaction(function () use ($device, $validated, $request, $hasPicker, $ipIds): void {
            $device->update(Arr::except($validated, ['tags']));
            $this->syncAssetTags($device, $validated, $request);

            if ($hasPicker) {
                $this->syncIpLinks($device, $ipIds);
            }
        });

        return redirect()->route('admin.devices.show', $device)->with('success', 'Device updated.');
    }

    /**
     * Unlink a single IP address from a device.
     *
     * The IP row is the source of truth; the service clears both the
     * `device_id` pointer and the polymorphic assignment, writes the
     * allocation history and returns the address to the pool. A 404 is
     * returned when the address is not currently linked to this asset, so the
     * endpoint cannot be used to detach another asset's IP.
     */
    public function detachIp(Device $device, IpAddress $ipAddress): RedirectResponse
    {
        abort_unless((int) $ipAddress->device_id === (int) $device->id, 404);

        $this->ipAssignmentService->releaseFromAsset($ipAddress);

        return back()->with('success', 'IP unlinked from device.');
    }

    /**
     * Validation rule for a single picked IP id. An address that already
     * belongs to a different owner — another asset or a hosting account — is
     * rejected; one linked to this same asset (an existing chip) is allowed so
     * an unchanged form still validates. The service owns the conflict
     * wording so both IP-linked forms agree.
     *
     * @return array<int, mixed>
     */
    private function ipAddressIdRules(?Device $asset = null): array
    {
        return [
            function (string $attribute, mixed $value, Closure $fail) use ($asset): void {
                $ip = IpAddress::query()->find($value);

                if ($ip === null) {
                    return; // the exists rule reports a missing address
                }

                $conflict = $this->ipAssignmentService->linkConflictMessage($ip, (int) ($asset?->id ?? 0));

                if ($conflict !== null) {
                    $fail($conflict);
                }
            },
        ];
    }

    /**
     * Apply the picker's link changes for one asset inside a transaction.
     *
     * Chosen ids are assigned to this asset through the service (which also
     * maintains the polymorphic assignment columns and writes history); any
     * address currently linked to this asset and no longer chosen is released.
     * The whole switch is atomic so a partial link state is never visible.
     *
     * @param  array<int, mixed>  $ids
     */
    private function syncIpLinks(Device $asset, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        DB::transaction(function () use ($asset, $ids): void {
            foreach ($ids as $id) {
                $ip = IpAddress::query()->whereKey($id)->lockForUpdate()->first();

                if ($ip !== null) {
                    $this->ipAssignmentService->assignToAsset($ip, $asset);
                }
            }

            $stale = IpAddress::query()->where('device_id', $asset->id);
            if ($ids !== []) {
                $stale->whereNotIn('id', $ids);
            }
            $stale->lockForUpdate()->get()->each(
                fn (IpAddress $ip) => $this->ipAssignmentService->releaseFromAsset($ip),
            );
        });
    }

    public function destroy(Device $device): RedirectResponse
    {
        $this->deviceDeletionService->delete($device);

        return redirect()->route('admin.devices.index')->with('success', 'Device deleted.');
    }

    /**
     * Chips for the IP picker: when a submit bounced back on a validation
     * error, keep the addresses the user had selected; otherwise show the
     * asset's currently linked IPs.
     *
     * @return Collection<int, IpAddress>
     */
    private function linkedIpsForForm(?Device $asset): Collection
    {
        $submitted = collect(old('ip_address_ids'))
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($submitted->isNotEmpty()) {
            return IpAddress::query()->whereIn('id', $submitted)->with('subnet.vlan')->orderBy('ip_address')->get();
        }

        return $asset?->ipAddresses()->with('subnet.vlan')->orderBy('ip_address')->get() ?? collect();
    }

    /**
     * Shared view options for the create/edit forms.
     *
     * @param  Collection<int, Datacenter>  $datacenters
     * @param  Collection<int, Rack>  $racks
     * @return array<string, mixed>
     */
    private function formOptions($datacenters, $racks): array
    {
        return [
            'datacenters' => $datacenters,
            'racks' => $racks,
            'statuses' => Device::STATUSES,
            'manufacturers' => Manufacturer::orderBy('name')->get(),
            'deviceTypes' => DeviceType::query()
                ->select('device_types.*')
                ->with('manufacturer')
                ->join('manufacturers', 'manufacturers.id', '=', 'device_types.manufacturer_id')
                ->orderBy('manufacturers.name')
                ->orderBy('device_types.model')
                ->get(),
            'tags' => Tag::orderBy('name')->get(),
        ];
    }
}
