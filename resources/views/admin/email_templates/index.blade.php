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
        :search-value="$search" search-placeholder="Search templates..."
        :columns="[
            ['label' => 'Name', 'sort' => 'name'],
            ['label' => 'Subject', 'sort' => 'subject'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]">

        <x-slot name="tools">
            <a href="{{ route('admin.email-templates.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> New Template</a>
        </x-slot>

        @forelse ($templates as $tpl)
            <tr>
                <td><a href="{{ route('admin.email-templates.show', $tpl) }}"><strong>{{ $tpl->name }}</strong></a></td>
                <td>{{ $tpl->subject }}</td>
                <td><x-adminlte.partials.status-badge :status="$tpl->status" /></td>
                <td class="text-end">
<div class="table-actions">
                    <a href="{{ route('admin.email-templates.edit', $tpl) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="text-center text-muted py-4">No email templates.</td></tr>
        @endforelse

        <x-slot name="pagination">{{ $templates->links() }}</x-slot>
    </x-adminlte.partials.datatable>
@stop
