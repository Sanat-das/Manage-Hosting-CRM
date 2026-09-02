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

    <x-adminlte.partials.form-card
        icon="bi bi-ticket"
        title="New Support Ticket"
        :action="route('admin.tickets.store')"
        submit-label="Create Ticket"
        :cancel-url="route('admin.tickets.index')"
    >
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

        <x-adminlte-textarea name="message" label="Message" rows="6"
                             placeholder="Describe the issue in detail..." required>{{ old('message') }}</x-adminlte-textarea>
    </x-adminlte.partials.form-card>
@stop
