<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\NoAvailableIpException;
use App\Http\Controllers\Controller;
use App\Models\HostingAccount;
use App\Models\IpAddress;
use App\Models\IpSubnet;
use App\Services\IpAssignmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IpAddressController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));

        $query = IpAddress::with(['subnet.vlan', 'subnet.datacenter', 'assignedTo']);
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
        $vlans = \App\Models\Vlan::orderBy('vlan_id')->get();

        return view('admin.ip_addresses.index', compact('addresses', 'subnets', 'vlans', 'search'));
    }

    public function create(): View
    {
        $subnets = IpSubnet::orderBy('subnet_cidr')->get();

        return view('admin.ip_addresses.create', compact('subnets'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'subnet_id' => ['nullable', 'integer', 'exists:ip_subnets,id'],
            'ip_address' => ['required', 'string', 'max:45'],
            'ip_version' => ['sometimes', 'string', 'in:4,6'],
            'type' => ['sometimes', 'string', 'in:'.implode(',', IpAddress::TYPES)],
            'ptr_record' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $validated['ip_version'] = $validated['ip_version'] ?? '4';
        $validated['type'] = $validated['type'] ?? 'available';
        IpAddress::create($validated);

        return redirect()->route('admin.ip-addresses.index')->with('success', 'IP address created.');
    }

    public function show(IpAddress $ipAddress): View
    {
        $ipAddress->load(['subnet.vlan', 'subnet.datacenter', 'assignedTo']);

        $hostingAccounts = HostingAccount::with(['customer.user:id,first_name,last_name,company', 'product:id,name'])
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $history = \App\Models\IpAllocationHistory::where('ip_address_id', $ipAddress->id)
            ->orderByDesc('changed_at')
            ->limit(20)
            ->get();

        return view('admin.ip_addresses.show', compact('ipAddress', 'hostingAccounts', 'history'));
    }

    public function edit(IpAddress $ipAddress): View
    {
        $subnets = IpSubnet::orderBy('subnet_cidr')->get();

        return view('admin.ip_addresses.edit', compact('ipAddress', 'subnets'));
    }

    public function update(Request $request, IpAddress $ipAddress): RedirectResponse
    {
        $validated = $request->validate([
            'subnet_id' => ['nullable', 'integer', 'exists:ip_subnets,id'],
            'ip_address' => ['sometimes', 'string', 'max:45'],
            'type' => ['sometimes', 'string', 'in:'.implode(',', IpAddress::TYPES)],
            'ptr_record' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $ipAddress->update($validated);

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
            $ipAddress->update(['type' => 'assigned']);
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

        if ($accountType === HostingAccount::class && $accountId) {
            $account = HostingAccount::find($accountId);
            if ($account) {
                $service->release($account, "Released via IPAM panel by ".(auth()->user()->email ?? 'admin'), $ipAddress->id);
            } else {
                $ipAddress->update(['assigned_to_type' => null, 'assigned_to_id' => null]);
            }
        } else {
            $snapshot = json_encode($ipAddress->getAttributes());
            $ipAddress->update(['assigned_to_type' => null, 'assigned_to_id' => null]);
            \App\Models\IpAllocationHistory::create([
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
}
