@extends('adminlte::page')

@section('title', 'Edit IP — '.$ipAddress->ip_address)

@section('content_header')
    <x-ui.page-header title="Edit IP: {{ $ipAddress->ip_address }}" subtitle="Update IP address configuration" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'IP Addresses', 'url' => route('admin.ip-addresses.index')],
        ['label' => 'Edit', 'active' => true],
    ]" />
@stop

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-adminlte-alert>
    @endif

    <x-adminlte.partials.form-card icon="bi bi-geo-alt" title="Edit IP" :action="route('admin.ip-addresses.update', $ipAddress)" submit-label="Update IP" :cancel-url="route('admin.ip-addresses.show', $ipAddress)">
        @method('PUT')
        <x-adminlte-input name="ip_address" label="IP Address" value="{{ old('ip_address', $ipAddress->ip_address) }}" required />
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="subnet_id" label="Subnet">
                    <option value="">— None —</option>
                    @foreach ($subnets as $sub)
                        <option value="{{ $sub->id }}" @selected(old('subnet_id', $ipAddress->subnet_id) == $sub->id)>{{ $sub->subnet_cidr }} — {{ $sub->name }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-select name="type" label="Type">
                    @foreach (['available' => 'Available', 'reserved' => 'Reserved', 'gateway' => 'Gateway', 'broadcast' => 'Broadcast', 'network' => 'Network', 'floating' => 'Floating', 'nat' => 'NAT', 'assigned' => 'Assigned (read-only)'] as $val => $lbl)
                        <option value="{{ $val }}" @selected(old('type', $ipAddress->type) === $val) @disabled($val === 'assigned' && $ipAddress->assigned_to_type === null)>{{ $lbl }}</option>
                    @endforeach
                </x-adminlte-select>
                <p class="form-text small text-muted">Status is derived from assignment. To change to Assigned, use the Assign function on the detail page — not manual type change.</p>
            </div>
        </div>
        <x-adminlte-input name="ptr_record" label="PTR Record" value="{{ old('ptr_record', $ipAddress->ptr_record) }}" />
        <x-adminlte-textarea name="notes" label="Notes" rows="2">{{ old('notes', $ipAddress->notes) }}</x-adminlte-textarea>
        @if ($ipAddress->assigned_to_type)
            <div class="alert alert-warning small mb-0"><i class="bi bi-exclamation-triangle me-1"></i> This IP is currently <strong>{{ $ipAddress->status }}</strong> to {{ class_basename($ipAddress->assigned_to_type) }} #{{ $ipAddress->assigned_to_id }}. To make it available again use <strong>Release</strong> on the detail page — changing type alone will not clear the assignment.</div>
        @endif
    </x-adminlte.partials.form-card>
@stop
