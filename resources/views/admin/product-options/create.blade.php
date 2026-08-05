@extends('adminlte::page')

@section('title', 'Add Option Group')

@php
    $cycles = \App\Models\Product::BILLING_CYCLES;
    $oldValues = old('values');
    $valueRows = $oldValues !== null ? $oldValues : [];
    $nextIndex = count($valueRows);
    $typeOptions = ['dropdown' => 'Dropdown', 'radio' => 'Radio Buttons', 'quantity' => 'Quantity'];
@endphp

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Add Option Group</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.product-options.index') }}">Configurable Options</a></li>
                <li class="breadcrumb-item active" aria-current="page">Add Option Group</li>
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
        icon="bi bi-sliders"
        title="New Option Group"
        :action="route('admin.product-options.store')"
        submit-label="Save Option Group"
        :cancel-url="route('admin.product-options.index')"
    >
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="product_id" label="Product" required>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}" @selected((string) old('product_id') === (string) $product->id)>{{ $product->name }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="name" label="Name" placeholder="e.g. Extra Storage"
                                  value="{{ old('name') }}" required />
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="type" label="Input type">
                    @foreach ($typeOptions as $value => $label)
                        <option value="{{ $value }}" @selected(old('type', 'dropdown') === $value)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="sort_order" type="number" min="0" label="Sort order"
                                  value="{{ old('sort_order', 0) }}" />
            </div>
        </div>

        {{-- Option values --}}
        <x-adminlte-card title="Option values" icon="bi bi-list-check" class="mt-3" body-class="p-3">
            <div id="option-values-container">
                @foreach ($valueRows as $i => $value)
                    @include('admin.product-options._value-row', ['index' => $i, 'value' => $value])
                @endforeach
            </div>
            <button type="button" id="add-option-value" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Add value
            </button>
            <p class="text-muted small mb-0 mt-2">
                <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                Each value can carry an optional price modifier per billing cycle (blank = no charge).
            </p>
        </x-adminlte-card>

        {{-- Hidden JS clone template --}}
        <template id="option-value-template">
            @include('admin.product-options._value-row', ['index' => '__index__', 'value' => []])
        </template>
    </x-adminlte.partials.form-card>

    @push('js')
        <script>
            (function () {
                const container = document.getElementById('option-values-container');
                const template = document.getElementById('option-value-template');
                const addBtn = document.getElementById('add-option-value');
                if (!container || !template || !addBtn) return;

                let index = {{ $nextIndex }};

                addBtn.addEventListener('click', function () {
                    container.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__index__', index));
                    index++;
                });

                container.addEventListener('click', function (e) {
                    const btn = e.target.closest('.remove-option-value');
                    if (btn) btn.closest('.option-value-row').remove();
                });
            })();
        </script>
    @endpush
@stop
