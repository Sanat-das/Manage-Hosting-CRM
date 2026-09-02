@extends('adminlte::page')

@section('title', 'IP Address — '.$ipAddress->ip_address)

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">{{ $ipAddress->ip_address }} <x-adminlte.partials.status-badge :status="$ipAddress->status" /></h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.ip-addresses.index') }}">IP Addresses</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $ipAddress->ip_address }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    @if ($errors->has('assign')) <x-adminlte-alert theme="danger" dismissible>{{ $errors->first('assign') }}</x-adminlte-alert> @endif

    <div class="d-flex justify-content-end gap-2 mb-3">
        <a href="{{ route('admin.ip-addresses.edit', $ipAddress) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i> Edit</a>
        @if ($ipAddress->assigned_to_type)
            <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#release-ip-modal">
                <i class="bi bi-box-arrow-right me-1"></i> Release Assignment
            </button>
        @endif
    </div>

    <div class="row">
        <div class="col-lg-7">
            <x-adminlte-card icon="bi bi-info-circle" title="Details">
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <tr><th class="text-muted w-25">IP Address</th><td><strong>{{ $ipAddress->ip_address }}</strong></td></tr>
                        <tr><th class="text-muted">Version</th><td><span class="badge text-bg-info">IPv{{ $ipAddress->ip_version }}</span></td></tr>
                        <tr><th class="text-muted">Status</th><td><x-adminlte.partials.status-badge :status="$ipAddress->status" /> <span class="text-muted small ms-2">Derived from assignment — use Assign/Release to change</span></td></tr>
                        <tr><th class="text-muted">Assigned To</th><td>
                            @if ($ipAddress->assigned_to_type)
                                @if ($ipAddress->assigned_to_type === 'App\Models\HostingAccount' && $ipAddress->assignedTo)
                                    <a href="{{ route('admin.hosting.show', $ipAddress->assignedTo) }}" class="text-decoration-none">
                                        <span class="badge text-bg-primary">HostingAccount #{{ $ipAddress->assigned_to_id }}</span>
                                        <div class="small mt-1 text-primary">
                                            <strong>{{ $ipAddress->assignedTo->domain ?? '—' }}</strong>
                                            @if ($ipAddress->assignedTo->customer)
                                                <span class="text-muted">— {{ $ipAddress->assignedTo->customer->user->first_name ?? '' }} {{ $ipAddress->assignedTo->customer->user->last_name ?? '' }}</span>
                                            @endif
                                            @if ($ipAddress->assignedTo->product)
                                                <span class="text-muted">({{ $ipAddress->assignedTo->product->name }})</span>
                                            @endif
                                            <i class="bi bi-box-arrow-up-right ms-1" style="font-size:0.75rem"></i>
                                        </div>
                                    </a>
                                @else
                                    <span class="badge text-bg-primary">{{ class_basename($ipAddress->assigned_to_type) }} #{{ $ipAddress->assigned_to_id }}</span>
                                    @if ($ipAddress->assignedTo)
                                        <div class="small mt-1">
                                            <strong>{{ $ipAddress->assignedTo->domain ?? '—' }}</strong>
                                        </div>
                                    @endif
                                @endif
                            @else
                                <span class="text-muted">— Unassigned (available)</span>
                            @endif
                        </td></tr>
                        <tr><th class="text-muted">Subnet</th><td>{{ $ipAddress->subnet?->subnet_cidr ? $ipAddress->subnet->subnet_cidr.' ('.$ipAddress->subnet->name.')' : '—' }}</td></tr>
                        <tr><th class="text-muted">VLAN</th><td>{{ $ipAddress->subnet?->vlan?->name ? $ipAddress->subnet->vlan->name.' (ID '.$ipAddress->subnet->vlan->vlan_id.')' : '—' }}</td></tr>
                        <tr><th class="text-muted">PTR Record</th><td>{{ $ipAddress->ptr_record ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Notes</th><td>{{ $ipAddress->notes ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Last Seen</th><td>{{ $ipAddress->last_seen_at?->diffForHumans() ?? '—' }}</td></tr>
                    </tbody>
                </table>
            </x-adminlte-card>
        </div>
        <div class="col-lg-5">
            @if ($ipAddress->assigned_to_type)
                <x-adminlte-card icon="bi bi-link-45deg" title="Assignment" theme="warning">
                    <p class="small text-muted mb-2">This IP is currently <strong>assigned</strong>. Releasing it will clear the hosting account link, set <code>type</code> to <code>available</code> and status will become <span class="badge text-bg-success">available</span>.</p>
                    <button type="button" class="btn btn-warning w-100" data-bs-toggle="modal" data-bs-target="#release-ip-modal"><i class="bi bi-box-arrow-right me-1"></i> Release IP — make available</button>
                </x-adminlte-card>
            @else
                <x-adminlte-card icon="bi bi-link-45deg" title="Assign to Hosting Account" theme="primary">
                    <p class="small text-muted mb-3">Select a hosting account to lease this IP. Status will automatically change to <span class="badge text-bg-primary">assigned</span> and an entry will be written to <code>ip_allocation_history</code>.</p>
                    <form method="POST" action="{{ route('admin.ip-addresses.assign', $ipAddress) }}">
                        @csrf
                        <div class="mb-3">
                            <label for="hosting_account_id" class="form-label">Hosting Account</label>
                            <select name="hosting_account_id" id="hosting_account_id" class="form-select" required>
                                <option value="">— Select hosting account —</option>
                                @foreach ($hostingAccounts as $ha)
                                    <option value="{{ $ha->id }}">#{{ $ha->id }} — {{ $ha->domain ?? 'no domain' }} — {{ $ha->customer->user->company ?? ($ha->customer->user->first_name ?? '') }} ({{ $ha->product->name ?? '—' }})</option>
                                @endforeach
                            </select>
                            @error('hosting_account_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check2 me-1"></i> Assign IP</button>
                    </form>
                </x-adminlte-card>
            @endif

            @if ($history->isNotEmpty())
                <x-adminlte-card icon="bi bi-clock-history" title="Recent Allocation History">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>When</th><th>Action</th><th>Notes</th></tr></thead>
                            <tbody>
                                @foreach ($history as $h)
                                    <tr>
                                        <td class="small text-muted">{{ \Carbon\Carbon::parse($h->changed_at)->diffForHumans() }}</td>
                                        <td><span class="badge text-bg-secondary">{{ $h->action }}</span></td>
                                        <td class="small">{{ $h->notes ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-adminlte-card>
            @endif
        </div>
    </div>

    @if ($ipAddress->assigned_to_type)
        <x-adminlte.partials.confirm-modal
            id="release-ip-modal"
            title="Release IP address"
            :message="'Release ' . $ipAddress->ip_address . '? This clears the hosting account link and sets the status to available.'"
            method="POST"
            :action="route('admin.ip-addresses.release', $ipAddress)"
            confirm-label="Release IP"
            confirm-theme="warning"
        />
    @endif
@stop
