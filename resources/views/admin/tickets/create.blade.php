@extends('adminlte::page')

@section('title', 'Create Ticket')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Create Ticket</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.tickets.index') }}">Tickets</a></li>
                <li class="breadcrumb-item active" aria-current="page">Create</li>
            </ol>
        </div>
    </div>
@stop

@push('css')
    <link rel="stylesheet" href="{{ asset('vendor/trix/trix.css') }}">
    <style>
        trix-editor { min-height: 220px; font-size: 0.875rem; line-height: 1.6; border: 1px solid #ced4da; border-radius: 0.25rem; padding: 0.5rem 0.75rem; background: #fff; overflow-y: auto; }
        trix-editor:focus { border-color: #86b7fe; outline: 0; box-shadow: 0 0 0 0.2rem rgba(13,110,253,.25); }
        trix-editor.is-invalid { border-color: #dc3545; }
        trix-toolbar .trix-button-group { border: 1px solid #ced4da; border-radius: 0.2rem; }
        trix-toolbar .trix-button { border-bottom: none; }
        trix-toolbar .trix-button.trix-active { background: #e9ecef; }
        trix-toolbar .trix-button-group--file-tools { display: none; }
    </style>
@endpush

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-adminlte-alert>
    @endif

    <x-adminlte-card icon="bi bi-ticket" title="New Support Ticket">
        <form method="POST" action="{{ route('admin.tickets.store') }}" enctype="multipart/form-data" id="create-ticket-form">
            @csrf

            <div class="row">
                <div class="col-md-6">
                    <x-adminlte-select name="customer_id" label="Customer" required>
                        <option value="">Select customer...</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}" @selected(old('customer_id') == $customer->id)>
                                {{ $customer->full_name }} ({{ $customer->user?->email }})
                            </option>
                        @endforeach
                    </x-adminlte-select>
                </div>
                <div class="col-md-6">
                    <x-adminlte-select name="assigned_to" label="Assign to (optional)">
                        <option value="">Unassigned</option>
                        @foreach ($staff as $member)
                            <option value="{{ $member->id }}" @selected(old('assigned_to') == $member->id)>
                                {{ $member->full_name }}
                            </option>
                        @endforeach
                    </x-adminlte-select>
                </div>
            </div>

            <x-adminlte-input name="subject" label="Subject" placeholder="Brief description of the issue"
                              value="{{ old('subject') }}" required />

            <div class="row">
                <div class="col-md-6">
                    <x-adminlte-select name="department" label="Department" required>
                        <option value="">Select department...</option>
                        @foreach ($departments as $key => $label)
                            <option value="{{ $key }}" @selected(old('department') === $key)>{{ $label }}</option>
                        @endforeach
                    </x-adminlte-select>
                </div>
                <div class="col-md-6">
                    <x-adminlte-select name="priority" label="Priority" required>
                        <option value="">Select priority...</option>
                        @foreach ($priorities as $key => $label)
                            <option value="{{ $key }}" @selected(old('priority') === $key)>{{ $label }}</option>
                        @endforeach
                    </x-adminlte-select>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Message <span class="text-danger">*</span></label>
                @php
                    $sig = auth()->user()?->ticket_signature;
                    $sigHtml = $sig ? '<p><br></p><p>--<br>' . str_replace("\n", '<br>', e($sig)) . '</p>' : '';
                    $defaultHtml = old('html_body', $sigHtml);
                @endphp
                <input type="hidden" id="create-html-body" name="html_body" value="{{ $defaultHtml }}">
                <input type="hidden" id="create-message" name="message" value="{{ old('message') }}">
                <trix-editor input="create-html-body" placeholder="Describe the issue in detail..."
                             class="{{ $errors->has('message') || $errors->has('html_body') ? 'is-invalid' : '' }}"></trix-editor>
                @error('message')
                    <div class="text-danger small mt-1">{{ $message }}</div>
                @enderror
                @error('html_body')
                    <div class="text-danger small mt-1">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="create-attachments" class="form-label">Attachments <span class="text-muted small">(up to 10 files, 25 MB each)</span></label>
                <input type="file" name="attachments[]" id="create-attachments" class="form-control" multiple accept="*/*">
                <div id="create-attachments-list" class="mt-2 d-flex flex-column gap-1"></div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-ticket me-1"></i> Create Ticket</button>
                <a href="{{ route('admin.tickets.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </x-adminlte-card>

    @push('js')
        <script src="{{ asset('vendor/trix/trix.js') }}"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                document.addEventListener('trix-file-accept', function (e) { e.preventDefault(); });

                var form = document.getElementById('create-ticket-form');
                if (form) {
                    form.addEventListener('submit', function () {
                        var editor = document.querySelector('#create-ticket-form trix-editor');
                        var msgInput = document.getElementById('create-message');
                        if (editor && msgInput) {
                            msgInput.value = editor.innerText.trim();
                        }
                    });
                }

                var input = document.getElementById('create-attachments');
                var list = document.getElementById('create-attachments-list');
                if (input && list) {
                    function formatSize(bytes) {
                        if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
                        if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
                        return bytes + ' B';
                    }
                    input.addEventListener('change', function () {
                        list.innerHTML = '';
                        Array.from(input.files).forEach(function (f) {
                            var item = document.createElement('div');
                            item.className = 'small d-flex align-items-center gap-1';
                            item.innerHTML = '<i class="bi bi-paperclip"></i><span class="text-truncate">' + f.name + '</span><span class="text-muted ms-1">(' + formatSize(f.size) + ')</span>';
                            list.appendChild(item);
                        });
                    });
                }
            });
        </script>
    @endpush
@stop
