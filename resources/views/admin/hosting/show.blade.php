@extends('adminlte::page')

@section('title', 'Hosting Account #'.$hostingAccount->id.' — '.$hostingAccount->username)

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Hosting Account #{{ $hostingAccount->id }}</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.hosting.index') }}">Hosting Accounts</a></li>
                <li class="breadcrumb-item active" aria-current="page">#{{ $hostingAccount->id }}</li>
            </ol>
        </div>
    </div>
@stop

@php
    $activeTab = (string) request()->query('tab', 'info');
    $tabs = [
        ['id' => 'info', 'label' => 'Info', 'icon' => 'bi bi-info-circle'],
        ['id' => 'actions', 'label' => 'Actions', 'icon' => 'bi bi-lightning'],
        ['id' => 'history', 'label' => 'History', 'icon' => 'bi bi-clock-history', 'badge' => count($audit)],
    ];
@endphp

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif
    @if (session('error'))
        <x-adminlte-alert theme="danger" dismissible>{{ session('error') }}</x-adminlte-alert>
    @endif
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-adminlte-alert>
    @endif

    {{-- Account header --}}
    <x-adminlte-card>
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center rounded-circle bg-primary text-white"
                 style="width: 56px; height: 56px; font-size: 1.25rem; flex-shrink: 0;">
                {{ strtoupper(substr($hostingAccount->username, 0, 1)) }}
            </div>
            <div class="flex-grow-1">
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <h4 class="mb-0">{{ $hostingAccount->username }}</h4>
                    <x-adminlte.partials.status-badge :status="$hostingAccount->status" />
                    @if ($hostingAccount->domain)
                        <span class="badge bg-info">{{ $hostingAccount->domain }}</span>
                    @endif
                </div>
                <div class="text-muted mt-1">
                    @if ($hostingAccount->customer)
                        <i class="bi bi-person me-1"></i>
                        <a href="{{ route('admin.customers.show', $hostingAccount->customer_id) }}">
                            {{ $hostingAccount->customer->full_name }}
                        </a>
                        <span class="mx-2">|</span>
                    @endif
                    <i class="bi bi-box-seam me-1"></i>{{ $hostingAccount->product?->name ?? 'No package' }}
                    @if ($hostingAccount->server)
                        <span class="mx-2">|</span>
                        <i class="bi bi-server me-1"></i>
                        <a href="{{ route('admin.servers.show', $hostingAccount->server_id) }}">
                            {{ $hostingAccount->server->name }}
                        </a>
                    @endif
                </div>
            </div>
            <div class="d-flex gap-2">
                @can('hosting.manage')
                    <a href="{{ route('admin.hosting.edit', $hostingAccount) }}" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-pencil me-1"></i> Edit
                    </a>
                    @if ($hostingAccount->status !== 'terminated')
                        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
                                data-bs-target="#terminate-hosting-modal">
                            <i class="bi bi-trash me-1"></i> Terminate
                        </button>
                    @endif
                @endcan
            </div>
        </div>
    </x-adminlte-card>

    {{-- Metric row --}}
    <x-adminlte.partials.metric-cards :items="[
        ['title' => $hostingAccount->disk_used.' / '.$hostingAccount->disk_quota.' MB', 'text' => 'Disk Usage', 'icon' => 'bi bi-hdd', 'theme' => 'primary'],
        ['title' => $hostingAccount->bandwidth_used.' / '.$hostingAccount->bandwidth_quota.' MB', 'text' => 'Bandwidth Usage', 'icon' => 'bi bi-arrow-down-up', 'theme' => 'info'],
        ['title' => ucfirst(str_replace('_', ' ', $hostingAccount->product?->type ?? 'none')), 'text' => 'Package Type', 'icon' => 'bi bi-box-seam', 'theme' => 'success'],
        ['title' => $hostingAccount->server?->name ?? 'Unassigned', 'text' => 'Server', 'icon' => 'bi bi-server', 'theme' => 'warning'],
    ]" />

    {{-- Tabbed detail --}}
    <x-adminlte-card>
        <x-adminlte.partials.detail-tabs :tabs="$tabs" :active-tab="$activeTab">
            {{-- Info --}}
            <div class="tab-pane fade {{ $activeTab === 'info' ? 'show active' : '' }}" id="info" role="tabpanel" aria-labelledby="info-tab">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tbody>
                                <tr><th class="w-25 text-muted">Username</th><td>{{ $hostingAccount->username }}</td></tr>
                                <tr><th class="text-muted">Domain</th><td>{{ $hostingAccount->domain ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Customer</th><td>{{ $hostingAccount->customer?->full_name ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Product / Package</th><td>{{ $hostingAccount->product?->name ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Server</th><td>{{ $hostingAccount->server?->name ?? 'Unassigned' }}</td></tr>
                                <tr><th class="text-muted">Panel account ID</th><td>{{ $hostingAccount->panel_account_id ?? '—' }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tbody>
                                <tr><th class="w-25 text-muted">Username prefix</th><td>{{ $hostingAccount->username_prefix ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Order</th><td>{{ $hostingAccount->order_id ? '#'.$hostingAccount->order_id : '—' }}</td></tr>
                                <tr><th class="text-muted">Created</th><td>{{ $hostingAccount->created_at?->format('M j, Y H:i') }}</td></tr>
                                <tr><th class="text-muted">Last updated</th><td>{{ $hostingAccount->updated_at?->format('M j, Y H:i') }}</td></tr>
                                @if ($hostingAccount->suspended_at)
                                    <tr><th class="text-muted">Suspended at</th><td>{{ $hostingAccount->suspended_at->format('M j, Y H:i') }}</td></tr>
                                @endif
                                @if ($hostingAccount->suspended_reason)
                                    <tr><th class="text-muted">Suspension reason</th><td>{{ $hostingAccount->suspended_reason }}</td></tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Usage --}}
                <div class="row mt-2">
                    <div class="col-md-6">
                        <div class="border rounded p-3">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted small">Disk usage</span>
                                <span class="small">{{ $hostingAccount->diskUsagePercent() }}%</span>
                            </div>
                            <div class="progress">
                                <div class="progress-bar bg-primary" style="width: {{ min(100, $hostingAccount->diskUsagePercent()) }}%"></div>
                            </div>
                            <div class="text-muted small mt-1">{{ $hostingAccount->disk_used }} MB used of {{ $hostingAccount->disk_quota }} MB</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded p-3">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted small">Bandwidth usage</span>
                                <span class="small">{{ $hostingAccount->bandwidthUsagePercent() }}%</span>
                            </div>
                            <div class="progress">
                                <div class="progress-bar bg-info" style="width: {{ min(100, $hostingAccount->bandwidthUsagePercent()) }}%"></div>
                            </div>
                            <div class="text-muted small mt-1">{{ $hostingAccount->bandwidth_used }} MB used of {{ $hostingAccount->bandwidth_quota }} MB</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Actions --}}
            <div class="tab-pane fade {{ $activeTab === 'actions' ? 'show active' : '' }}" id="actions" role="tabpanel" aria-labelledby="actions-tab">
                @can('hosting.manage')
                    <div class="row g-3">
                        {{-- Activate (pending) --}}
                        @if ($hostingAccount->status === 'pending')
                            <div class="col-md-6">
                                <div class="border rounded p-3 h-100">
                                    <h6 class="text-success"><i class="bi bi-check-circle me-1"></i> Activate account</h6>
                                    <p class="text-muted small">Sets the account to <strong>active</strong>.</p>
                                    <form method="POST" action="{{ route('admin.hosting.unsuspend', $hostingAccount) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-success btn-sm">
                                            <i class="bi bi-check-lg me-1"></i> Activate
                                        </button>
                                    </form>
                                </div>
                            </div>
                        @endif

                        {{-- Suspend (active) --}}
                        @if ($hostingAccount->status === 'active')
                            <div class="col-md-6">
                                <div class="border rounded p-3 h-100">
                                    <h6 class="text-warning"><i class="bi bi-pause-circle me-1"></i> Suspend account</h6>
                                    <form method="POST" action="{{ route('admin.hosting.suspend', $hostingAccount) }}">
                                        @csrf
                                        <div class="mb-2">
                                            <textarea name="reason" class="form-control form-control-sm" rows="2"
                                                      placeholder="Suspension reason (optional)"></textarea>
                                        </div>
                                        <button type="submit" class="btn btn-warning btn-sm">
                                            <i class="bi bi-pause me-1"></i> Suspend
                                        </button>
                                    </form>
                                </div>
                            </div>
                        @endif

                        {{-- Unsuspend (suspended) --}}
                        @if ($hostingAccount->status === 'suspended')
                            <div class="col-md-6">
                                <div class="border rounded p-3 h-100">
                                    <h6 class="text-success"><i class="bi bi-play-circle me-1"></i> Reactivate account</h6>
                                    @if ($hostingAccount->suspended_reason)
                                        <p class="text-muted small">Suspension reason: {{ $hostingAccount->suspended_reason }}</p>
                                    @endif
                                    <form method="POST" action="{{ route('admin.hosting.unsuspend', $hostingAccount) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-success btn-sm">
                                            <i class="bi bi-play me-1"></i> Reactivate
                                        </button>
                                    </form>
                                </div>
                            </div>
                        @endif

                        {{-- Change package (active | suspended) --}}
                        @if (in_array($hostingAccount->status, ['active', 'suspended'], true))
                            <div class="col-md-6">
                                <div class="border rounded p-3 h-100">
                                    <h6 class="text-info"><i class="bi bi-box-arrow-up me-1"></i> Change package</h6>
                                    <p class="text-muted small">Current: <strong>{{ $hostingAccount->product?->name ?? 'none' }}</strong></p>
                                    <form method="POST" action="{{ route('admin.hosting.change-package', $hostingAccount) }}">
                                        @csrf
                                        <div class="mb-2">
                                            <select name="product_id" class="form-select form-select-sm" required>
                                                @foreach ($packages as $package)
                                                    <option value="{{ $package->id }}" @selected($package->id === $hostingAccount->product_id)>
                                                        {{ $package->name }} ({{ ucfirst(str_replace('_', ' ', $package->type)) }}) — {{ number_format($package->price, 2) }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <button type="submit" class="btn btn-info btn-sm">
                                            <i class="bi bi-arrow-repeat me-1"></i> Change Package
                                        </button>
                                    </form>
                                </div>
                            </div>
                        @endif

                        @if ($hostingAccount->status === 'terminated')
                            <div class="col-12">
                                <x-adminlte-alert theme="secondary" dismissible>
                                    This account is <strong>terminated</strong>. No lifecycle actions are available.
                                </x-adminlte-alert>
                            </div>
                        @endif
                    </div>
                @else
                    <p class="text-muted mb-0">You do not have permission to manage hosting accounts.</p>
                @endcan
            </div>

            {{-- History --}}
            <div class="tab-pane fade {{ $activeTab === 'history' ? 'show active' : '' }}" id="history" role="tabpanel" aria-labelledby="history-tab">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>When</th><th>Action</th><th>Details</th><th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($audit as $entry)
                                @php
                                    $decoded = json_decode((string) $entry->details, true);
                                    $details = is_array($decoded) ? $decoded : null;
                                @endphp
                                <tr>
                                    <td class="text-muted" style="white-space: nowrap;">{{ $entry->created_at?->format('M j, Y H:i') }}</td>
                                    <td><span class="badge bg-info">{{ $entry->action }}</span></td>
                                    <td>
                                        @if ($details)
                                            @foreach ($details as $key => $value)
                                                <span class="text-muted small">{{ $key }}:</span>
                                                <code class="small">{{ is_scalar($value) ? $value : json_encode($value) }}</code>
                                                @if (! $loop->last)<br>@endif
                                            @endforeach
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="text-muted">{{ $entry->user?->full_name ?? 'System' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-3">No history recorded.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </x-adminlte.partials.detail-tabs>
    </x-adminlte-card>

    @can('hosting.manage')
        @if ($hostingAccount->status !== 'terminated')
            <x-adminlte.partials.confirm-modal
                id="terminate-hosting-modal"
                title="Terminate hosting account"
                :message="'Terminate #' . $hostingAccount->id . ' (' . $hostingAccount->username . ')? This sets the status to terminated and cannot be undone.'"
                :action="route('admin.hosting.destroy', $hostingAccount)"
                confirm-label="Terminate account"
            />
        @endif
    @endcan
@stop
