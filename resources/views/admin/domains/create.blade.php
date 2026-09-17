@extends('adminlte::page')

@section('title', 'Register Domain')

@section('content_header')
    <x-ui.page-header title="Register Domain" subtitle="Provision a new domain" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Domains', 'url' => route('admin.domains.index')],
        ['label' => 'Register', 'active' => true],
    ]" />
@stop

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-adminlte-alert>
    @endif

    <x-adminlte.partials.form-card icon="bi bi-globe" title="Register New Domain"
        :action="route('admin.domains.store')" submit-label="Register Domain"
        :cancel-url="route('admin.domains.index')">
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="customer_id" label="Customer" required>
                    <option value="">Select customer...</option>
                    @foreach ($customers as $c)
                        <option value="{{ $c->id }}" @selected(old('customer_id') == $c->id)>{{ $c->full_name }} ({{ $c->user?->email }})</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="name" label="Domain name" placeholder="example.com" value="{{ old('name') }}" required />
            </div>
        </div>
        <div class="row">
            <div class="col-md-4">
                <x-adminlte-input name="registration_period" type="number" min="1" label="Registration period (years)" value="{{ old('registration_period', 1) }}" />
            </div>
            <div class="col-md-4">
                <x-adminlte-input name="recurring_amount" type="number" step="0.01" min="0" label="Recurring amount" value="{{ old('recurring_amount', '0.00') }}" />
            </div>
        </div>
    </x-adminlte.partials.form-card>
@stop
