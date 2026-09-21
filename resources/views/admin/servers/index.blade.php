@extends('adminlte::page')

@section('title', 'Servers')

@section('content_header')
    <x-ui.page-header title="Servers" subtitle="Manage server inventory" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Servers', 'active' => true],
    ]" />
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif
    @if (session('error'))
        <x-adminlte-alert theme="danger" dismissible>{{ session('error') }}</x-adminlte-alert>
    @endif

    @php
        $typeFilter = request()->query('server_type');
        $connFilter = request()->query('connection_status');
        $typeOptions = $typeOptions ?? [];
        // fallback from IntegrationRegistry::serverTypeOptions() if not passed
        $serverTypeLabels = collect($typeOptions)->pluck('label', 'value')->all();
    @endphp

    {{-- Type + Connection filters --}}
    <x-adminlte-card class="mb-3">
        <form method="GET" action="{{ route('admin.servers.index') }}" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-medium mb-1">Type</label>
                <select name="server_type" class="form-select form-select-sm">
                    <option value="">All types</option>
                    @foreach ($typeOptions as $opt)
                        <option value="{{ $opt['value'] ?? $opt['slug'] }}" @selected($typeFilter === ($opt['value'] ?? $opt['slug']))>{{ $opt['label'] ?? $opt['value'] }} ({{ $opt['value'] ?? $opt['slug'] }})</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-medium mb-1">Connection</label>
                <select name="connection_status" class="form-select form-select-sm">
                    <option value="">All connections</option>
                    <option value="connected" @selected($connFilter === 'connected')>Connected</option>
                    <option value="failed" @selected($connFilter === 'failed')>Failed</option>
                    <option value="untested" @selected($connFilter === 'untested')>Untested</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-medium mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All statuses</option>
                    <option value="active" @selected(request()->query('status') === 'active')>Active</option>
                    <option value="inactive" @selected(request()->query('status') === 'inactive')>Inactive</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
                <a href="{{ route('admin.servers.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                @if(request()->hasAny(['server_type','connection_status','status','search']))
                    <span class="text-muted small align-self-center ms-2">{{ $servers->total() }} results</span>
                @endif
            </div>
            @if(request()->query('search'))
                <input type="hidden" name="search" value="{{ request()->query('search') }}">
            @endif
            @if(request()->query('sort'))
                <input type="hidden" name="sort" value="{{ request()->query('sort') }}">
                <input type="hidden" name="direction" value="{{ request()->query('direction') }}">
            @endif
        </form>
    </x-adminlte-card>

    <x-adminlte.partials.datatable
        icon="bi bi-server"
        title="All Servers"
        :search-value="$search"
        search-placeholder="Search name, IP address..."
        :status-options="[]"
        :status-value="''"
        :columns="[
            ['label' => 'Name', 'sort' => 'name'],
            ['label' => 'IP Address', 'sort' => 'ip_address'],
            ['label' => 'Type', 'sort' => 'server_type'],
            ['label' => 'Connection', 'sort' => 'connection_status'],
            ['label' => 'Accounts', 'class' => 'text-end'],
            ['label' => 'Capacity', 'class' => 'text-end'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$servers"
    >
        <x-slot name="tools">
            @can('hosting.manage')
                <a href="{{ route('admin.servers.create-type') }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Add Server
                </a>
            @endcan
        </x-slot>

        @forelse ($servers as $server)
            @php
                $displayType = $server->display_type ?? \Illuminate\Support\Str::title(str_replace(['_','-'],' ', $server->server_type ?? $server->panel_type ?? ''));
                $connStatus = $server->connection_status ?? 'untested';
                $connTheme = match($connStatus) { 'connected' => 'success', 'failed' => 'danger', default => 'secondary' };
                $dotClass = match($connStatus) { 'connected' => 'bg-success', 'failed' => 'bg-danger', default => 'bg-secondary' };
            @endphp
            <tr>
                <td>
                    <a href="{{ route('admin.servers.show', $server) }}"><strong>{{ $server->name }}</strong></a>
                </td>
                <td class="text-muted">{{ $server->ip_address }}</td>
                <td>
                    <span class="badge text-bg-info rounded-pill" style="font-size:var(--text-xs); font-weight:500;">{{ $displayType }}</span>
                    <span class="text-muted small ms-1">{{ $server->server_type ?? $server->panel_type }}</span>
                </td>
                <td>
                    <span class="d-inline-flex align-items-center gap-1 badge rounded-pill text-bg-{{ $connTheme }}" style="font-size:var(--text-xs); font-weight:500;">
                        <span class="d-inline-block rounded-circle {{ $dotClass }}" style="width:8px;height:8px;"></span>
                        {{ ucfirst($connStatus) }}
                    </span>
                </td>
                <td class="text-end">{{ $server->hosting_accounts_count }}</td>
                <td class="text-end">
                    {{ $server->max_accounts > 0 ? $server->hosting_accounts_count.' / '.$server->max_accounts : 'Unlimited' }}
                </td>
                <td>
                    <x-adminlte.partials.status-badge :status="$server->status" />
                </td>
                <td class="text-end">
                    <div class="table-actions">
                        @can('hosting.manage')
                            <a href="{{ route('admin.servers.edit', $server) }}"
                               class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                        @endcan
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="8" class="text-center text-muted py-4">
                    No servers found.
                </td>
            </tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
