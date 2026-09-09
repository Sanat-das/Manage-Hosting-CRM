@extends('adminlte::page')

@section('title', 'Edit '.$productOption->name)

@php
    $oldValues = old('values');
    $valueRows = $oldValues !== null
        ? $oldValues
        : $productOption->values->map(fn ($v) => [
            'label' => $v->label,
            'is_default' => $v->is_default,
            'sort_order' => $v->sort_order,
            'pricing' => $v->pricing->mapWithKeys(fn ($p) => [$p->billing_cycle => ['price_modifier' => $p->price_modifier]])->all(),
        ])->all();
    $nextIndex = count($valueRows);
    $selectedType = old('type', $productOption->type);
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
            <h1 class="m-0">Edit {{ $productOption->name }}</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.product-options.index') }}">Configurable Options</a></li>
                <li class="breadcrumb-item active" aria-current="page">Edit</li>
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

    {{-- Where this template is in use. Read-only: the money lives on the
         product link, and detaching here would cascade it away. --}}
    <x-adminlte-card title="Used by {{ $usedBy->count() }} {{ Str::plural('product', $usedBy->count()) }}"
                     icon="bi bi-box-seam" body-class="p-0">
        @if ($usedBy->isEmpty())
            <div class="p-3">
                <p class="text-muted mb-0">
                    No product offers this option yet. Attach it from a product&rsquo;s
                    <strong>Configurable options</strong> tab &mdash; that is where its prices are set.
                </p>
            </div>
        @else
            <ul class="list-group list-group-flush">
                @foreach ($usedBy as $usage)
                    <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span>
                            <a href="{{ route('admin.products.edit', $usage['product']) }}">{{ $usage['product']->name ?? 'Unknown product' }}</a>
                            <span class="text-muted small ms-2">
                                {{ $usage['values_count'] }} {{ Str::plural('value', $usage['values_count']) }}
                            </span>
                        </span>
                        <span>
                            @if ($usage['link']->customer_editable)
                                <span class="badge bg-primary">Customer chooses</span>
                            @else
                                <span class="badge bg-secondary">Fixed</span>
                            @endif
                            @if ($usage['has_pricing'])
                                <span class="badge bg-success">Priced</span>
                            @else
                                <span class="badge bg-light text-dark">No price set</span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>

            <div class="p-3 border-top">
                <form method="POST" action="{{ route('admin.product-options.push', $productOption) }}"
                      onsubmit="return confirm('Replace the option values and prices on {{ $usedBy->count() }} product(s) with the ones saved here? Per-product prices will be overwritten.');">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-warning">
                        <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>
                        Push these values to all {{ $usedBy->count() }} {{ Str::plural('product', $usedBy->count()) }}
                    </button>
                    <p class="text-muted small mb-0 mt-2">
                        Each product keeps its <em>own</em> copy of these values and prices, so editing them here
                        changes nothing on its own. Push overwrites those copies &mdash; including any price tuned
                        for a single product.
                    </p>
                </form>
            </div>
        @endif
    </x-adminlte-card>

    <x-adminlte.partials.form-card
        icon="bi bi-pencil-square"
        title="Option Template"
        :action="route('admin.product-options.update', $productOption)"
        method="PUT"
        submit-label="Save Changes"
        :cancel-url="route('admin.product-options.index')"
    >
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="name" label="Name" placeholder="e.g. Extra Storage"
                                  value="{{ old('name', $productOption->name) }}" required />
            </div>
            <div class="col-md-3">
                <x-adminlte-select name="type" label="Input type" id="option-type-select">
                    @foreach ($typeOptions as $value => $label)
                        <option value="{{ $value }}" @selected($selectedType === $value)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-3">
                <x-adminlte-input name="sort_order" type="number" min="0" label="Sort order"
                                  value="{{ old('sort_order', $productOption->sort_order) }}" />
            </div>
        </div>

        <div class="row">
            <div class="col-md-4" data-option-field="unit">
                <x-adminlte-input name="unit" label="Unit" placeholder="e.g. GB, vCPU"
                                  value="{{ old('unit', $productOption->unit) }}" />
                <p class="text-muted small">How the number reads on an invoice &mdash; &ldquo;200&rdquo; means nothing, &ldquo;200 GB&rdquo; is the feature.</p>
            </div>
            <div class="col-md-4" data-option-field="placeholder">
                <x-adminlte-input name="input_placeholder" label="Placeholder"
                                  placeholder="e.g. Enter amount"
                                  value="{{ old('input_placeholder', $productOption->input_placeholder) }}" />
            </div>
        </div>

        {{-- step="any" so a whole bound can be typed and spun as a whole
             number; the values are trimmed for display because the decimal:2
             cast rendered 32 as "32.00". --}}
        <div class="row" data-option-field="range">
            <div class="col-md-3">
                <x-adminlte-input name="input_min" type="number" step="any" min="0" label="Min value"
                                  value="{{ old('input_min', \App\Support\OptionNumber::format($productOption->input_min)) }}" />
            </div>
            <div class="col-md-3">
                <x-adminlte-input name="input_max" type="number" step="any" min="0" label="Max value"
                                  value="{{ old('input_max', \App\Support\OptionNumber::format($productOption->input_max)) }}" />
            </div>
            <div class="col-md-3">
                <x-adminlte-input name="input_step" type="number" step="any" min="0" label="Step"
                                  value="{{ old('input_step', \App\Support\OptionNumber::format($productOption->input_step)) }}" />
            </div>
        </div>

        {{-- Checkbox groups reuse input_max as the cap on how many values a
             customer may tick (OptionSelectionRules), so it is the one range
             field that means something for a discrete type. --}}
        <div class="row" data-option-field="max-selections">
            <div class="col-md-3">
                <x-adminlte-input name="max_selections" type="number" step="1" min="1" label="Maximum selections"
                                  value="{{ old('max_selections', \App\Support\OptionNumber::format($productOption->input_max)) }}" />
                <p class="text-muted small">Blank lets a customer tick every value.</p>
            </div>
        </div>

        <div data-option-field="values">
            <x-adminlte-card title="Option values" icon="bi bi-list-check" class="mt-3" body-class="p-3">
                <p class="text-muted small">
                    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                    Saving replaces the value list wholesale. <strong>Default</strong> is what a product charges for
                    when the option is fixed &mdash; when the customer cannot choose.
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
