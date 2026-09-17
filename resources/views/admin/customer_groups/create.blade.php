@extends('adminlte::page')

@section('title', 'New Customer Group')

@section('content_header')
    <x-ui.page-header title="New Customer Group" subtitle="Create a new customer group for segmentation" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')], ['label' => 'Customer Groups', 'url' => route('admin.customer-groups.index')], ['label' => 'New', 'active' => true]]" />
@stop

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-adminlte-alert>
    @endif

    <x-adminlte.partials.form-card icon="bi bi-folder" title="Create Customer Group"
        :action="route('admin.customer-groups.store')" submit-label="Create Group"
        :cancel-url="route('admin.customer-groups.index')">
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="name" label="Group Name" value="{{ old('name') }}" required />
            </div>
            <div class="col-md-3">
                <x-adminlte-select name="parent_id" label="Parent Group">
                    <option value="">None</option>
                    @foreach ($parentGroups as $pg)
                        <option value="{{ $pg->id }}" @selected(old('parent_id') == $pg->id)>{{ $pg->name }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-3">
                <x-adminlte-select name="status" label="Status">
                    <option value="active" @selected(old('status', 'active') === 'active')>Active</option>
                    <option value="inactive" @selected(old('status') === 'inactive')>Inactive</option>
                </x-adminlte-select>
            </div>
        </div>
        <x-adminlte-textarea name="description" label="Description" rows="2">{{ old('description') }}</x-adminlte-textarea>
        <x-adminlte-input name="sort_order" type="number" min="0" label="Sort Order" value="{{ old('sort_order', 0) }}" />
    </x-adminlte.partials.form-card>
@stop
