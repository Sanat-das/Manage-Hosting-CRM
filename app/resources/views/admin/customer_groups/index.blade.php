@extends('adminlte::page')

@section('title', 'Customer Groups')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Customer Groups</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active">Customer Groups</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif

    <x-adminlte.partials.datatable icon="bi bi-folder" title="Customer Groups">
        <x-slot name="tools">
            <a href="{{ route('admin.customer-groups.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> New Group</a>
        </x-slot>
        @forelse ($groups as $group)
            <tr>
                <td><a href="{{ route('admin.customer-groups.show', $group) }}"><strong>{{ $group->name }}</strong></a></td>
                <td class="text-muted">{{ $group->description ?? '—' }}</td>
                <td>{{ $group->parent?->name ?? '—' }}</td>
                <td>{{ $group->products_count }}</td>
                <td>
                    @if ($group->status === 'active')
                        <span class="badge bg-success">Active</span>
                    @else
                        <span class="badge bg-secondary">Inactive</span>
                    @endif
                </td>
                <td class="text-end">
                    <a href="{{ route('admin.customer-groups.edit', $group) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center text-muted py-4">No customer groups.</td></tr>
        @endforelse
        <x-slot name="pagination">{{ $groups->links() }}</x-slot>
    </x-adminlte.partials.datatable>
@stop
