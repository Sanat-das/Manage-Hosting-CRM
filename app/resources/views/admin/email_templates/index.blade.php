@extends('adminlte::page')

@section('title', 'Email Templates')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Email Templates</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active">Email Templates</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif

    <x-adminlte.partials.datatable icon="bi bi-envelope" title="Email Templates"
        :search-value="$search" search-placeholder="Search templates...">

        <x-slot name="tools">
            <a href="{{ route('admin.email-templates.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> New Template</a>
        </x-slot>

        @forelse ($templates as $tpl)
            <tr>
                <td><a href="{{ route('admin.email-templates.show', $tpl) }}"><strong>{{ $tpl->name }}</strong></a></td>
                <td>{{ $tpl->subject }}</td>
                <td class="text-muted">{{ $tpl->type ?? '—' }}</td>
                <td>
                    @if ($tpl->is_active)
                        <span class="badge bg-success">Active</span>
                    @else
                        <span class="badge bg-secondary">Inactive</span>
                    @endif
                </td>
                <td class="text-end">
                    <a href="{{ route('admin.email-templates.edit', $tpl) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-center text-muted py-4">No email templates.</td></tr>
        @endforelse

        <x-slot name="pagination">{{ $templates->links() }}</x-slot>
    </x-adminlte.partials.datatable>
@stop
