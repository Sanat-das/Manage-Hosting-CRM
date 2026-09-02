@extends('adminlte::page')
@section('title', 'IP Subnet — '.$ipSubnet->name)
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">{{ $ipSubnet->name }}</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.ip-subnets.index') }}">IP Subnets</a></li><li class="breadcrumb-item active">{{ $ipSubnet->name }}</li></ol></div></div>
@stop
@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    @if ($errors->has('generate')) <x-adminlte-alert theme="danger" dismissible>{{ $errors->first('generate') }}</x-adminlte-alert> @endif

    @if ($errors->has('delete')) <x-adminlte-alert theme="danger" dismissible>{{ $errors->first('delete') }}</x-adminlte-alert> @endif

    <div class="d-flex justify-content-end gap-2 mb-3">
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#generateIpsModal"><i class="bi bi-lightning me-1"></i> Generate IPs</button>
        <a href="{{ route('admin.ip-subnets.edit', $ipSubnet) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i> Edit</a>
        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#delete-subnet-modal">
            <i class="bi bi-trash me-1"></i> Delete Subnet
        </button>
    </div>

    <x-adminlte.partials.confirm-modal
        id="delete-subnet-modal"
        title="Delete subnet"
        :message="'Delete subnet ' . $ipSubnet->subnet_cidr . ' and all ' . $ipSubnet->ipAddresses->count() . ' IPs? This cannot be undone.'"
        :action="route('admin.ip-subnets.destroy', $ipSubnet)"
        confirm-label="Delete subnet"
    />

    {{-- Generate IPs Modal --}}
    <div class="modal fade" id="generateIpsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" action="{{ route('admin.ip-subnets.generate-ips', $ipSubnet) }}">
                @csrf
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Generate IPs for {{ $ipSubnet->subnet_cidr }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small mb-3">Creates <code>available</code> addresses for every usable host in <code>{{ $ipSubnet->subnet_cidr }}</code>. Existing IPs are skipped. Large subnets are capped at 4096 per batch (/16 – /32; /31 and /32 handled per RFC).</p>
                        <div class="mb-3">
                            <label class="form-label">Type</label>
                            <select name="type" class="form-select">
                                <option value="available" selected>available</option>
                                <option value="reserved">reserved</option>
                                <option value="floating">floating</option>
                                <option value="nat">nat</option>
                            </select>
                        </div>
                        <div class="form-check mb-2">
                            <input type="hidden" name="exclude_network_broadcast" value="0">
                            <input class="form-check-input" type="checkbox" name="exclude_network_broadcast" value="1" id="exNetBc" checked>
                            <label class="form-check-label" for="exNetBc">Exclude network &amp; broadcast address</label>
                        </div>
                        <div class="form-check">
                            <input type="hidden" name="exclude_gateway" value="0">
                            <input class="form-check-input" type="checkbox" name="exclude_gateway" value="1" id="exGw" checked>
                            <label class="form-check-label" for="exGw">Exclude gateway ({{ $ipSubnet->gateway ?? 'none set' }})</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Generate</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <x-adminlte-card icon="bi bi-grid" title="Subnet Details">
        <div class="row">
            <div class="col-md-4"><strong>CIDR:</strong> <code>{{ $ipSubnet->subnet_cidr }}</code></div>
            <div class="col-md-4"><strong>Gateway:</strong> {{ $ipSubnet->gateway ?? '—' }}</div>
            <div class="col-md-4"><strong>Netmask:</strong> {{ $ipSubnet->netmask ?? '—' }}</div>
        </div>
        <div class="row mt-3">
            <div class="col-md-4"><strong>IP Version:</strong> IPv{{ $ipSubnet->ip_version }}</div>
            <div class="col-md-4"><strong>Network Type:</strong> {{ ucfirst($ipSubnet->network_type) }}</div>
            <div class="col-md-4"><strong>Status:</strong> <x-adminlte.partials.status-badge :status="$ipSubnet->status ?? 'active'" /></div>
        </div>
        <div class="row mt-3">
            <div class="col-md-4"><strong>Total Addresses:</strong> {{ $ipSubnet->total_addresses ?? '—' }}</div>
            <div class="col-md-4"><strong>Used:</strong> {{ $ipSubnet->used_addresses ?? 0 }}</div>
            <div class="col-md-4"><strong>Reserved:</strong> {{ $ipSubnet->reserved_count ?? 0 }}</div>
        </div>
        <div class="row mt-3">
            <div class="col-md-4"><strong>VLAN:</strong> {{ $ipSubnet->vlan?->name ?? '—' }}</div>
            <div class="col-md-4"><strong>Datacenter:</strong> {{ $ipSubnet->datacenter?->name ?? '—' }}</div>
        </div>
        @if ($ipSubnet->description)
            <hr>
            <p class="mb-0">{{ $ipSubnet->description }}</p>
        @endif
    </x-adminlte-card>

    <x-adminlte-card icon="bi bi-diagram-3" title="IP Addresses in this Subnet ({{ $ipSubnet->ipAddresses->count() }})">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>IP Address</th><th>Type</th><th>Assigned To</th></tr></thead>
                <tbody>
                    @forelse ($ipSubnet->ipAddresses->sortBy(fn($ip) => sprintf('%08X', ip2long($ip->ip_address) ?: 0)) as $ip)
                        <tr>
                            <td><code>{{ $ip->ip_address }}</code></td>
                            <td><x-adminlte.partials.status-badge :status="$ip->type ?? 'available'" /></td>
                            <td class="text-muted small">{{ $ip->assigned_to_type ? class_basename($ip->assigned_to_type).'#'.$ip->assigned_to_id : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-muted py-4">No IP addresses yet — use Generate IPs above.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-adminlte-card>
@stop
