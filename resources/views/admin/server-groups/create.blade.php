@extends('adminlte::page')

@section('title', 'Add Server Group')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Add Server Group</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.server-groups.index') }}">Server Groups</a></li>
                <li class="breadcrumb-item active" aria-current="page">Add Server Group</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-adminlte-alert>
    @endif

    <x-adminlte.partials.form-card
        icon="bi bi-collection"
        title="New Server Group"
        :action="route('admin.server-groups.store')"
        submit-label="Save Server Group"
        :cancel-url="route('admin.server-groups.index')"
    >
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="name" label="Name" placeholder="e.g. Web Cluster A"
                                  value="{{ old('name') }}" required />
            </div>
            <div class="col-md-6">
                <x-adminlte-select name="load_balancing" label="Load balancing">
                    @foreach (['round_robin' => 'Round Robin', 'least_loaded' => 'Least Loaded', 'failover' => 'Failover'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('load_balancing', 'round_robin') === $value)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>

        <x-adminlte-select name="status" label="Status">
            <option value="active" @selected(old('status', 'active') === 'active')>Active</option>
            <option value="inactive" @selected(old('status') === 'inactive')>Inactive</option>
        </x-adminlte-select>

        <x-adminlte-textarea name="description" label="Description" rows="2"
                             placeholder="Optional description">{{ old('description') }}</x-adminlte-textarea>

        <x-adminlte-card title="Member servers" icon="bi bi-server" class="mt-3" body-class="p-3">
            @forelse ($servers as $server)
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="server_ids[]"
                           value="{{ $server->id }}" id="server-{{ $server->id }}"
                           @checked(in_array($server->id, old('server_ids', []), true))>
                    <label class="form-check-label" for="server-{{ $server->id }}">
                        {{ $server->name }} ({{ $server->ip_address }})
                        <x-adminlte.partials.status-badge :status="$server->status" />
                    </label>
                </div>
            @empty
                <p class="text-muted mb-0">No servers registered yet.</p>
            @endforelse
        </x-adminlte-card>
    </x-adminlte.partials.form-card>
@stop
