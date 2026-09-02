<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DnsZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DnsZoneController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));

        $query = DnsZone::query()->withCount('records');

        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }

        // The grid keys are the view-facing labels; the values are the real
        // dns_zones columns (the zone domain is stored as `name`).
        $zones = $query
            ->gridSort([
                'domain' => 'name',
                'type' => 'zone_type',
                'records_count' => 'records_count',
                'status' => 'status',
                'created_at' => 'created_at',
            ])
            ->orderByDesc('id')->paginate(20)->withQueryString();

        return view('admin.dns_zones.index', compact('zones', 'search'));
    }

    public function create(): View
    {
        return view('admin.dns_zones.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
            'type' => ['sometimes', 'string', 'in:master,slave,forwarder'],
            'primary_ns' => ['nullable', 'string', 'max:255'],
            'admin_email' => ['nullable', 'string', 'max:255'],
            'serial' => ['nullable', 'integer', 'min:1'],
            'refresh' => ['nullable', 'integer', 'min:0'],
            'retry' => ['nullable', 'integer', 'min:0'],
            'expire' => ['nullable', 'integer', 'min:0'],
            'minimum_ttl' => ['nullable', 'integer', 'min:0'],
            'status' => ['sometimes', 'string', 'in:active,disabled'],
        ]);
        $validated['type'] = $validated['type'] ?? 'master';
        $validated['status'] = $validated['status'] ?? 'active';
        DnsZone::create($validated);

        return redirect()->route('admin.dns-zones.index')->with('success', 'DNS zone created.');
    }

    public function show(DnsZone $dnsZone): View
    {
        $dnsZone->load('records');

        return view('admin.dns_zones.show', compact('dnsZone'));
    }

    public function edit(DnsZone $dnsZone): View
    {
        return view('admin.dns_zones.edit', compact('dnsZone'));
    }

    public function update(Request $request, DnsZone $dnsZone): RedirectResponse
    {
        $validated = $request->validate([
            'primary_ns' => ['nullable', 'string', 'max:255'],
            'admin_email' => ['nullable', 'string', 'max:255'],
            'serial' => ['nullable', 'integer', 'min:1'],
            'refresh' => ['nullable', 'integer', 'min:0'],
            'retry' => ['nullable', 'integer', 'min:0'],
            'expire' => ['nullable', 'integer', 'min:0'],
            'minimum_ttl' => ['nullable', 'integer', 'min:0'],
            'status' => ['sometimes', 'string', 'in:active,disabled'],
        ]);
        $dnsZone->update($validated);

        return redirect()->route('admin.dns-zones.show', $dnsZone)->with('success', 'DNS zone updated.');
    }

    public function destroy(DnsZone $dnsZone): RedirectResponse
    {
        $dnsZone->delete();

        return redirect()->route('admin.dns-zones.index')->with('success', 'DNS zone deleted.');
    }
}
