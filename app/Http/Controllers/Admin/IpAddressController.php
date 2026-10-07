<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\NoAvailableIpException;
use App\Http\Controllers\Controller;
use App\Models\HostingAccount;
use App\Models\InventoryAsset;
use App\Models\IpAddress;
use App\Models\IpAllocationHistory;
use App\Models\IpSubnet;
use App\Models\Vlan;
use App\Services\IpAssignmentService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IpAddressController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));

        $query = IpAddress::with(['subnet.vlan', 'subnet.datacenter', 'inventoryAsset:id,asset_tag']);
        if ($request->filled('subnet_id')) {
            $query->where('subnet_id', $request->query('subnet_id'));
        }
        if ($request->filled('vlan_id')) {
            $vlanId = $request->query('vlan_id');
            $query->whereHas('subnet', function ($q) use ($vlanId) {
                $q->where('vlan_id', $vlanId);
            });
        }
        if ($search !== '') {
            $q = $search;
            $query->where(function ($query) use ($q) {
                $query->where('ip_address', 'like', "%{$q}%")
                    ->orWhere('ptr_record', 'like', "%{$q}%")
                    ->orWhere('type', 'like', "%{$q}%")
                    ->orWhereHas('subnet', function ($sq) use ($q) {
                        $sq->where('subnet_cidr', 'like', "%{$q}%")
                            ->orWhere('name', 'like', "%{$q}%");
                    });
            });
        }
        $addresses = $query
            ->gridSort([
                'ip_address' => 'ip_address',
                'ip_version' => 'ip_version',
                'subnet' => 'subnet.subnet_cidr',
                'vlan' => function (Builder $q, string $dir): void {
                    $q->orderBy(IpSubnet::select('vlan_id')->whereColumn('ip_subnets.id', 'ip_addresses.subnet_id'), $dir);
                },
                'ptr_record' => 'ptr_record',
                'status' => 'type',
                'assigned_to' => 'assigned_to_id',
            ])
            ->orderByDesc('id')->paginate(30)->withQueryString();
        $subnets = IpSubnet::orderBy('subnet_cidr')->get();
        $vlans = Vlan::orderBy('vlan_id')->get();

        return view('admin.ip_addresses.index', compact('addresses', 'subnets', 'vlans', 'search'));
    }

    /**
     * Typeahead search over IP Manager addresses, used by the asset form's IP
     * picker. Matches the IP, PTR, notes and type as well as the owning
     * subnet's name/CIDR. A blank query returns an empty set without a query,
     * and results are capped and ordered like the inventory asset picker.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $q = trim((string) ($validated['q'] ?? ''));
        if ($q === '') {
            return response()->json(['results' => []]);
        }

        $like = '%'.$q.'%';

        $addresses = IpAddress::query()
            ->with(['subnet:id,name,subnet_cidr,vlan_id', 'subnet.vlan:id,name,vlan_id', 'inventoryAsset:id,asset_tag'])
            ->where(function (Builder $query) use ($like): void {
                $query->where('ip_address', 'like', $like)
                    ->orWhere('ptr_record', 'like', $like)
                    ->orWhere('notes', 'like', $like)
                    ->orWhere('type', 'like', $like)
                    ->orWhereHas('subnet', fn (Builder $subnet) => $subnet
                        ->where('name', 'like', $like)
                        ->orWhere('subnet_cidr', 'like', $like));
            })
            ->orderBy('ip_address')
            ->limit(20)
            ->get([
                'id', 'ip_address', 'type', 'assigned_to_type', 'subnet_id',
                'inventory_asset_id', 'last_seen_at',
            ]);

        return response()->json([
            'results' => $addresses->map(fn (IpAddress $ip): array => [
                'id' => $ip->id,
                'label' => $this->searchResultLabel($ip),
                'meta' => $this->searchResultMeta($ip),
            ])->all(),
        ]);
    }

    /**
     * The label shown for an IP in the picker: the address, the owning subnet
     * name, and the subnet's VLAN when one is set. The VLAN uses the panel's
     * `name (vlan_id)` convention, falling back to `VLAN {vlan_id}` when the
     * name is blank; the segment is omitted when there is no subnet or VLAN.
     */
    private function searchResultLabel(IpAddress $ip): string
    {
        $label = $ip->subnet?->name
            ? $ip->ip_address.' — '.$ip->subnet->name
            : $ip->ip_address;

        $vlan = $ip->subnet?->vlan;

        if ($vlan === null) {
            return $label;
        }

        $vlanName = trim((string) $vlan->name);

        return $label.' · '.($vlanName !== '' ? $vlanName : 'VLAN '.$vlan->vlan_id).' ('.$vlan->vlan_id.')';
    }

    /**
     * The compact one-line summary shown under an IP's label in the picker.
     * Empty parts are dropped so a sparse address renders no stray bullets.
     */
    private function searchResultMeta(IpAddress $ip): string
    {
        $isInventoryAssigned = $ip->assigned_to_type === 'inventory' && $ip->inventoryAsset?->asset_tag !== null;

        $assignment = $ip->inventoryAsset?->asset_tag !== null
            ? 'Asset '.$ip->inventoryAsset->asset_tag
            : ($ip->is_assigned ? 'assigned' : '');

        $parts = array_filter([
            $isInventoryAssigned ? 'assigned' : (string) $ip->type,
            ! $isInventoryAssigned && $ip->status !== $ip->type ? (string) $ip->status : '',
            $assignment,
            $ip->last_seen_at !== null ? 'last seen '.$ip->last_seen_at->format('Y-m-d H:i') : '',
        ], fn (string $part): bool => trim($part) !== '');

        return implode(' · ', $parts);
    }

    public function create(): View
    {
        $subnets = IpSubnet::orderBy('subnet_cidr')->get();
        $inventoryAssets = $this->inventoryAssetOptions();

        return view('admin.ip_addresses.create', compact('subnets', 'inventoryAssets'));
    }

    public function store(Request $request, IpAssignmentService $service): RedirectResponse
    {
        $validated = $request->validate([
            'subnet_id' => ['nullable', 'integer', 'exists:ip_subnets,id'],
            'ip_address' => ['required', 'string', 'max:45'],
            'ip_version' => ['sometimes', 'string', 'in:4,6'],
            'type' => ['sometimes', 'string', 'in:'.implode(',', IpAddress::TYPES)],
            'inventory_asset_id' => ['nullable', 'integer', 'exists:inventory_assets,id', ...$this->inventoryAssetIdRules($service, null)],
            'ptr_record' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $validated['ip_version'] = $validated['ip_version'] ?? '4';
        $validated['type'] = $validated['type'] ?? 'available';
        $ipAddress = IpAddress::create($validated);

        if (($validated['inventory_asset_id'] ?? null) !== null) {
            $service->assignToAsset($ipAddress, InventoryAsset::findOrFail((int) $validated['inventory_asset_id']));
        }

        return redirect()->route('admin.ip-addresses.index')->with('success', 'IP address created.');
    }

    public function show(IpAddress $ipAddress): View
    {
        $ipAddress->load(['subnet.vlan', 'subnet.datacenter', 'inventoryAsset']);

        $hostingAccounts = HostingAccount::with(['customer.user:id,first_name,last_name,company', 'product:id,name'])
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $history = IpAllocationHistory::where('ip_address_id', $ipAddress->id)
            ->orderByDesc('changed_at')
            ->limit(20)
            ->get();

        return view('admin.ip_addresses.show', compact('ipAddress', 'hostingAccounts', 'history'));
    }

    public function edit(IpAddress $ipAddress): View
    {
        $subnets = IpSubnet::orderBy('subnet_cidr')->get();
        $inventoryAssets = $this->inventoryAssetOptions();

        return view('admin.ip_addresses.edit', compact('ipAddress', 'subnets', 'inventoryAssets'));
    }

    public function update(Request $request, IpAddress $ipAddress, IpAssignmentService $service): RedirectResponse
    {
        $validated = $request->validate([
            'subnet_id' => ['nullable', 'integer', 'exists:ip_subnets,id'],
            'ip_address' => ['sometimes', 'string', 'max:45'],
            'type' => ['sometimes', 'string', 'in:'.implode(',', IpAddress::TYPES)],
            'inventory_asset_id' => ['nullable', 'integer', 'exists:inventory_assets,id', ...$this->inventoryAssetIdRules($service, $ipAddress)],
            'ptr_record' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $previousAssetId = $ipAddress->inventory_asset_id;
        $ipAddress->update($validated);

        if (array_key_exists('inventory_asset_id', $validated)) {
            $newAssetId = $validated['inventory_asset_id'];

            if ($newAssetId !== null && (int) $newAssetId !== (int) $previousAssetId) {
                $service->assignToAsset($ipAddress, InventoryAsset::findOrFail((int) $newAssetId));
            } elseif ($newAssetId === null && $previousAssetId !== null) {
                $service->releaseFromAsset($ipAddress);
            }
        }

        return redirect()->route('admin.ip-addresses.show', $ipAddress)->with('success', 'IP address updated.');
    }

    public function assign(Request $request, IpAddress $ipAddress, IpAssignmentService $service): RedirectResponse
    {
        $validated = $request->validate([
            'hosting_account_id' => ['required', 'integer', 'exists:hosting_accounts,id'],
        ]);

        if ($ipAddress->assigned_to_type !== null) {
            return back()->withErrors(['assign' => "IP {$ipAddress->ip_address} is already assigned to ".class_basename($ipAddress->assigned_to_type)." #{$ipAddress->assigned_to_id}. Release it first."]);
        }

        $account = HostingAccount::findOrFail($validated['hosting_account_id']);

        try {
            $service->assignSpecific($account, $ipAddress->id);
        } catch (NoAvailableIpException $e) {
            return back()->withErrors(['assign' => $e->getMessage()]);
        }

        return redirect()->route('admin.ip-addresses.show', $ipAddress)->with('success', "IP {$ipAddress->ip_address} assigned to hosting account #{$account->id} ({$account->domain}). Status is now assigned.");
    }

    public function release(IpAddress $ipAddress, IpAssignmentService $service): RedirectResponse
    {
        if ($ipAddress->assigned_to_type === null) {
            return back()->withErrors(['assign' => "IP {$ipAddress->ip_address} is not assigned."]);
        }

        $accountId = $ipAddress->assigned_to_id;
        $accountType = $ipAddress->assigned_to_type;

        if ($accountType === 'inventory') {
            $service->releaseFromAsset($ipAddress, 'Released via IPAM panel by '.(auth()->user()->email ?? 'admin'));

            return redirect()->route('admin.ip-addresses.show', $ipAddress)->with('success', "IP {$ipAddress->ip_address} released. Status is now available.");
        }

        if ($accountType === HostingAccount::class && $accountId) {
            $account = HostingAccount::find($accountId);
            if ($account) {
                $service->release($account, 'Released via IPAM panel by '.(auth()->user()->email ?? 'admin'), $ipAddress->id);
            } else {
                $ipAddress->update(['assigned_to_type' => null, 'assigned_to_id' => null]);
            }
        } else {
            $snapshot = json_encode($ipAddress->getAttributes());
            $ipAddress->update(['assigned_to_type' => null, 'assigned_to_id' => null]);
            IpAllocationHistory::create([
                'ip_address_id' => $ipAddress->id,
                'action' => 'released',
                'previous_assigned_to_type' => $accountType,
                'previous_assigned_to_id' => $accountId,
                'new_assigned_to_type' => null,
                'new_assigned_to_id' => null,
                'changed_by_user_id' => auth()->id(),
                'ip_address_snapshot' => $snapshot,
                'changed_at' => now(),
                'notes' => "Released IP {$ipAddress->ip_address} via IPAM panel",
            ]);
        }

        $ipAddress->update(['type' => 'available']);

        return redirect()->route('admin.ip-addresses.show', $ipAddress)->with('success', "IP {$ipAddress->ip_address} released. Status is now available.");
    }

    public function destroy(IpAddress $ipAddress): RedirectResponse
    {
        if ($ipAddress->assigned_to_type !== null) {
            return back()->withErrors(['delete' => "Cannot delete IP {$ipAddress->ip_address} — it is currently assigned to ".class_basename($ipAddress->assigned_to_type)." #{$ipAddress->assigned_to_id}. Release it first."]);
        }

        $ipAddress->delete();

        return redirect()->route('admin.ip-addresses.index')->with('success', 'IP address deleted.');
    }

    /**
     * Validation rule for the IP form's inventory_asset_id select, shared by
     * store and update. The owner conflict wording comes from the assignment
     * service so both IP-linked forms agree. On store there is no existing
     * address to conflict with, so the check is a no-op.
     *
     * @return array<int, mixed>
     */
    private function inventoryAssetIdRules(IpAssignmentService $service, ?IpAddress $ip): array
    {
        return [
            function (string $attribute, mixed $value, Closure $fail) use ($service, $ip): void {
                if ($ip === null || $value === null || $value === '') {
                    return;
                }

                $conflict = $service->linkConflictMessage($ip, (int) $value);

                if ($conflict !== null) {
                    $fail($conflict);
                }
            },
        ];
    }

    /**
     * Inventory assets offered for IP linkage (id + asset_tag), capped like the
     * hosting asset context so the select stays reasonable on large inventories.
     *
     * @return Collection<int, InventoryAsset>
     */
    private function inventoryAssetOptions(): Collection
    {
        return InventoryAsset::query()
            ->orderBy('asset_tag')
            ->limit(200)
            ->get(['id', 'asset_tag']);
    }
}
