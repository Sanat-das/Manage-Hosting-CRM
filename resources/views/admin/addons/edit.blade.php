@extends('adminlte::page')

@section('title', 'Edit: ' . $addon->name)

@section('content_header')
    <x-ui.page-header title="Edit Add-on" subtitle="Update add-on details" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Add-ons','url' => route('admin.addons.index')],['label' => $addon->name,'active' => true]]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

    <x-adminlte.partials.form-card
        icon="bi bi-plus-square"
        title="Edit: {{ $addon->name }}"
        :action="route('admin.addons.update', $addon)"
        submit-label="Save Changes"
        :cancel-url="route('admin.addons.index')"
    >
        @method('PUT')

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="name" label="Name" placeholder="Add-on name"
                                  value="{{ old('name', $addon->name) }}" required />
            </div>
            <div class="col-md-6">
                <x-adminlte-select name="product_id" label="Product (optional)">
                    <option value="">Global (available for all products)</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}" @selected(old('product_id', $addon->product_id) == $product->id)>{{ $product->name }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>

        <x-adminlte-textarea name="description" label="Description" rows="2"
                             placeholder="Brief description (optional)">{{ old('description', $addon->description) }}</x-adminlte-textarea>

        <div class="row">
            <div class="col-md-4">
                <x-adminlte-select name="billing_cycle" label="Billing cycle (default)" required>
                    @foreach (['one_time' => 'One Time', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'semi_annual' => 'Semi-Annual', 'annual' => 'Annual'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('billing_cycle', $addon->billing_cycle) === $value)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-4">
                <x-adminlte-input name="price" type="number" step="0.01" min="0" label="Price (default)"
                                  value="{{ old('price', $addon->price) }}" required />
            </div>
            <div class="col-md-4">
                <x-adminlte-input name="setup_fee" type="number" step="0.01" min="0" label="Setup fee (default)"
                                  value="{{ old('setup_fee', $addon->setup_fee) }}" />
            </div>
        </div>

        @php
            $pricingRows = $addon->pricing->keyBy('billing_cycle');
            $selectedCycles = is_array(old('pricing_cycles')) ? old('pricing_cycles') : $pricingRows->keys()->all();
        @endphp

        <div class="border-top pt-3 mt-4">
            <h6 class="mb-2"><i class="bi bi-diagram-3 me-1"></i>Per-cycle pricing</h6>
            <p class="text-muted small mb-2">
                <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                The default cycle, price and setup fee above apply to any cycle without a row here.
                Enable a cycle to give this add-on its own price and setup fee for that billing cycle.
            </p>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="min-width: 140px;">Billing cycle</th>
                            <th class="text-end" style="min-width: 130px;">Price</th>
                            <th class="text-end" style="min-width: 130px;">Setup fee</th>
                            <th class="text-center" style="width: 70px;">Enable</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($cycles as $cycle => $cycleLabel)
                            <tr>
                                <td><strong>{{ $cycleLabel }}</strong></td>
                                <td class="text-end">
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text px-1">₹</span>
                                        <input type="number" step="0.01" min="0"
                                               name="pricing[{{ $cycle }}][price]"
                                               class="form-control text-end"
                                               value="{{ old("pricing.$cycle.price", $pricingRows[$cycle]->price ?? '') }}"
                                               placeholder="0.00">
                                    </div>
                                </td>
                                <td class="text-end">
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text px-1">₹</span>
                                        <input type="number" step="0.01" min="0"
                                               name="pricing[{{ $cycle }}][setup_fee]"
                                               class="form-control text-end"
                                               value="{{ old("pricing.$cycle.setup_fee", $pricingRows[$cycle]->setup_fee ?? '') }}"
                                               placeholder="0.00">
                                    </div>
                                </td>
                                <td class="text-center">
                                    <div class="form-check form-switch d-inline-block mb-0">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                               id="pricing-cycle-{{ $cycle }}"
                                               name="pricing_cycles[]" value="{{ $cycle }}"
                                               @checked(in_array($cycle, $selectedCycles, true))
                                               title="Enable a per-cycle price for {{ $cycleLabel }}">
                                        <label class="form-check-label" for="pricing-cycle-{{ $cycle }}"></label>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <x-adminlte-select name="status" label="Status">
            @foreach (['active' => 'Active', 'inactive' => 'Inactive'] as $value => $label)
                <option value="{{ $value }}" @selected(old('status', $addon->status) === $value)>{{ $label }}</option>
            @endforeach
        </x-adminlte-select>
    </x-adminlte.partials.form-card>
@stop

