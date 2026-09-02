@extends('adminlte::page')

@section('title', 'Registrar Settings')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Registrar Settings</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active">Registrar Settings</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif

    @forelse ($registrars as $registrar)
        <x-adminlte-card icon="bi bi-globe2" title="{{ ucfirst($registrar) }}">
            <table class="table table-sm table-borderless">
                @foreach ($allSettings[$registrar] ?? [] as $key => $value)
                    <tr>
                        <td class="text-muted w-25">{{ ucfirst(str_replace('_', ' ', $key)) }}</td>
                        <td>
                            @if (str_contains($key, 'key') || str_contains($key, 'secret') || str_contains($key, 'password'))
                                <code>{{ \App\Models\RegistrarSetting::mask($value) }}</code>
                            @else
                                {{ $value }}
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
            <a href="{{ route('admin.registrar-settings.edit', $registrar) }}" class="btn btn-sm btn-outline-primary">Edit</a>
            <button type="button" class="btn btn-sm btn-outline-danger"
                    data-bs-toggle="modal" data-bs-target="#delete-registrar-{{ Str::slug($registrar) }}">Remove</button>
        </x-adminlte-card>
    @empty
        <x-adminlte-card title="No Registrars Configured">
            <p class="text-muted mb-0">Configure domain registrar API connections in Settings > General, or add them manually.</p>
        </x-adminlte-card>
    @endforelse

    @foreach ($registrars as $registrar)
        <x-adminlte.partials.confirm-modal
            :id="'delete-registrar-' . Str::slug($registrar)"
            title="Remove registrar"
            :message="'Remove registrar ' . ucfirst($registrar) . '? Its stored settings will be deleted.'"
            :action="route('admin.registrar-settings.destroy', $registrar)"
            confirm-label="Remove registrar"
        />
    @endforeach
@stop
