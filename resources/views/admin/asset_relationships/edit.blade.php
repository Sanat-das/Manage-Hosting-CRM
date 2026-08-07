@extends('adminlte::page')
@section('title', 'Edit Asset Relationship #'.$relationship->id)
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Edit Relationship #{{ $relationship->id }}</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.asset-relationships.index') }}">Asset Relationships</a></li><li class="breadcrumb-item active">Edit</li></ol></div></div>
@stop
@section('content')
    @if ($errors->any()) <x-adminlte-alert theme="danger" dismissible><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-adminlte-alert> @endif
    <x-adminlte.partials.form-card icon="bi bi-diagram-3" title="Edit Asset Relationship" :action="route('admin.asset-relationships.update', $relationship)" submit-label="Update" :cancel-url="route('admin.asset-relationships.index')">
        @method('PUT')
        <div class="row">
            <div class="col-md-3">
                <x-adminlte-select name="parent_kind" label="Parent kind" required>
                    @foreach ($kinds as $value => $label)
                        <option value="{{ $value }}" @selected(old('parent_kind', $relationship->parent_kind) === $value)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-3"><x-adminlte-input name="parent_id" type="number" min="1" label="Parent ID" value="{{ old('parent_id', $relationship->parent_id) }}" required /></div>
            <div class="col-md-3">
                <x-adminlte-select name="child_kind" label="Child kind" required>
                    @foreach ($kinds as $value => $label)
                        <option value="{{ $value }}" @selected(old('child_kind', $relationship->child_kind) === $value)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-3"><x-adminlte-input name="child_id" type="number" min="1" label="Child ID" value="{{ old('child_id', $relationship->child_id) }}" required /></div>
        </div>
        <div class="row">
            <div class="col-md-4">
                <x-adminlte-select name="relationship_type" label="Relationship type" required>
                    @foreach ($types as $type)
                        <option value="{{ $type }}" @selected(old('relationship_type', $relationship->relationship_type) === $type)>{{ $type }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-4"><x-adminlte-input name="label" label="Label (optional)" value="{{ old('label', $relationship->label) }}" /></div>
            <div class="col-md-4"><x-adminlte-input name="sort_order" type="number" min="0" label="Sort order" value="{{ old('sort_order', $relationship->sort_order) }}" /></div>
        </div>
        <x-adminlte-textarea name="notes" label="Notes (optional)" rows="2">{{ old('notes', $relationship->notes) }}</x-adminlte-textarea>
    </x-adminlte.partials.form-card>
@stop
