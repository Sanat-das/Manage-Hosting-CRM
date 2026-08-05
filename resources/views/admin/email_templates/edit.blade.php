@extends('adminlte::page')

@section('title', 'Edit: ' . $template->name)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Edit Email Template</h1></div>
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
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-adminlte-alert>
    @endif

    <x-adminlte.partials.form-card icon="bi bi-envelope" title="Edit: {{ $template->name }}"
        :action="route('admin.email-templates.update', $template)" submit-label="Save Changes"
        :cancel-url="route('admin.email-templates.show', $template)">
        @method('PUT')

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="name" label="Template Name" value="{{ old('name', $template->name) }}" required />
            </div>
            <div class="col-md-3">
                <x-adminlte-select name="type" label="Type">
                    <option value="transactional" @selected(old('type', $template->type) === 'transactional')>Transactional</option>
                    <option value="notification" @selected(old('type', $template->type) === 'notification')>Notification</option>
                    <option value="marketing" @selected(old('type', $template->type) === 'marketing')>Marketing</option>
                </x-adminlte-select>
            </div>
            <div class="col-md-3">
                <div class="form-check form-switch mt-4">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" @checked(old('is_active', $template->is_active))>
                    <label class="form-check-label" for="is_active">Active</label>
                </div>
            </div>
        </div>

        <x-adminlte-input name="subject" label="Subject" value="{{ old('subject', $template->subject) }}" required />

        <x-adminlte-textarea name="body" label="Body (HTML)" rows="12" required>{{ old('body', $template->body) }}</x-adminlte-textarea>

        <small class="text-muted">Available variables: <code>@{{name}}</code>, <code>@{{email}}</code>, <code>@{{invoice_no}}</code>, <code>@{{amount}}</code>, <code>@{{domain}}</code>, <code>@{{expiry_date}}</code></small>
    </x-adminlte.partials.form-card>
@stop
