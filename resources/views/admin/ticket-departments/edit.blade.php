@extends('adminlte::page')

@section('title', 'Edit Support Department')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Edit Support Department</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.ticket-departments.index') }}">Support Departments</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $department->name }}</li>
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
        icon="bi bi-diagram-2"
        :title="'Edit ' . $department->name"
        :action="route('admin.ticket-departments.update', $department)"
        method="PUT"
        submit-label="Update Department"
        :cancel-url="route('admin.ticket-departments.index')"
    >
        @include('admin.ticket-departments._form', ['department' => $department])
    </x-adminlte.partials.form-card>
@stop
