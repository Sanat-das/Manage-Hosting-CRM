@extends('adminlte::page')

@section('title', 'Add Option Group')

@php
    $oldValues = old('values');
    $valueRows = $oldValues !== null ? $oldValues : [];
    $nextIndex = count($valueRows);
    $selectedType = old('type', 'dropdown');
    $typeLabels = [
        'dropdown' => 'Dropdown',
        'radio' => 'Radio Buttons',
        'quantity' => 'Quantity',
        'text' => 'Text',
        'number' => 'Number',
        'slider' => 'Slider',
        'checkbox' => 'Checkbox',
    ];
    $typeOptions = collect($optionTypes)->mapWithKeys(fn ($type) => [$type => $typeLabels[$type] ?? ucfirst($type)])->all();
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
                <div class="mb-3">
                    <label class="form-label">Products <span class="text-danger">*</span></label>
                    <div class="border rounded p-3" style="max-height: 220px; overflow-y: auto;">
                        @forelse ($products as $product)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="product_ids[]"
                                       value="{{ $product->id }}" id="product-{{ $product->id }}"
                                       @checked(in_array((string) $product->id, array_map('strval', (array) old('product_ids', [])), true))>
                                <label class="form-check-label" for="product-{{ $product->id }}">{{ $product->name }}</label>
                            </div>
                        @empty
                            <p class="text-muted small mb-0">No products available.</p>
                        @endforelse
                    </div>
                    <p class="text-muted small mb-0 mt-1">
                        Each product gets its own copy of the values and prices below, which you can then tune on the
                        product itself. Attachments are only editable from the product after this &mdash; detaching
                        there is what deletes a product&rsquo;s prices.
                    </p>
                </div>
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="name" label="Name" placeholder="e.g. Extra Storage"
                                  value="{{ old('name') }}" required />
                <div class="row">
                    <div class="col-md-6">
                        <x-adminlte-select name="type" label="Input type" id="option-type-select">
                            @foreach ($typeOptions as $value => $label)
                                <option value="{{ $value }}" @selected($selectedType === $value)>{{ $label }}</option>
                            @endforeach
                        </x-adminlte-select>
                    </div>
                    <div class="col-md-6">
                        <x-adminlte-input name="sort_order" type="number" min="0" label="Sort order"
                                          value="{{ old('sort_order', 0) }}" />
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4" data-option-field="unit">
                <x-adminlte-input name="unit" label="Unit" placeholder="e.g. GB, vCPU"
                                  value="{{ old('unit') }}" />
                <p class="text-muted small">How the number reads on an invoice &mdash; &ldquo;200&rdquo; means nothing, &ldquo;200 GB&rdquo; is the feature.</p>
            </div>
            <div class="col-md-4" data-option-field="placeholder">
                <x-adminlte-input name="input_placeholder" label="Placeholder"
                                  placeholder="e.g. Enter amount" value="{{ old('input_placeholder') }}" />
            </div>
        </div>

        {{-- step="any": a bound is usually a whole number, and a 0.01 spinner
             made entering 32 a chore. Fractional steps are still accepted. --}}
        <div class="row" data-option-field="range">
            <div class="col-md-3">
                <x-adminlte-input name="input_min" type="number" step="any" min="0" label="Min value"
                                  value="{{ old('input_min') }}" />
            </div>
            <div class="col-md-3">
                <x-adminlte-input name="input_max" type="number" step="any" min="0" label="Max value"
                                  value="{{ old('input_max') }}" />
            </div>
            <div class="col-md-3">
                <x-adminlte-input name="input_step" type="number" step="any" min="0" label="Step"
                                  value="{{ old('input_step') }}" />
            </div>
        </div>

        {{-- Checkbox groups reuse input_max as the cap on how many values a
             customer may tick (OptionSelectionRules), so it is the one range
             field that means something for a discrete type. --}}
        <div class="row" data-option-field="max-selections">
            <div class="col-md-3">
                <x-adminlte-input name="max_selections" type="number" step="1" min="1" label="Maximum selections"
                                  value="{{ old('max_selections') }}" />
                <p class="text-muted small">Blank lets a customer tick every value.</p>
            </div>
        </div>

        <div data-option-field="values">
            <x-adminlte-card title="Option values" icon="bi bi-list-check" class="mt-3" body-class="p-3">
                <p class="text-muted small">
                    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                    <strong>Default</strong> is what a product charges for when the option is fixed &mdash; when the
                    customer cannot choose.
                </p>
                <div id="option-values-container">
                    @foreach ($valueRows as $i => $value)
                        @include('admin.product-options._value-row', ['index' => $i, 'value' => $value])
                    @endforeach
                </div>
                <button type="button" id="add-option-value" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Add value
                </button>
            </x-adminlte-card>
        </div>

        <div data-option-field="unit-price-note">
            <x-adminlte-alert theme="info" class="mt-3">
                <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                This type is priced <strong>per unit</strong> on each product &mdash; the customer&rsquo;s number is
                multiplied by a rate set on the product&rsquo;s Configurable options tab. There is nothing to price here.
            </x-adminlte-alert>
        </div>

        <div data-option-field="text-note">
            <x-adminlte-alert theme="info" class="mt-3">
                <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                Free-form text describes the service (a hostname, a licence name). It never carries a price.
            </x-adminlte-alert>
        </div>

        {{-- Hidden JS clone template --}}
        <template id="option-value-template">
            @include('admin.product-options._value-row', ['index' => '__index__', 'value' => []])
        </template>
    </x-adminlte.partials.form-card>

    @push('js')
        @include('admin.product-options._form-js', ['nextIndex' => $nextIndex])
    @endpush
@stop
