@extends('adminlte::page')

@section('title', 'Modules')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Modules</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item">System</li>
                <li class="breadcrumb-item active" aria-current="page">Modules</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif
    @if (session('error'))
        <x-adminlte-alert theme="danger" dismissible>{{ session('error') }}</x-adminlte-alert>
    @endif
    @if (session('warning'))
        <x-adminlte-alert theme="warning" dismissible>{{ session('warning') }}</x-adminlte-alert>
    @endif

    <x-adminlte-card icon="bi bi-puzzle" title="Modules">
        <x-slot name="tools">
            @can('modules.manage')
                <form method="POST" action="{{ route('admin.modules.install') }}" enctype="multipart/form-data"
                      class="d-inline-flex align-items-center gap-2">
                    @csrf
                    <input type="file" name="module_zip" class="form-control form-control-sm"
                           accept=".zip,application/zip" required aria-label="Module ZIP file">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-upload me-1" aria-hidden="true"></i> Install
                    </button>
                </form>
            @endcan
        </x-slot>

        <div class="row">
        @forelse ($modules as $module)
            @php
                $caps = [];
                try {
                    $caps = $manager->capabilities($module);
                } catch (\Throwable $e) {
                    $caps = [];
                }
            @endphp

            <div class="col-md-6 col-xl-4 mb-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-start justify-content-between mb-2">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-puzzle fs-4 me-2 text-primary" aria-hidden="true"></i>
                                <div>
                                    <strong class="me-2">{{ $module->name }}</strong>
                                    <span class="badge text-bg-secondary">v{{ $module->version }}</span>
                                </div>
                            </div>
                            <x-adminlte.partials.status-badge :status="$module->status" />
                        </div>

                        <p class="text-muted small mb-2">
                            {{ $module->manifest['description'] ?? 'No description provided.' }}
                        </p>

                        <div class="text-muted small mb-2">{{ $module->provider }}</div>

                        @if (!empty($caps))
                            <div class="mb-2">
                                @foreach ($caps as $capability)
                                    <span class="badge text-bg-info me-1">{{ $capability }}</span>
                                @endforeach
                            </div>
                        @endif

                        @if (!empty($module->manifest['permissions']))
                            <div class="mb-2">
                                @foreach ($module->manifest['permissions'] as $permission)
                                    <span class="badge text-bg-dark me-1">{{ $permission }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="card-footer bg-transparent">
                        <div class="d-flex flex-wrap gap-2">
                            @if ($module->status === \App\Models\Module::STATUS_ACTIVE)
                                @can('modules.manage')
                                    <form method="POST" action="{{ route('admin.modules.deactivate', $module) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-warning">
                                            <i class="bi bi-pause me-1" aria-hidden="true"></i> Deactivate
                                        </button>
                                    </form>
                                @endcan
                            @else
                                @can('modules.manage')
                                    <form method="POST" action="{{ route('admin.modules.activate', $module) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-success">
                                            <i class="bi bi-play me-1" aria-hidden="true"></i> Activate
                                        </button>
                                    </form>

                                    <a href="{{ route('admin.modules.config', $module) }}"
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-sliders me-1" aria-hidden="true"></i> Configure
                                    </a>

                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                            data-bs-toggle="modal" data-bs-target="#uninstall-module-{{ $module->id }}">
                                        <i class="bi bi-trash me-1" aria-hidden="true"></i> Uninstall
                                    </button>
                                @endcan
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <p class="text-muted mb-0">
                No modules installed yet. Upload a module ZIP above to get started.
            </p>
        @endforelse
        </div>
    </x-adminlte-card>

    @foreach ($modules as $module)
        @if ($module->status !== \App\Models\Module::STATUS_ACTIVE)
            @can('modules.manage')
                <x-adminlte.partials.confirm-modal
                    :id="'uninstall-module-' . $module->id"
                    title="Uninstall module"
                    :message="'Uninstall ' . $module->name . '? This permanently removes the module and its data.'"
                    method="POST"
                    :action="route('admin.modules.uninstall', $module)"
                    confirm-label="Uninstall module"
                />
            @endcan
        @endif
    @endforeach
@stop
