@extends('adminlte::page')

@section('title', 'Edit '.$product->name)

@php
    // Enable the SortableJS plugin so @pluginScripts injects it for the
    // option-link drag-to-reorder on the Options tab.
    app(\ColorlibHQ\AdminLte\Plugins\PluginManager::class)->enable('sortablejs');

    // After a validation error the tab holding the problem stays open. After a
    // successful save the update redirects back here, so the flashed tab
    // (the one the user was editing) is restored too.
    $activeTab = session('active_tab', 'details');
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
            <h1 class="m-0">Edit {{ $product->name }}</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.products.index') }}">Products</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.products.show', $product) }}">{{ $product->name }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Edit</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif
    @if (session('error'))
        <x-adminlte-alert theme="danger" dismissible>{{ session('error') }}</x-adminlte-alert>
    @endif
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
        icon="bi bi-pencil-square"
        title="Edit Product"
        :action="route('admin.products.update', $product)"
        method="PUT"
        form-id="product-edit-form"
        :show-footer="false"
    >
        {{-- Save / Cancel live in the card header (card-tools), attached to
             the Edit Product container. Save submits the form by id; Cancel
             returns to the product's show page. --}}
        <x-slot name="tools">
            <a href="{{ route('admin.products.show', $product) }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-x-lg me-1" aria-hidden="true"></i> Cancel
            </a>
            <button type="submit" form="product-edit-form" class="btn btn-sm btn-primary">
                <i class="bi bi-check-lg me-1" aria-hidden="true"></i> Save Changes
            </button>
        </x-slot>

        <input type="hidden" name="active_tab" id="active-tab-input" value="{{ $activeTab }}">

        {{-- Tab navigation (single form, one save) --}}
        <ul class="nav nav-tabs mb-3" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link {{ $activeTab === 'details' ? 'active' : '' }}" id="edit-tab-details"
                        data-bs-toggle="tab" data-bs-target="#edit-pane-details" type="button" role="tab"
                        aria-controls="edit-pane-details" aria-selected="{{ $activeTab === 'details' ? 'true' : 'false' }}">
                    <i class="bi bi-info-circle me-1"></i> Details
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link {{ $activeTab === 'pricing' ? 'active' : '' }}" id="edit-tab-pricing"
                        data-bs-toggle="tab" data-bs-target="#edit-pane-pricing" type="button" role="tab"
                        aria-controls="edit-pane-pricing" aria-selected="{{ $activeTab === 'pricing' ? 'true' : 'false' }}">
                    <i class="bi bi-currency-rupee me-1"></i> Pricing
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link {{ $activeTab === 'options' ? 'active' : '' }}" id="edit-tab-options"
                        data-bs-toggle="tab" data-bs-target="#edit-pane-options" type="button" role="tab"
                        aria-controls="edit-pane-options" aria-selected="{{ $activeTab === 'options' ? 'true' : 'false' }}">
                    <i class="bi bi-sliders me-1"></i> Options
                </button>
            </li>
        </ul>

        <div class="tab-content">
            {{-- Details --}}
            <div class="tab-pane fade {{ $activeTab === 'details' ? 'show active' : '' }}" id="edit-pane-details"
                 role="tabpanel" aria-labelledby="edit-tab-details">
                <div class="row">
                    <div class="col-md-6">
                        <x-adminlte-input name="name" label="Name" placeholder="e.g. Business cPanel Hosting"
                                          value="{{ old('name', $product->name) }}" required />
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <x-adminlte-select name="product_group_id" label="Product group">
                            <option value="">— None —</option>
                            @foreach ($groups as $group)
                                <option value="{{ $group->id }}" @selected((string) old('product_group_id', $product->product_group_id) === (string) $group->id)>{{ $group->name }}</option>
                            @endforeach
                        </x-adminlte-select>
                    </div>
                    <div class="col-md-6">
                        <x-adminlte-select name="billing_cycle" label="Default billing cycle" required>
                            @foreach ($defaultCycles as $value => $label)
                                <option value="{{ $value }}" @selected(old('billing_cycle', $product->billing_cycle) === $value)>{{ $label }}</option>
                            @endforeach
                        </x-adminlte-select>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <x-adminlte-select name="provisioning_module" label="Provisioning module" required>
                            @foreach ($provisioningModules as $value => $label)
                                <option value="{{ $value }}" @selected(old('provisioning_module', $product->provisioning_module) === $value)>{{ $label }}</option>
                            @endforeach
                        </x-adminlte-select>
                    </div>
                    <div class="col-md-6">
                        <x-adminlte-select name="server_group_id" label="Server group">
                            <option value="">— None —</option>
                            @foreach ($serverGroups as $serverGroup)
                                <option value="{{ $serverGroup->id }}" @selected((string) old('server_group_id', $product->server_group_id) === (string) $serverGroup->id)>{{ $serverGroup->name }}</option>
                            @endforeach
                        </x-adminlte-select>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <x-adminlte-select name="welcome_email_template_id" label="Welcome email template">
                            <option value="">— None —</option>
                            @foreach ($emailTemplates as $template)
                                <option value="{{ $template->id }}" @selected((string) old('welcome_email_template_id', $product->welcome_email_template_id) === (string) $template->id)>{{ $template->name }}</option>
                            @endforeach
                        </x-adminlte-select>
                    </div>
                    <div class="col-md-6">
                        <x-adminlte-select name="status" label="Status">
                            <option value="active" @selected(old('status', $product->status) === 'active')>Active</option>
                            <option value="inactive" @selected(old('status', $product->status) === 'inactive')>Inactive</option>
                        </x-adminlte-select>
                    </div>
                </div>

                <x-adminlte-input name="sort_order" type="number" min="0" label="Sort order"
                                  value="{{ old('sort_order', $product->sort_order) }}" />

                <x-adminlte-textarea name="description" label="Description" rows="2"
                                     placeholder="Optional product description">{{ old('description', $product->description) }}</x-adminlte-textarea>

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
                                       id="{{ $field }}" @checked(old($field, $product->{$field}))>
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
                                       id="{{ $field }}" @checked(old($field, $product->{$field}))>
                                <label class="form-check-label" for="{{ $field }}">{{ $label }}</label>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Pricing --}}
            <div class="tab-pane fade {{ $activeTab === 'pricing' ? 'show active' : '' }}" id="edit-pane-pricing"
                 role="tabpanel" aria-labelledby="edit-tab-pricing">
                @include('admin.products._pricing')
            </div>

            {{-- Options --}}
            <div class="tab-pane fade {{ $activeTab === 'options' ? 'show active' : '' }}" id="edit-pane-options"
                 role="tabpanel" aria-labelledby="edit-tab-options">
                {{-- Attach picker: a JS-fetch action (not a nested form), so it can
                     live inside the update form. Reloads after attaching. --}}
                <div class="d-flex flex-wrap gap-2 align-items-end mb-3 border rounded p-2">
                    <div class="flex-grow-1" style="min-width: 220px;">
                        <label class="form-label small text-muted mb-1" for="option-group-select">Attach an option group</label>
                        <select id="option-group-select" class="form-select form-select-sm">
                            <option value="">— Select option group —</option>
                            @foreach ($availableGroups as $group)
                                <option value="{{ $group->id }}">{{ $group->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" value="1" id="new-link-customer-editable">
                        <label class="form-check-label" for="new-link-customer-editable">Customer editable</label>
                    </div>
                    <button type="button" id="option-attach-btn" class="btn btn-sm btn-primary mb-1">
                        <i class="bi bi-link-45deg me-1"></i> Attach
                    </button>
                </div>

                @include('admin.products._options')
            </div>
        </div>
    </x-adminlte.partials.form-card>

    {{-- Per-link action forms (sync) and detach confirm modals: separate from
         the update form, referenced from the option cards via form= / modal
         triggers. --}}
    @foreach ($product->optionLinks as $link)
        @if ($link->group && ! in_array($link->group->type, \App\Models\ProductOptionGroup::CONTINUOUS_TYPES, true))
            <form method="POST" action="{{ route('admin.products.options.sync', [$product, $link]) }}"
                  id="sync-{{ $link->id }}" class="d-none"
                  onsubmit="return confirm('Replace this product\'s values with the group\'s current values? Per-product tweaks will be overwritten.');">
                @csrf
            </form>
        @endif
        <x-adminlte.partials.confirm-modal
            :id="'detach-link-' . $link->id"
            title="Detach option group"
            :message="'Detach ' . ($link->group?->name ?? 'this option group') . ' from ' . $product->name . '? This removes the attached values and their price modifiers.'"
            :action="route('admin.products.options.detach', [$product, $link])"
            method="DELETE"
            confirm-label="Detach"
        />
    @endforeach

    @push('js')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                // Keep the active pane on the same tab after a save redirect or
                // the attach reload: track the visible tab in the hidden input
                // (submitted with the form) and in sessionStorage (survives the
                // client-side attach reload).
                var activeTabInput = document.getElementById('active-tab-input');
                var syncTab = function (name) {
                    if (activeTabInput) activeTabInput.value = name;
                    try { sessionStorage.setItem('product-edit-active-tab', name); } catch (e) {}
                };
                document.querySelectorAll('[data-bs-toggle="tab"]').forEach(function (tab) {
                    tab.addEventListener('shown.bs.tab', function () {
                        syncTab(tab.getAttribute('data-bs-target').replace('#edit-pane-', ''));
                    });
                });

                @if (! $errors->any())
                // Restore the tab the user was on before an attach reload. Not
                // run after validation errors — the server already re-opened
                // the pane that holds the problem.
                var storedTab = null;
                try { storedTab = sessionStorage.getItem('product-edit-active-tab'); } catch (e) {}
                if (storedTab) {
                    var restoreTab = document.getElementById('edit-tab-' + storedTab);
                    if (restoreTab) restoreTab.click();
                }
                @endif

                var attachBtn = document.getElementById('option-attach-btn');
                var select = document.getElementById('option-group-select');
                if (!attachBtn || !select) return;

                attachBtn.addEventListener('click', function () {
                    if (!select.value) return;

                    var data = new FormData();
                    data.append('_token', document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '');
                    data.append('option_group_id', select.value);
                    var editable = document.getElementById('new-link-customer-editable');
                    data.append('customer_editable', editable && editable.checked ? '1' : '0');

                    fetch('{{ route('admin.products.options.attach', $product) }}', { method: 'POST', body: data })
                        .finally(function () {
                            window.location.href = '{{ route('admin.products.edit', $product) }}';
                        });
                });
            });
        </script>
    @endpush
@stop
