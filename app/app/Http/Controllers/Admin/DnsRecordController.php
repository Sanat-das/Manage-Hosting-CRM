<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DnsRecordController extends Controller
{
    public function index(DnsZone $dnsZone, Request $request): View
    {
        $query = $dnsZone->records();
        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }
        $records = $query->orderBy('name')->orderBy('type')->paginate(50)->withQueryString();

        return view('admin.dns_records.index', compact('dnsZone', 'records'));
    }

    public function create(DnsZone $dnsZone): View
    {
        return view('admin.dns_records.create', compact('dnsZone'));
    }

    public function store(Request $request, DnsZone $dnsZone): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:A,AAAA,CNAME,MX,NS,TXT,SRV,PTR,SOA,CAA'],
            'ttl' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'content' => ['required', 'string', 'max:1000'],
        ]);
        $validated['ttl'] = $validated['ttl'] ?? 3600;
        $validated['dns_zone_id'] = $dnsZone->id;
        DnsRecord::create($validated);

        return redirect()->route('admin.dns-zones.records.index', $dnsZone)->with('success', 'DNS record created.');
    }

    public function show(DnsZone $dnsZone, DnsRecord $dnsRecord): View
    {
        return view('admin.dns_records.show', compact('dnsZone', 'dnsRecord'));
    }

    public function edit(DnsZone $dnsZone, DnsRecord $dnsRecord): View
    {
        return view('admin.dns_records.edit', compact('dnsZone', 'dnsRecord'));
    }

    public function update(Request $request, DnsZone $dnsZone, DnsRecord $dnsRecord): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', 'in:A,AAAA,CNAME,MX,NS,TXT,SRV,PTR,SOA,CAA'],
            'ttl' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'content' => ['sometimes', 'string', 'max:1000'],
        ]);
        $dnsRecord->update($validated);

        return redirect()->route('admin.dns-zones.records.index', $dnsZone)->with('success', 'DNS record updated.');
    }

    public function destroy(DnsZone $dnsZone, DnsRecord $dnsRecord): RedirectResponse
    {
        $dnsRecord->delete();

        return redirect()->route('admin.dns-zones.records.index', $dnsZone)->with('success', 'DNS record deleted.');
    }
}
