@extends('adminlte::page')

@section('title', __('adminlte.users'))

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">{{ __('adminlte.users') }}</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item">{{ __('adminlte.administration') }}</li>
                <li class="breadcrumb-item active" aria-current="page">{{ __('adminlte.users') }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('status'))
        <x-adminlte-alert theme="success" dismissible>{{ session('status') }}</x-adminlte-alert>
    @endif

    <x-adminlte-card icon="bi bi-people" title="{{ __('adminlte.users') }}" bodyClass="p-0">
        <x-slot name="tools">
            <a href="{{ route('adminlte.users.create') }}" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> {{ __('adminlte.new_user') }}
            </a>
        </x-slot>

        <div class="table-responsive">
            <table class="table table-striped align-middle m-0">
                <thead>
                    <tr>
                        <th>{{ __('adminlte.name') }}</th>
                        <th>{{ __('adminlte.email') }}</th>
                        <th>{{ __('adminlte.roles') }}</th>
                        <th class="text-end">{{ __('adminlte.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr>
                            <td><a href="{{ route('adminlte.users.edit', $user) }}" class="table-link"><strong>{{ $user->name }}</strong></a></td>
                            <td class="text-muted">{{ $user->email }}</td>
                            <td>
                                @forelse ($user->roles as $role)
                                    <span class="badge text-bg-primary">{{ $role->label ?? $role->name }}</span>
                                @empty
                                    <span class="text-muted">—</span>
                                @endforelse
                            </td>
                            <td class="text-end">
                                <div class="table-actions">
                                    @if (\Illuminate\Support\Facades\Route::has('adminlte.impersonate.start') && ! $user->is(auth()->user()))
                                        <a href="{{ route('adminlte.impersonate.start', $user) }}"
                                           class="btn btn-sm btn-outline-secondary btn-icon" aria-label="{{ __('adminlte.login_as') }}"
                                           title="{{ __('adminlte.login_as') }}">
                                            <i class="bi bi-incognito"></i>
                                        </a>
                                    @endif
                                    <a href="{{ route('adminlte.users.edit', $user) }}"
                                       class="btn btn-sm btn-outline-secondary btn-icon" aria-label="{{ __('adminlte.edit') }}" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-danger btn-icon" aria-label="{{ __('adminlte.delete') }}" title="Delete"
                                            data-bs-toggle="modal" data-bs-target="#delete-user-{{ $user->id }}">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-4">{{ __('adminlte.no_users') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-slot name="footer">
            {{ $users->links() }}
        </x-slot>
    </x-adminlte-card>

    @foreach ($users as $user)
        <x-adminlte.partials.confirm-modal
            :id="'delete-user-' . $user->id"
            :title="__('adminlte.delete')"
            :message="__('adminlte.confirm_delete')"
            :action="route('adminlte.users.destroy', $user)"
            :confirm-label="__('adminlte.delete')"
        />
    @endforeach
@stop
