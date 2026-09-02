<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Datacenter;
use App\Models\IpSubnet;
use App\Models\Vlan;
use App\Services\SubnetIpGeneratorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * IP subnet CRUD — enterprise network module (Session 3B.2).
 */
class IpSubnetController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));

        $query = IpSubnet::with(['datacenter', 'vlan'])->withCount('ipAddresses');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('subnet_cidr', 'like', "%{$search}%")
                    ->orWhere('gateway', 'like', "%{$search}%");
            });
        }

        if ($request->filled('datacenter_id')) {
            $query->where('datacenter_id', $request->query('datacenter_id'));
        }
        if ($request->filled('ip_version')) {
            $query->where('ip_version', $request->query('ip_version'));
        }

        $subnets = $query
            ->gridSort([
                'name' => 'name',
                'subnet_cidr' => 'subnet_cidr',
                'gateway' => 'gateway',
                'ip_version' => 'ip_version',
                'network_type' => 'network_type',
                'total_addresses' => 'total_addresses',
                'vlan' => 'vlan.name',
                'datacenter' => 'datacenter.name',
                'id' => 'id',
                'created_at' => 'created_at',
            ])
            ->orderByDesc('id')->paginate(20)->withQueryString();
        $datacenters = Datacenter::orderBy('name')->get();

        return view('admin.ip_subnets.index', compact('subnets', 'datacenters', 'search'));
    }

    public function create(): View
    {
        $datacenters = Datacenter::orderBy('name')->get();
        $vlans = Vlan::orderBy('name')->get();

        return view('admin.ip_subnets.create', compact('datacenters', 'vlans'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'subnet_cidr' => ['required', 'string', 'max:50'],
            'gateway' => ['nullable', 'string', 'max:50'],
            'netmask' => ['nullable', 'string', 'max:50'],
            'ip_version' => ['sometimes', 'string', 'in:4,6'],
            'network_type' => ['sometimes', 'string', 'in:private,public'],
            'vlan_id' => ['nullable', 'integer', 'exists:vlans,id'],
            'datacenter_id' => ['nullable', 'integer', 'exists:datacenters,id'],
            'total_addresses' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
        ]);
        $validated['ip_version'] = $validated['ip_version'] ?? '4';
        $validated['network_type'] = $validated['network_type'] ?? 'private';
        $validated['status'] = $validated['status'] ?? 'active';

        IpSubnet::create($validated);

        return redirect()->route('admin.ip-subnets.index')->with('success', 'IP subnet created.');
    }

    public function show(IpSubnet $ipSubnet): View
    {
        $ipSubnet->load(['datacenter', 'vlan', 'ipAddresses']);

        return view('admin.ip_subnets.show', compact('ipSubnet'));
    }

    public function edit(IpSubnet $ipSubnet): View
    {
        $datacenters = Datacenter::orderBy('name')->get();
        $vlans = Vlan::orderBy('name')->get();

        return view('admin.ip_subnets.edit', compact('ipSubnet', 'datacenters', 'vlans'));
    }

    public function update(Request $request, IpSubnet $ipSubnet): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'subnet_cidr' => ['sometimes', 'string', 'max:50'],
            'gateway' => ['nullable', 'string', 'max:50'],
            'netmask' => ['nullable', 'string', 'max:50'],
            'ip_version' => ['sometimes', 'string', 'in:4,6'],
            'network_type' => ['sometimes', 'string', 'in:private,public'],
            'vlan_id' => ['nullable', 'integer', 'exists:vlans,id'],
            'datacenter_id' => ['nullable', 'integer', 'exists:datacenters,id'],
            'total_addresses' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
        ]);
        $ipSubnet->update($validated);

        return redirect()->route('admin.ip-subnets.show', $ipSubnet)->with('success', 'IP subnet updated.');
    }

    public function destroy(IpSubnet $ipSubnet): RedirectResponse
    {
        $assignedCount = $ipSubnet->ipAddresses()->whereNotNull('assigned_to_type')->count();
        if ($assignedCount > 0) {
            return redirect()->route('admin.ip-subnets.show', $ipSubnet)->withErrors(['delete' => "Cannot delete subnet {$ipSubnet->subnet_cidr} — {$assignedCount} IP(s) are still assigned to services/servers. Release them first."]);
        }

        DB::transaction(function () use ($ipSubnet) {
            $ipIds = $ipSubnet->ipAddresses()->pluck('id');
            if ($ipIds->isNotEmpty()) {
                DB::table('ip_allocation_history')->whereIn('ip_address_id', $ipIds)->delete();
            }
            $ipSubnet->ipAddresses()->delete();
            $ipSubnet->delete();
        });

        return redirect()->route('admin.ip-subnets.index')->with('success', 'IP subnet deleted along with its IPs.');
    }

    public function generateIps(Request $request, IpSubnet $ipSubnet): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['sometimes', 'string', 'in:available,reserved,gateway,broadcast,network,assigned,floating,nat'],
            'exclude_network_broadcast' => ['sometimes', 'boolean'],
            'exclude_gateway' => ['sometimes', 'boolean'],
        ]);

        try {
            $service = app(SubnetIpGeneratorService::class);
            $result = $service->generate($ipSubnet, [
                'type' => $validated['type'] ?? 'available',
                'exclude_network_broadcast' => (bool) ($validated['exclude_network_broadcast'] ?? true),
                'exclude_gateway' => (bool) ($validated['exclude_gateway'] ?? true),
            ]);
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('admin.ip-subnets.show', $ipSubnet)->withErrors(['generate' => $e->getMessage()]);
        }

        if ($result['created'] === 0) {
            return redirect()->route('admin.ip-subnets.show', $ipSubnet)->with('success', "No new IPs generated — {$result['skipped']} already exist, {$result['total']} total usable.");
        }

        return redirect()->route('admin.ip-subnets.show', $ipSubnet)->with('success', "Generated {$result['created']} IP(s) — {$result['skipped']} skipped (already exist).");
    }
}
