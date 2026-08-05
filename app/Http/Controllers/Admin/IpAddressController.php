<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IpAddress;
use App\Models\IpSubnet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IpAddressController extends Controller
{
    public function index(Request $request): View
    {
        $query = IpAddress::with('subnet');
        if ($request->filled('subnet_id')) {
            $query->where('subnet_id', $request->query('subnet_id'));
        }
        if ($request->filled('search')) {
            $q = $request->query('search');
            $query->where('ip_address', 'like', "%{$q}%");
        }
        $addresses = $query->orderByDesc('id')->paginate(30)->withQueryString();
        $subnets = IpSubnet::orderBy('subnet_cidr')->get();
        return view('admin.ip_addresses.index', compact('addresses', 'subnets'));
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
            'type' => ['sometimes', 'string', 'in:private,public,reserved'],
            'ptr_record' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $validated['ip_version'] = $validated['ip_version'] ?? '4';
        $validated['type'] = $validated['type'] ?? 'private';
        IpAddress::create($validated);
        return redirect()->route('admin.ip-addresses.index')->with('success', 'IP address created.');
    }

    public function show(IpAddress $ipAddress): View
    {
        $ipAddress->load('subnet');
        return view('admin.ip_addresses.show', compact('ipAddress'));
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
            'type' => ['sometimes', 'string', 'in:private,public,reserved'],
            'ptr_record' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $ipAddress->update($validated);
        return redirect()->route('admin.ip-addresses.show', $ipAddress)->with('success', 'IP address updated.');
    }

    public function destroy(IpAddress $ipAddress): RedirectResponse
    {
        $ipAddress->delete();
        return redirect()->route('admin.ip-addresses.index')->with('success', 'IP address deleted.');
    }
}
