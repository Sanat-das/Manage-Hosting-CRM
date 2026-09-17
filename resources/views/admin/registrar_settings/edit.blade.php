@extends('adminlte::page')

@section('title', 'Edit: ' . ucfirst($registrar))

@section('content_header')
    <x-ui.page-header title="Edit {{ ucfirst($registrar) }} Settings" subtitle="Update registrar settings" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Registrars','url' => route('admin.registrar-settings.index')],['label' => ucfirst($registrar),'active' => true]]" />
@stop

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-adminlte-alert>
    @endif

    <x-adminlte.partials.form-card icon="bi bi-globe2" title="Edit: {{ ucfirst($registrar) }}"
        :action="route('admin.registrar-settings.update', $registrar)" submit-label="Save Changes"
        :cancel-url="route('admin.registrar-settings.index')">
        @method('PUT')
        <div class="row">
            <div class="col-md-8">
                <x-adminlte-input name="api_endpoint" label="API Endpoint" placeholder="https://api.registrar.com/v1"
                    value="{{ old('api_endpoint', $settings['api_endpoint'] ?? '') }}" />
            </div>
            <div class="col-md-4">
                <x-adminlte-select name="enabled" label="Status">
                    <option value="1" @selected(($settings['enabled'] ?? '0') === '1')>Enabled</option>
                    <option value="0" @selected(($settings['enabled'] ?? '0') === '0')>Disabled</option>
                </x-adminlte-select>
            </div>
        </div>
        <div class="row">
            <div class="col-md-8">
                <x-adminlte-input name="api_key" label="API Key" placeholder="Enter API key"
                    value="" />
                @if (!empty($settings['api_key']))
                    <small class="text-muted">Current: <code>{{ \App\Models\RegistrarSetting::mask($settings['api_key']) }}</code></small>
                @endif
            </div>
            <div class="col-md-4">
                <x-adminlte-select name="test_mode" label="Test Mode">
                    <option value="0" @selected(($settings['test_mode'] ?? '0') === '0')>Production</option>
                    <option value="1" @selected(($settings['test_mode'] ?? '0') === '1')>Test / Sandbox</option>
                </x-adminlte-select>
            </div>
        </div>
    </x-adminlte.partials.form-card>
@stop

