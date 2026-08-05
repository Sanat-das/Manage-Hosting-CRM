@extends('adminlte::page')

@section('title', 'Servers')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Servers</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Servers</li>
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

    <x-adminlte.partials.datatable
        icon="bi bi-server"
        title="All Servers"
        :search-value="$search"
        search-placeholder="Search name, IP address..."
        :status-options="['active' => 'Active', 'inactive' => 'Inactive']"
        :status-value="$status"
        :columns="[
            ['label' => 'Name'],
            ['label' => 'IP Address'],
            ['label' => 'Panel'],
            ['label' => 'Accounts', 'class' => 'text-end'],
            ['label' => 'Capacity', 'class' => 'text-end'],
            ['label' => 'Status'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$servers"
    >
        <x-slot name="tools">
            @can('hosting.manage')
                <a href="{{ route('admin.servers.create') }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Add Server
                </a>
            @endcan
        </x-slot>

        @forelse ($servers as $server)
            <tr>
                <td>
                    <a href="{{ route('admin.servers.show', $server) }}"><strong>{{ $server->name }}</strong></a>
                </td>
                <td class="text-muted">{{ $server->ip_address }}</td>
                <td>{{ ucfirst($server->panel_type) }}</td>
                <td class="text-end">{{ $server->hosting_accounts_count }}</td>
                <td class="text-end">
                    {{ $server->max_accounts > 0 ? $server->hosting_accounts_count.' / '.$server->max_accounts : 'Unlimited' }}
                </td>
                <td>
                    <x-adminlte.partials.status-badge :status="$server->status" />
                </td>
                <td class="text-end">
                    <a href="{{ route('admin.servers.show', $server) }}"
                       class="btn btn-sm btn-outline-secondary" title="View">
                        <i class="bi bi-eye"></i>
                    </a>
                    @can('hosting.manage')
                        <a href="{{ route('admin.servers.edit', $server) }}"
                           class="btn btn-sm btn-outline-secondary" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </a>
                    @endcan
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7" class="text-center text-muted py-4">
                    No servers found.
                </td>
            </tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
