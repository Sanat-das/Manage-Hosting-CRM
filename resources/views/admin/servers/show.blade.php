@extends('adminlte::page')

@section('title', 'Server — '.$server->name)

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">{{ $server->name }}</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.servers.index') }}">Servers</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $server->name }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif
    @if (session('error'))
        <x-adminlte-alert theme="danger" dismissible>{{ session('error') }}</x-adminlte-alert>
    @endif

    {{-- Server header --}}
    <x-adminlte-card>
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center rounded-circle bg-primary text-white"
                 style="width: 56px; height: 56px; font-size: 1.25rem; flex-shrink: 0;">
                <i class="bi bi-server"></i>
            </div>
            <div class="flex-grow-1">
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <h4 class="mb-0">{{ $server->name }}</h4>
                    <x-adminlte.partials.status-badge :status="$server->status" />
                    <span class="badge text-bg-info">{{ ucfirst($server->panel_type) }}</span>
                </div>
                <div class="text-muted mt-1">
                    <i class="bi bi-hdd-network me-1"></i>{{ $server->ip_address }}
                    @if ($server->api_url)
                        <span class="mx-2">|</span><i class="bi bi-link-45deg me-1"></i>{{ $server->api_url }}
                    @endif
                </div>
            </div>
            <div class="d-flex gap-2">
                @can('hosting.manage')
                    <a href="{{ route('admin.servers.edit', $server) }}" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-pencil me-1"></i> Edit
                    </a>
                @endcan
            </div>
        </div>
    </x-adminlte-card>

    {{-- Metric row --}}
    <x-adminlte.partials.metric-cards :items="[
        ['title' => $server->hostingAccounts->count(), 'text' => 'Hosting Accounts', 'icon' => 'bi bi-hdd-stack', 'theme' => 'primary'],
        ['title' => $server->max_accounts > 0 ? $server->max_accounts : '∞', 'text' => 'Max Accounts', 'icon' => 'bi bi-box', 'theme' => 'warning'],
        ['title' => $groups->count(), 'text' => 'Server Groups', 'icon' => 'bi bi-collection', 'theme' => 'success'],
        ['title' => $server->hostingAccounts->where('status', 'active')->count(), 'text' => 'Active Accounts', 'icon' => 'bi bi-check-circle', 'theme' => 'info'],
    ]" />

    <div class="row">
        {{-- Accounts on this server --}}
        <div class="col-md-8">
            <x-adminlte-card icon="bi bi-hdd-stack" title="Hosting Accounts">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Username</th><th>Customer</th><th>Package</th><th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($server->hostingAccounts as $account)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.hosting.show', $account) }}"><strong>{{ $account->username }}</strong></a>
                                        @if ($account->domain)
                                            <div class="text-muted small">{{ $account->domain }}</div>
                                        @endif
                                    </td>
                                    <td class="text-muted">{{ $account->customer?->full_name ?? '—' }}</td>
                                    <td class="text-muted">{{ $account->product?->name ?? '—' }}</td>
                                    <td><x-adminlte.partials.status-badge :status="$account->status" /></td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-3">No accounts on this server.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-adminlte-card>
        </div>

        {{-- Server details + group membership --}}
        <div class="col-md-4">
            <x-adminlte-card icon="bi bi-info-circle" title="Details">
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <tr><th class="text-muted w-25">Name</th><td>{{ $server->name }}</td></tr>
                        <tr><th class="text-muted">IP address</th><td>{{ $server->ip_address }}</td></tr>
                        <tr><th class="text-muted">Panel type</th><td>{{ ucfirst($server->panel_type) }}</td></tr>
                        <tr><th class="text-muted">API URL</th><td>{{ $server->api_url ?? '—' }}</td></tr>
                        <tr><th class="text-muted">API username</th><td>{{ $server->api_username ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Max accounts</th><td>{{ $server->max_accounts > 0 ? $server->max_accounts : 'Unlimited' }}</td></tr>
                    </tbody>
                </table>
            </x-adminlte-card>

            <x-adminlte-card icon="bi bi-collection" title="Server Groups">
                @forelse ($groups as $member)
                    <span class="badge text-bg-info me-1">{{ $member->group?->name ?? 'Deleted group' }}</span>
                @empty
                    <p class="text-muted mb-0">Not a member of any group.</p>
                @endforelse
            </x-adminlte-card>
        </div>
    </div>
@stop
