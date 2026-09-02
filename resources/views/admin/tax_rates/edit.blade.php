@extends('adminlte::page')
@section('title', 'Edit Tax Rate — '.($rate->name ?? 'Unnamed'))
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Edit: {{ $rate->name ?? 'Unnamed' }}</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.tax-rates.index') }}">Tax Rates</a></li><li class="breadcrumb-item active">Edit</li></ol></div></div>
@stop
@section('content')
    @if ($errors->any()) <x-adminlte-alert theme="danger" dismissible><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-adminlte-alert> @endif
    <x-adminlte.partials.form-card icon="bi bi-percent" title="Edit Tax Rate" :action="route('admin.tax-rates.update', $rate)" submit-label="Update" :cancel-url="route('admin.tax-rates.show', $rate)">
        @method('PUT')
        <div class="row">
            <div class="col-md-6"><x-adminlte-input name="name" label="Name" value="{{ old('name', $rate->name) }}" /></div>
            <div class="col-md-3"><x-adminlte-input name="rate" type="number" step="0.01" label="Rate (%)" value="{{ old('rate', $rate->rate) }}" required /></div>
            <div class="col-md-3 d-flex align-items-end pb-2"><x-adminlte-input-switch name="is_active" label="Active" :checked="$rate->is_active" /></div>
        </div>
    </x-adminlte.partials.form-card>
@stop
