@extends('adminlte::page')

@section('title', 'Email Templates')

@section('content_header')
    <x-ui.page-header title="Email Templates" subtitle="Overview and management of email templates" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Email Templates','active' => true]]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

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
            <x-ui.empty-table-row colSpan="4" icon="bi bi-envelope" title="No email templates." message="Create one to get started." />
        @endforelse

        <x-slot name="pagination">{{ $templates->links() }}</x-slot>
    </x-adminlte.partials.datatable>
@stop

