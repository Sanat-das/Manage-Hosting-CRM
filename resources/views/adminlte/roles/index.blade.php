@extends('adminlte::page')

@section('title', __('adminlte.roles'))

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">{{ __('adminlte.roles') }}</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item">{{ __('adminlte.administration') }}</li>
                <li class="breadcrumb-item active" aria-current="page">{{ __('adminlte.roles') }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('status'))
        <x-adminlte-alert theme="success" dismissible>{{ session('status') }}</x-adminlte-alert>
    @endif

    <x-adminlte.partials.datatable
        icon="bi bi-shield-lock"
        :title="__('adminlte.roles')"
        :search-value="$search"
        search-placeholder="Search name, label..."
        :columns="[
            ['label' => __('adminlte.name'), 'sort' => 'name'],
            ['label' => __('adminlte.label'), 'sort' => 'label'],
            ['label' => __('adminlte.permissions'), 'sort' => 'permissions'],
            ['label' => __('adminlte.actions'), 'class' => 'text-end'],
        ]"
        :pagination="$roles"
    >
        <x-slot name="tools">
            <a href="{{ route('adminlte.roles.create') }}" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> {{ __('adminlte.new_role') }}
            </a>
        </x-slot>

        @forelse ($roles as $role)
            <tr>
                <td><a href="{{ route('adminlte.roles.edit', $role) }}" class="table-link"><strong>{{ $role->name }}</strong></a></td>
                <td class="text-muted">{{ $role->label ?? '—' }}</td>
                <td><span class="badge text-bg-secondary">{{ $role->permissions_count }}</span></td>
                <td class="text-end">
                    <div class="table-actions">
                        <a href="{{ route('adminlte.roles.edit', $role) }}"
                           class="btn btn-sm btn-outline-secondary btn-icon" aria-label="{{ __('adminlte.edit') }}" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <button type="button" class="btn btn-sm btn-outline-danger btn-icon" aria-label="{{ __('adminlte.delete') }}" title="Delete"
                                data-bs-toggle="modal" data-bs-target="#delete-role-{{ $role->id }}">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="text-center text-muted py-4">{{ __('adminlte.no_roles') }}</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>

    @foreach ($roles as $role)
        <x-adminlte.partials.confirm-modal
            :id="'delete-role-' . $role->id"
            :title="__('adminlte.delete')"
            :message="__('adminlte.confirm_delete')"
            :action="route('adminlte.roles.destroy', $role)"
            :confirm-label="__('adminlte.delete')"
        />
    @endforeach
@stop
