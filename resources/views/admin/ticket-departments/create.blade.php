@extends('adminlte::page')

@section('title', 'Add Support Department')

@section('content_header')
    <x-ui.page-header title="Add Support Department" subtitle="Create a new support department" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Support Departments', 'url' => route('admin.ticket-departments.index')],
        ['label' => 'Add Department', 'active' => true],
    ]" />
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
        title="New Department"
        :action="route('admin.ticket-departments.store')"
        submit-label="Save Department"
        :cancel-url="route('admin.ticket-departments.index')"
    >
        @include('admin.ticket-departments._form', ['department' => $department])
    </x-adminlte.partials.form-card>
@stop
