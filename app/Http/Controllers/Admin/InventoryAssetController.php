<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ValidatesInventoryAssetRules;
use App\Http\Controllers\Controller;
use App\Models\AssetRelationship;
use App\Models\Datacenter;
use App\Models\InventoryAsset;
use App\Models\IpAddress;
use App\Models\IpAllocationHistory;
use App\Models\PortConnection;
use App\Models\PortConnectionEvent;
use App\Models\Rack;
use App\Models\User;
use App\Services\Exports\CsvStreamService;
use App\Services\Inventory\InventoryAssetDeletionService;
use App\Services\IpAssignmentService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryAssetController extends Controller
{
    use ValidatesInventoryAssetRules;

    public function __construct(
        private readonly IpAssignmentService $ipAssignmentService,
        private readonly InventoryAssetDeletionService $inventoryAssetDeletionService,
    ) {}

    public function index(Request $request): View
    {
        $assets = $this->filteredQuery($request)
            ->gridSort([
                'asset_tag' => 'asset_tag',
                'asset_type' => 'asset_type',
                'serial_number' => 'serial_number',
                'model' => 'model',
                'datacenter' => 'datacenter.name',
                'rack' => 'rack.name',
                'status' => 'status',
            ])
            ->orderByDesc('id')->paginate(25)->withQueryString();
        $datacenters = Datacenter::orderBy('name')->get();
        $racks = Rack::orderBy('name')->get();

        return view('admin.inventory_assets.index', [
            'assets' => $assets,
            'datacenters' => $datacenters,
            'racks' => $racks,
            'assetTypes' => InventoryAsset::ASSET_TYPES,
            'statuses' => InventoryAsset::STATUSES,
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
        $query = InventoryAsset::with(['datacenter', 'rack']);
        if ($request->filled('datacenter_id')) {
            $query->where('datacenter_id', $request->query('datacenter_id'));
        }
        if ($request->filled('rack_id')) {
            $query->where('rack_id', $request->query('rack_id'));
        }
        if ($request->filled('asset_type')) {
            $query->where('asset_type', $request->query('asset_type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
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
        $filename = 'inventory-assets-'.now()->format('Ymd-His').'.csv';
        $csvHeaders = [
            'asset_tag', 'asset_type', 'serial_number', 'model', 'manufacturer',
            'vendor', 'status', 'datacenter', 'rack', 'rack_u_position',
            'purchase_date', 'purchase_cost', 'warranty_expiry',
        ];

        /** @var CsvStreamService $csv */
        $csv = app(CsvStreamService::class);

        return $csv->stream($filename, $csvHeaders, function ($handle) use ($request): void {
            $this->filteredQuery($request)->chunk(500, function ($assets) use ($handle): void {
                foreach ($assets as $asset) {
                    fputcsv($handle, [
                        $asset->asset_tag,
                        $asset->asset_type,
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
                    ]);
                }
            });
        });
    }

    /**
     * Typeahead search over every searchable asset field, used by the asset
     * picker on the show page's relationship forms.
     *
     * The label carries the tag and type; the meta line sums up the remaining
     * details a maintainer needs to tell two assets apart. Blank query returns
     * an empty result set without touching the database.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'exclude' => ['nullable', 'integer', 'min:1'],
        ]);

        $q = trim((string) ($validated['q'] ?? ''));
        if ($q === '') {
            return response()->json(['results' => []]);
        }

        $like = '%'.$q.'%';

        $assets = InventoryAsset::query()
            ->with(['datacenter:id,name', 'rack:id,name'])
            ->when(
                ($validated['exclude'] ?? null) !== null,
                fn (Builder $query) => $query->whereKeyNot((int) $validated['exclude']),
            )
            ->where(function (Builder $query) use ($like): void {
                $query->where('asset_tag', 'like', $like)
                    ->orWhere('serial_number', 'like', $like)
                    ->orWhere('model', 'like', $like)
                    ->orWhere('manufacturer', 'like', $like)
                    ->orWhere('vendor', 'like', $like)
                    ->orWhere('notes', 'like', $like)
                    ->orWhere('status', 'like', $like)
                    ->orWhere('asset_type', 'like', $like)
                    ->orWhereHas('datacenter', fn (Builder $datacenter) => $datacenter->where('name', 'like', $like))
                    ->orWhereHas('rack', fn (Builder $rack) => $rack->where('name', 'like', $like));
            })
            ->orderBy('asset_tag')
            ->limit(20)
            ->get([
                'id', 'asset_tag', 'asset_type', 'serial_number', 'model',
                'manufacturer', 'status', 'datacenter_id', 'rack_id',
            ]);

        return response()->json([
            'results' => $assets->map(fn (InventoryAsset $asset): array => [
                'id' => $asset->id,
                'label' => $asset->asset_tag.' — '.ucfirst($asset->asset_type),
                'meta' => $this->searchResultMeta($asset),
            ])->all(),
        ]);
    }

    /**
     * The compact one-line summary shown under an asset's label in the picker.
     * Empty parts are dropped so a sparse asset does not render stray bullets.
     */
    private function searchResultMeta(InventoryAsset $asset): string
    {
        $hardware = trim(implode(' ', array_filter([
            (string) $asset->manufacturer,
            (string) $asset->model,
        ], fn (string $part): bool => trim($part) !== '')));

        $parts = array_filter([
            $hardware,
            $asset->serial_number !== null ? 'SN '.$asset->serial_number : '',
            (string) $asset->status,
            (string) $asset->datacenter?->name,
            (string) $asset->rack?->name,
        ], fn (string $part): bool => trim($part) !== '');

        return implode(' · ', $parts);
    }

    public function bulkStatus(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'min:1', 'distinct', Rule::exists('inventory_assets', 'id')->whereNull('deleted_at')],
            'status' => ['required', 'string', Rule::in(InventoryAsset::STATUSES)],
        ]);

        $count = InventoryAsset::whereIn('id', $validated['ids'])
            ->update(['status' => $validated['status']]);

        return redirect()->back()->with(
            'success',
            'Updated '.$count.' inventory '.($count === 1 ? 'asset' : 'assets').'.',
        );
    }

    public function create(): View
    {
        $datacenters = Datacenter::orderBy('name')->get();
        $racks = Rack::orderBy('name')->get();

        return view('admin.inventory_assets.create', $this->formOptions($datacenters, $racks) + [
            'linkedIps' => $this->linkedIpsForForm(null),
            'ipTrackingTypes' => InventoryAsset::IP_TRACKING_TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->inventoryAssetStoreRules($request) + [
            'ip_picker_present' => ['sometimes', 'boolean'],
            'ip_address_ids' => ['nullable', 'array'],
            'ip_address_ids.*' => ['integer', 'exists:ip_addresses,id', ...$this->ipAddressIdRules()],
        ]);
        $validated['status'] = $validated['status'] ?? 'in_stock';

        $ipIds = (array) ($validated['ip_address_ids'] ?? []);
        $hasPicker = $request->boolean('ip_picker_present');
        unset($validated['ip_picker_present'], $validated['ip_address_ids']);

        $asset = InventoryAsset::create($validated);

        if ($hasPicker) {
            $this->syncIpLinks($asset, $ipIds);
        }

        return redirect()->route('admin.inventory-assets.index')->with('success', 'Inventory asset created.');
    }

    public function show(InventoryAsset $inventoryAsset): View
    {
        $inventoryAsset->load(['datacenter', 'rack', 'parent', 'ipAddresses.subnet.vlan']);

        // Children / parents come from the canonical asset_relationships graph,
        // not the legacy parent_asset_id relation, so the card and the
        // dependency tree page agree.
        $childLinks = AssetRelationship::query()
            ->where('parent_kind', 'inventory_asset')
            ->where('parent_id', $inventoryAsset->id)
            ->where('child_kind', 'inventory_asset')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $parentLinks = AssetRelationship::query()
            ->where('child_kind', 'inventory_asset')
            ->where('child_id', $inventoryAsset->id)
            ->where('parent_kind', 'inventory_asset')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        // Resolve every related asset tag for both sides in a single query.
        $relatedIds = $childLinks->pluck('child_id')
            ->merge($parentLinks->pluck('parent_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        // Batch-load every related asset for both sides in a single query so the
        // relationship tables can show the full details of each linked asset
        // without an N+1 per row.
        $relatedAssets = $relatedIds === []
            ? collect()
            : InventoryAsset::query()
                ->with(['datacenter:id,name', 'rack:id,name'])
                ->whereKey($relatedIds)
                ->get([
                    'id', 'asset_tag', 'asset_type', 'serial_number', 'model',
                    'manufacturer', 'status', 'datacenter_id', 'rack_id', 'rack_u_position',
                ])
                ->keyBy('id');

        // Modals still resolve a tag for their message; derive it from the
        // loaded collection instead of re-querying.
        $relatedAssetTags = $relatedAssets->pluck('asset_tag', 'id');

        $recentIpHistory = IpAllocationHistory::query()
            ->with('ipAddress')
            ->whereIn('ip_address_id', $inventoryAsset->ipAddresses->pluck('id'))
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

        if (in_array($inventoryAsset->asset_type, InventoryAsset::PORT_BEARING_TYPES, true) || $inventoryAsset->ports()->exists()) {
            $ports = $inventoryAsset->ports()->orderBy('name')->get();
            $portIds = $ports->pluck('id')->all();

            if ($portIds !== []) {
                $connections = PortConnection::query()
                    ->with([
                        'portA.inventoryAsset:id,asset_tag',
                        'portB.inventoryAsset:id,asset_tag',
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
                            'peerAssetTag' => $connection->portB?->inventoryAsset?->asset_tag,
                        ];
                    }

                    if (in_array((int) $connection->port_b_id, $portIds, true)) {
                        $connectionMap[(int) $connection->port_b_id] = [
                            'connection' => $connection,
                            'peerPort' => $connection->portA,
                            'peerAssetTag' => $connection->portA?->inventoryAsset?->asset_tag,
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

        return view('admin.inventory_assets.show', compact(
            'inventoryAsset',
            'recentIpHistory',
            'childLinks',
            'parentLinks',
            'relatedAssets',
            'relatedAssetTags',
            'ports',
            'connectionMap',
            'recentCableEvents',
            'cableEventUsers',
        ));
    }

    public function edit(InventoryAsset $inventoryAsset): View
    {
        $datacenters = Datacenter::orderBy('name')->get();
        $racks = Rack::orderBy('name')->get();

        return view('admin.inventory_assets.edit', [
            'inventoryAsset' => $inventoryAsset,
            'linkedIps' => $this->linkedIpsForForm($inventoryAsset),
            'ipTrackingTypes' => InventoryAsset::IP_TRACKING_TYPES,
        ] + $this->formOptions($datacenters, $racks));
    }

    public function update(Request $request, InventoryAsset $inventoryAsset): RedirectResponse
    {
        $validated = $request->validate($this->inventoryAssetUpdateRules($request, $inventoryAsset) + [
            'ip_picker_present' => ['sometimes', 'boolean'],
            'ip_address_ids' => ['nullable', 'array'],
            'ip_address_ids.*' => ['integer', 'exists:ip_addresses,id', ...$this->ipAddressIdRules($inventoryAsset)],
        ]);

        $this->validateRackTransition($inventoryAsset, $validated);

        $ipIds = (array) ($validated['ip_address_ids'] ?? []);
        $hasPicker = $request->boolean('ip_picker_present');
        unset($validated['ip_picker_present'], $validated['ip_address_ids']);

        $inventoryAsset->update($validated);

        if ($hasPicker) {
            $this->syncIpLinks($inventoryAsset, $ipIds);
        }

        return redirect()->route('admin.inventory-assets.show', $inventoryAsset)->with('success', 'Inventory asset updated.');
    }

    /**
     * Unlink a single IP address from an inventory asset.
     *
     * The IP row is the source of truth; the service clears both the
     * `inventory_asset_id` pointer and the polymorphic assignment, writes the
     * allocation history and returns the address to the pool. A 404 is
     * returned when the address is not currently linked to this asset, so the
     * endpoint cannot be used to detach another asset's IP.
     */
    public function detachIp(InventoryAsset $inventoryAsset, IpAddress $ipAddress): RedirectResponse
    {
        abort_unless((int) $ipAddress->inventory_asset_id === (int) $inventoryAsset->id, 404);

        $this->ipAssignmentService->releaseFromAsset($ipAddress);

        return back()->with('success', 'IP unlinked from asset.');
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
    private function ipAddressIdRules(?InventoryAsset $asset = null): array
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
    private function syncIpLinks(InventoryAsset $asset, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        DB::transaction(function () use ($asset, $ids): void {
            foreach ($ids as $id) {
                $ip = IpAddress::query()->whereKey($id)->lockForUpdate()->first();

                if ($ip !== null) {
                    $this->ipAssignmentService->assignToAsset($ip, $asset);
                }
            }

            $stale = IpAddress::query()->where('inventory_asset_id', $asset->id);
            if ($ids !== []) {
                $stale->whereNotIn('id', $ids);
            }
            $stale->lockForUpdate()->get()->each(
                fn (IpAddress $ip) => $this->ipAssignmentService->releaseFromAsset($ip),
            );
        });
    }

    public function destroy(InventoryAsset $inventoryAsset): RedirectResponse
    {
        $this->inventoryAssetDeletionService->delete($inventoryAsset);

        return redirect()->route('admin.inventory-assets.index')->with('success', 'Inventory asset deleted.');
    }

    /**
     * Chips for the IP picker: when a submit bounced back on a validation
     * error, keep the addresses the user had selected; otherwise show the
     * asset's currently linked IPs.
     *
     * @return Collection<int, IpAddress>
     */
    private function linkedIpsForForm(?InventoryAsset $asset): Collection
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
            'assetTypes' => InventoryAsset::ASSET_TYPES,
            'statuses' => InventoryAsset::STATUSES,
        ];
    }
}
