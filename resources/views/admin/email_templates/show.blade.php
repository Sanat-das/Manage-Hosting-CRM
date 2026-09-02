@extends('adminlte::page')

@section('title', $template->name)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">{{ $template->name }}</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.email-templates.index') }}">Email Templates</a></li>
                <li class="breadcrumb-item active">{{ $template->name }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif

    <div class="d-flex justify-content-end mb-3">
        <a href="{{ route('admin.email-templates.edit', $template) }}" class="btn btn-outline-primary me-2"><i class="bi bi-pencil me-1"></i> Edit</a>
        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#delete-email-template-modal">
            <i class="bi bi-trash me-1"></i> Delete
        </button>
    </div>

    <x-adminlte.partials.confirm-modal
        id="delete-email-template-modal"
        title="Delete email template"
        :message="'Delete ' . $template->name . '? This cannot be undone.'"
        :action="route('admin.email-templates.destroy', $template)"
        confirm-label="Delete template"
    />

    <div class="row">
        <div class="col-lg-6">
            <x-adminlte-card icon="bi bi-info-circle" title="Details">
                <table class="table table-sm table-borderless">
                    <tr><th class="w-25 text-muted">Name</th><td><strong>{{ $template->name }}</strong></td></tr>
                    <tr><th class="text-muted">Subject</th><td>{{ $template->subject }}</td></tr>
                    <tr><th class="text-muted">Status</th><td><x-adminlte.partials.status-badge :status="$template->status" /></td></tr>
                </table>
            </x-adminlte-card>
        </div>
        <div class="col-lg-6">
            <x-adminlte-card icon="bi bi-code-slash" title="Body Preview">
                <div style="white-space: pre-wrap; background: var(--color-bg-subtle, var(--bs-tertiary-bg, #f8fafc)); padding: 1rem; border-radius: var(--radius-md); max-height: 400px; overflow-y: auto; border: 1px solid var(--color-border);">{{ $template->body }}</div>
            </x-adminlte-card>
        </div>
    </div>
@stop
