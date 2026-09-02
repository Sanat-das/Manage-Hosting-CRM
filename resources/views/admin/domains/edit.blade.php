@extends('adminlte::page')

@section('title', 'Edit: ' . $domain->name)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Edit Domain</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.domains.index') }}">Domains</a></li>
                <li class="breadcrumb-item active">{{ $domain->name }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-adminlte-alert>
    @endif

    <x-adminlte.partials.form-card icon="bi bi-globe" title="Edit: {{ $domain->name }}"
        :action="route('admin.domains.update', $domain)" submit-label="Save Changes"
        :cancel-url="route('admin.domains.show', $domain)">
        @method('PUT')
        <div class="row">
            <div class="col-md-4">
                <x-adminlte-select name="status" label="Status">
                    @foreach (\App\Services\DomainService::STATUS_LABELS as $s => $label)
                        <option value="{{ $s }}" @selected(old('status', $domain->status) === $s)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-4">
                <x-adminlte-input name="recurring_amount" type="number" step="0.01" min="0" label="Recurring amount" value="{{ old('recurring_amount', $domain->recurring_amount) }}" />
            </div>
            <div class="col-md-4">
                <div class="mt-4">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="auto_renew" value="1" id="auto_renew" @checked(old('auto_renew', $domain->auto_renew))>
                        <label class="form-check-label" for="auto_renew">Auto-renew</label>
                    </div>
                </div>
            </div>
        </div>
        <x-adminlte-textarea name="nameservers" label="Nameservers" rows="3" placeholder="ns1.example.com&#10;ns2.example.com">{{ old('nameservers', $domain->nameservers) }}</x-adminlte-textarea>
    </x-adminlte.partials.form-card>
@stop
