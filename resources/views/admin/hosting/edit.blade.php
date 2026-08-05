@extends('adminlte::page')

@section('title', 'Edit Hosting Account #'.$hostingAccount->id)

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Edit Hosting Account #{{ $hostingAccount->id }}</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.hosting.index') }}">Hosting Accounts</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.hosting.show', $hostingAccount) }}">#{{ $hostingAccount->id }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Edit</li>
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
        icon="bi bi-hdd-stack"
        title="Edit Hosting Account"
        :action="route('admin.hosting.update', $hostingAccount)"
        method="PUT"
        submit-label="Update Hosting Account"
        :cancel-url="route('admin.hosting.show', $hostingAccount)"
    >
        {{-- Direct status edits are an admin override; the lifecycle actions
             (suspend / unsuspend / change package) live on the show page. --}}
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="customer_id" label="Customer" required>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}" @selected(old('customer_id', $hostingAccount->customer_id) == $customer->id)>
                            {{ $customer->full_name }} — {{ $customer->user?->email ?? $customer->display_id }}
                        </option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-select name="product_id" label="Package (product)" required>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}" @selected(old('product_id', $hostingAccount->product_id) == $product->id)>
                            {{ $product->name }} ({{ ucfirst(str_replace('_', ' ', $product->type)) }}) — {{ number_format($product->price, 2) }}
                        </option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="username" label="Username" placeholder="e.g. acme_admin"
                                  value="{{ old('username', $hostingAccount->username) }}" required />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="domain" label="Primary domain" placeholder="e.g. example.com"
                                  value="{{ old('domain', $hostingAccount->domain) }}" />
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="server_id" label="Server (optional)">
                    <option value="">— Unassigned —</option>
                    @foreach ($servers as $server)
                        <option value="{{ $server->id }}" @selected(old('server_id', $hostingAccount->server_id) == $server->id)>
                            {{ $server->name }} ({{ $server->ip_address }})
                        </option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="username_prefix" label="Username prefix" placeholder="Optional, e.g. acme_"
                                  value="{{ old('username_prefix', $hostingAccount->username_prefix) }}" />
            </div>
        </div>

        <div class="row">
            <div class="col-md-4">
                <x-adminlte-input name="disk_quota" type="number" min="0" step="1" label="Disk quota (MB)"
                                  value="{{ old('disk_quota', $hostingAccount->disk_quota) }}" />
            </div>
            <div class="col-md-4">
                <x-adminlte-input name="bandwidth_quota" type="number" min="0" step="1" label="Bandwidth quota (MB)"
                                  value="{{ old('bandwidth_quota', $hostingAccount->bandwidth_quota) }}" />
            </div>
            <div class="col-md-4">
                <x-adminlte-select name="status" label="Status">
                    @foreach (['pending' => 'Pending', 'active' => 'Active', 'suspended' => 'Suspended', 'terminated' => 'Terminated'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('status', $hostingAccount->status) === $value)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="panel_account_id" label="Panel account ID" placeholder="Optional, e.g. cpanel username"
                                  value="{{ old('panel_account_id', $hostingAccount->panel_account_id) }}" />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="password" type="password" label="Panel password" placeholder="Leave blank to keep unchanged" />
            </div>
        </div>
    </x-adminlte.partials.form-card>
@stop
