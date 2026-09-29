@extends('adminlte::page')
@section('title', 'Edit Tax Rate — '.($rate->name ?? 'Unnamed'))
@section('content_header')
    <x-ui.page-header title="Edit: {{ $rate->name ?? 'Unnamed' }}" subtitle="Update tax rate configuration" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')], ['label' => 'Tax Rates', 'url' => route('admin.tax-rates.index')], ['label' => 'Edit', 'active' => true]]" />
@stop
@section('content')
    <x-adminlte.partials.flash-alert />
    <x-adminlte.partials.form-card icon="bi bi-percent" title="Edit Tax Rate" :action="route('admin.tax-rates.update', $rate)" submit-label="Update" :cancel-url="route('admin.tax-rates.show', $rate)">
        @method('PUT')
        <div class="row">
            <div class="col-md-6"><x-adminlte-input name="name" label="Name" value="{{ old('name', $rate->name) }}" /></div>
            <div class="col-md-3"><x-adminlte-input name="rate" type="number" step="0.01" label="Rate (%)" value="{{ old('rate', $rate->rate) }}" required /></div>
            <div class="col-md-3 d-flex align-items-end pb-2"><x-adminlte-input-switch name="is_active" label="Active" :checked="$rate->is_active" /></div>
        </div>
    </x-adminlte.partials.form-card>
@stop
