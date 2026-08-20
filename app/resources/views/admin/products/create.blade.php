@extends('adminlte::page')

@section('title', 'Add Product')

@php
    // After a validation error the tab holding the problem stays open.
    $activeTab = 'details';
    if ($errors->any()) {
        $errorKeys = collect($errors->keys());
        if ($errorKeys->contains(fn ($key) => str_starts_with((string) $key, 'option_links') || str_starts_with((string) $key, 'option_groups'))) {
            $activeTab = 'options';
        } elseif ($errorKeys->contains(fn ($key) => str_starts_with((string) $key, 'pricing') || in_array((string) $key, [
            'payment_type', 'quantity_behaviour', 'recurring_cycles_limit', 'auto_terminate_value', 'auto_terminate_unit',
            'prorata_enabled', 'prorata_date', 'prorata_charge_next_month', 'early_renewal_mode', 'early_renewal_days',
        ], true))) {
            $activeTab = 'pricing';
        }
    }
@endphp

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Add Product</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.products.index') }}">Products</a></li>
                <li class="breadcrumb-item active" aria-current="page">Add Product</li>
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
        icon="bi bi-box"
        title="New Product"
        :action="route('admin.products.store')"
        form-id="product-create-form"
        :show-footer="false"
    >
        {{-- Save / Cancel live in the card header (card-tools), matching the
             edit page. Save submits the form by id; Cancel returns to the
             products list. --}}
        <x-slot name="tools">
            <a href="{{ route('admin.products.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-x-lg me-1" aria-hidden="true"></i> Cancel
            </a>
            <button type="submit" form="product-create-form" class="btn btn-sm btn-primary">
                <i class="bi bi-check-lg me-1" aria-hidden="true"></i> Save Product
            </button>
        </x-slot>

        {{-- Tab navigation (single form, one save) --}}
        <ul class="nav nav-tabs mb-3" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link {{ $activeTab === 'details' ? 'active' : '' }}" id="create-tab-details"
                        data-bs-toggle="tab" data-bs-target="#create-pane-details" type="button" role="tab"
                        aria-controls="create-pane-details" aria-selected="{{ $activeTab === 'details' ? 'true' : 'false' }}">
                    <i class="bi bi-info-circle me-1"></i> Details
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link {{ $activeTab === 'pricing' ? 'active' : '' }}" id="create-tab-pricing"
                        data-bs-toggle="tab" data-bs-target="#create-pane-pricing" type="button" role="tab"
                        aria-controls="create-pane-pricing" aria-selected="{{ $activeTab === 'pricing' ? 'true' : 'false' }}">
                    <i class="bi bi-currency-rupee me-1"></i> Pricing
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link {{ $activeTab === 'options' ? 'active' : '' }}" id="create-tab-options"
                        data-bs-toggle="tab" data-bs-target="#create-pane-options" type="button" role="tab"
                        aria-controls="create-pane-options" aria-selected="{{ $activeTab === 'options' ? 'true' : 'false' }}">
                    <i class="bi bi-sliders me-1"></i> Options
                </button>
            </li>
        </ul>

        <div class="tab-content">
            {{-- Details --}}
            <div class="tab-pane fade {{ $activeTab === 'details' ? 'show active' : '' }}" id="create-pane-details"
                 role="tabpanel" aria-labelledby="create-tab-details">
                <div class="row">
                    <div class="col-md-6">
                        <x-adminlte-input name="name" label="Name" placeholder="e.g. Business cPanel Hosting"
                                          value="{{ old('name') }}" required />
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <x-adminlte-select name="product_group_id" label="Product group">
                            <option value="">— None —</option>
                            @foreach ($groups as $group)
                                <option value="{{ $group->id }}" @selected((string) old('product_group_id') === (string) $group->id)>{{ $group->name }}</option>
                            @endforeach
                        </x-adminlte-select>
                    </div>
                    <div class="col-md-6">
                        <x-adminlte-select name="billing_cycle" label="Default billing cycle" required>
                            @foreach ($defaultCycles as $value => $label)
                                <option value="{{ $value }}" @selected(old('billing_cycle', 'monthly') === $value)>{{ $label }}</option>
                            @endforeach
                        </x-adminlte-select>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <x-adminlte-select name="provisioning_module" label="Provisioning module" required>
                            @foreach ($provisioningModules as $value => $label)
                                <option value="{{ $value }}" @selected(old('provisioning_module', 'manual') === $value)>{{ $label }}</option>
                            @endforeach
                        </x-adminlte-select>
                    </div>
                    <div class="col-md-6">
                        <x-adminlte-select name="server_group_id" label="Server group">
                            <option value="">— None —</option>
                            @foreach ($serverGroups as $serverGroup)
                                <option value="{{ $serverGroup->id }}" @selected((string) old('server_group_id') === (string) $serverGroup->id)>{{ $serverGroup->name }}</option>
                            @endforeach
                        </x-adminlte-select>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <x-adminlte-select name="welcome_email_template_id" label="Welcome email template">
                            <option value="">— None —</option>
                            @foreach ($emailTemplates as $template)
                                <option value="{{ $template->id }}" @selected((string) old('welcome_email_template_id') === (string) $template->id)>{{ $template->name }}</option>
                            @endforeach
                        </x-adminlte-select>
                    </div>
                    <div class="col-md-6">
                        <x-adminlte-select name="status" label="Status">
                            <option value="active" @selected(old('status', 'active') === 'active')>Active</option>
                            <option value="inactive" @selected(old('status') === 'inactive')>Inactive</option>
                        </x-adminlte-select>
                    </div>
                </div>

                <x-adminlte-input name="sort_order" type="number" min="0" label="Sort order"
                                  value="{{ old('sort_order', 0) }}" />

                <x-adminlte-textarea name="description" label="Description" rows="2"
                                     placeholder="Optional product description">{{ old('description') }}</x-adminlte-textarea>

                <div class="row">
                    @foreach ([
                        'require_domain' => 'Requires a domain',
                        'show_in_order' => 'Visible in order form',
                        'show_in_affiliate' => 'Visible to affiliates',
                        'only_admin' => 'Admin-only ordering',
                    ] as $field => $label)
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="{{ $field }}" value="1"
                                       id="{{ $field }}" @checked(old($field, true))>
                                <label class="form-check-label" for="{{ $field }}">{{ $label }}</label>
                            </div>
                        </div>
                    @endforeach

                    @foreach ([
                        'require_public_ip' => 'Requires a public IP',
                        'require_private_ip' => 'Requires a private IP',
                        'is_bundle' => 'This is a bundle',
                    ] as $field => $label)
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="{{ $field }}" value="1"
                                       id="{{ $field }}" @checked(old($field, false))>
                                <label class="form-check-label" for="{{ $field }}">{{ $label }}</label>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Pricing --}}
            <div class="tab-pane fade {{ $activeTab === 'pricing' ? 'show active' : '' }}" id="create-pane-pricing"
                 role="tabpanel" aria-labelledby="create-tab-pricing">
                @include('admin.products._pricing')
            </div>

            {{-- Options --}}
            <div class="tab-pane fade {{ $activeTab === 'options' ? 'show active' : '' }}" id="create-pane-options"
                 role="tabpanel" aria-labelledby="create-tab-options">
                @include('admin.products._options_create')
            </div>
        </div>
    </x-adminlte.partials.form-card>
@stop
