@extends('adminlte::page')

@section('title', 'Edit Support Department')

@section('content_header')
    <x-ui.page-header title="Edit Support Department" subtitle="Update support department details" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Support Departments', 'url' => route('admin.ticket-departments.index')],
        ['label' => $department->name, 'active' => true],
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
        :title="'Edit ' . $department->name"
        :action="route('admin.ticket-departments.update', $department)"
        method="PUT"
        submit-label="Update Department"
        :cancel-url="route('admin.ticket-departments.index')"
    >
        @include('admin.ticket-departments._form', ['department' => $department])
    </x-adminlte.partials.form-card>
@stop
