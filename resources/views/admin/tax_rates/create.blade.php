@extends('adminlte::page')
@section('title', 'Add Tax Rate')
@section('content_header')
    <x-ui.page-header title="Add Tax Rate" subtitle="Create a new tax rate for billing" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')], ['label' => 'Tax Rates', 'url' => route('admin.tax-rates.index')], ['label' => 'Add', 'active' => true]]" />
@stop
@section('content')
    @if ($errors->any()) <x-adminlte-alert theme="danger" dismissible><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-adminlte-alert> @endif
    <x-adminlte.partials.form-card icon="bi bi-percent" title="New Tax Rate" :action="route('admin.tax-rates.store')" submit-label="Save" :cancel-url="route('admin.tax-rates.index')">
        <div class="row">
            <div class="col-md-6"><x-adminlte-input name="name" label="Name" placeholder="e.g. GST 18%" value="{{ old('name') }}" /></div>
            <div class="col-md-3"><x-adminlte-input name="rate" type="number" step="0.01" label="Rate (%)" value="{{ old('rate') }}" required /></div>
            <div class="col-md-3 d-flex align-items-end pb-2"><x-adminlte-input-switch name="is_active" label="Active" checked /></div>
        </div>
    </x-adminlte.partials.form-card>
@stop
