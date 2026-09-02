@extends('adminlte::page')

@section('title', 'Product — '.$product->name)

@php
    $cycleLabels = \App\Models\Product::BILLING_CYCLES;
    $activeTab = (string) request()->query('tab', 'overview');
    $tabs = [
        ['id' => 'overview', 'label' => 'Overview', 'icon' => 'bi bi-info-circle'],
        ['id' => 'pricing', 'label' => 'Pricing', 'icon' => 'bi bi-currency-rupee', 'badge' => $product->pricing->count()],
        ['id' => 'options', 'label' => 'Options', 'icon' => 'bi bi-sliders', 'badge' => $product->options->count()],
        ['id' => 'addons', 'label' => 'Add-ons', 'icon' => 'bi bi-plus-square', 'badge' => $product->addons->count()],
        ['id' => 'modules', 'label' => 'Modules', 'icon' => 'bi bi-puzzle', 'badge' => $product->moduleLinks->where('enabled', true)->count()],
    ];
@endphp

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">{{ $product->name }}</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.products.index') }}">Products</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $product->name }}</li>
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

    {{-- Product header --}}
    <x-adminlte-card>
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center rounded-circle bg-primary text-white"
                 style="width: 56px; height: 56px; font-size: 1.25rem; flex-shrink: 0;">
                <i class="bi bi-box"></i>
            </div>
            <div class="flex-grow-1">
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <h4 class="mb-0">{{ $product->name }}</h4>
                    @if ($product->isBundle())
                        <span class="badge text-bg-success">Bundle</span>
                    @endif
                    <x-adminlte.partials.status-badge :status="$product->status" />
                    @if ($product->group)
                        <span class="badge text-bg-secondary">{{ $product->group->name }}</span>
                    @endif
                </div>
                <div class="text-muted mt-1">
                    <i class="bi bi-currency-rupee me-1"></i>{{ number_format($product->price, 2) }}
                    <span class="mx-2">|</span><i class="bi bi-arrow-repeat me-1"></i>{{ $cycleLabels[$product->billing_cycle] ?? $product->billing_cycle }}
                    @if ($product->description)
                        <div class="mt-1">{{ $product->description }}</div>
                    @endif
                </div>
            </div>
            <div class="d-flex gap-2">
                @can('products.edit')
                    <a href="{{ route('admin.products.edit', $product) }}" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-pencil me-1"></i> Edit
                    </a>
                @endcan
                @can('products.delete')
                    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
                            data-bs-target="#delete-product-modal">
                        <i class="bi bi-trash me-1"></i> Delete
                    </button>
                @endcan
            </div>
        </div>
    </x-adminlte-card>

    {{-- Metric row --}}
    <x-adminlte.partials.metric-cards :items="[
        ['title' => $product->pricing->count(), 'text' => 'Price Points', 'icon' => 'bi bi-currency-rupee', 'theme' => 'primary'],
        ['title' => $product->options->count(), 'text' => 'Option Groups', 'icon' => 'bi bi-sliders', 'theme' => 'warning'],
        ['title' => $product->options->sum(fn ($option) => $option->values->count()), 'text' => 'Option Values', 'icon' => 'bi bi-list-check', 'theme' => 'success'],
        ['title' => $product->addons->count(), 'text' => 'Add-ons', 'icon' => 'bi bi-plus-square', 'theme' => 'info'],
    ]" />

    <x-adminlte-card>
        <x-adminlte.partials.detail-tabs :tabs="$tabs" :active-tab="$activeTab">
            {{-- Overview --}}
            <div class="tab-pane fade {{ $activeTab === 'overview' ? 'show active' : '' }}" id="overview" role="tabpanel" aria-labelledby="overview-tab">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tbody>
                                <tr><th class="w-25 text-muted">Name</th><td>{{ $product->name }}</td></tr>
                                <tr><th class="text-muted">Group</th><td>{{ $product->group?->name ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Default cycle</th><td>{{ $cycleLabels[$product->billing_cycle] ?? $product->billing_cycle }}</td></tr>
                                <tr><th class="text-muted">Price</th><td>{{ number_format($product->price, 2) }}</td></tr>
                                <tr><th class="text-muted">Setup fee</th><td>{{ number_format($product->setup_fee, 2) }}</td></tr>
                                <tr><th class="text-muted">Provisioning</th><td>{{ ucfirst(str_replace('_', ' ', $product->provisioning_module)) }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tbody>
                                <tr><th class="w-25 text-muted">Sort order</th><td>{{ $product->sort_order }}</td></tr>
                                <tr><th class="text-muted">Requires domain</th><td>{{ $product->require_domain ? 'Yes' : 'No' }}</td></tr>
                                <tr><th class="text-muted">Show in order form</th><td>{{ $product->show_in_order ? 'Yes' : 'No' }}</td></tr>
                                <tr><th class="text-muted">Show to affiliates</th><td>{{ $product->show_in_affiliate ? 'Yes' : 'No' }}</td></tr>
                                <tr><th class="text-muted">Admin-only ordering</th><td>{{ $product->only_admin ? 'Yes' : 'No' }}</td></tr>
                                <tr><th class="text-muted">Server group</th><td>{{ $product->server_group_id ? 'Group #'.$product->server_group_id : '—' }}</td></tr>
                                <tr><th class="text-muted">Created</th><td>{{ $product->created_at?->format('M j, Y H:i') }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-12">
                        <h6 class="text-muted mt-3">GST</h6>
                        <table class="table table-sm table-borderless">
                            <tbody>
                                <tr><th class="text-muted w-25">Per-product GST</th><td>{{ $product->gst_enabled ? 'Enabled' : 'Disabled' }}</td></tr>
                                <tr><th class="text-muted">GST type</th><td>{{ ucfirst(str_replace('_', ' ', $product->gst_type)) }}</td></tr>
                                <tr><th class="text-muted">GST / CGST / SGST / IGST</th>
                                    <td>{{ $product->gst_rate ?? '—' }} / {{ $product->cgst_rate ?? '—' }} / {{ $product->sgst_rate ?? '—' }} / {{ $product->igst_rate ?? '—' }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Pricing --}}
            <div class="tab-pane fade {{ $activeTab === 'pricing' ? 'show active' : '' }}" id="pricing" role="tabpanel" aria-labelledby="pricing-tab">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Cycle</th>
                                <th class="text-end">Price</th>
                                <th class="text-end">Setup fee</th>
                                <th class="text-end">Promo price</th>
                                <th>Promo window</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($product->pricing->sortBy(fn ($row) => array_search($row->billing_cycle, array_keys($cycleLabels), true) ?: 99) as $row)
                                <tr>
                                    <td><strong>{{ $cycleLabels[$row->billing_cycle] ?? ucfirst($row->billing_cycle) }}</strong></td>
                                    <td class="text-end">{{ number_format($row->price, 2) }}</td>
                                    <td class="text-end">{{ number_format($row->setup_fee, 2) }}</td>
                                    <td class="text-end">{{ $row->promo_price !== null ? number_format($row->promo_price, 2) : '—' }}</td>
                                    <td class="text-muted">
                                        @if ($row->promo_start || $row->promo_end)
                                            {{ $row->promo_start?->format('M j, Y') ?? '—' }} → {{ $row->promo_end?->format('M j, Y') ?? '—' }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted py-3">No pricing configured.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Options --}}
            <div class="tab-pane fade {{ $activeTab === 'options' ? 'show active' : '' }}" id="options" role="tabpanel" aria-labelledby="options-tab">
                {{-- Attached option links: product-scoped copies of the groups --}}
                @forelse ($product->optionLinks as $link)
                    <div class="border rounded p-3 mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <strong>{{ $link->group?->name ?? 'Option group #'.$link->option_group_id }}</strong>
                                @if ($link->group)
                                    <span class="badge text-bg-info">{{ ucfirst($link->group->type) }}</span>
                                @endif
                            </div>
                            <span class="badge {{ $link->customer_editable ? 'text-bg-success' : 'text-bg-secondary' }}">
                                {{ $link->customer_editable ? 'Customer editable' : 'Admin only' }}
                            </span>
                        </div>
                        @if ($link->linkValues->isEmpty())
                            <p class="text-muted small mb-0">No values defined.</p>
                        @else
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Value</th>
                                            <th class="text-end">{{ $cycleLabels[$product->billing_cycle] ?? $product->billing_cycle }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($link->linkValues as $value)
                                            <tr>
                                                <td>
                                                    <strong>{{ $value->label }}</strong>
                                                    @if ($value->is_default)
                                                        <span class="badge text-bg-primary ms-1">Default</span>
                                                    @endif
                                                </td>
                                                @php
                                                    $modifier = $value->pricing->firstWhere('billing_cycle', $product->billing_cycle);
                                                @endphp
                                                <td class="text-end text-muted">{{ $modifier ? number_format($modifier->price_modifier, 2) : '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="text-muted mb-0">No option groups attached.</p>
                @endforelse
            </div>

            {{-- Add-ons --}}
            <div class="tab-pane fade {{ $activeTab === 'addons' ? 'show active' : '' }}" id="addons" role="tabpanel" aria-labelledby="addons-tab">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Cycle</th>
                                <th class="text-end">Setup fee</th>
                                <th class="text-end">Price</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($product->addons as $addon)
                                <tr>
                                    <td>
                                        <strong>{{ $addon->name }}</strong>
                                        @if ($addon->description)
                                            <div class="text-muted small">{{ $addon->description }}</div>
                                        @endif
                                    </td>
                                    <td class="text-muted">{{ $cycleLabels[$addon->billing_cycle] ?? ucfirst($addon->billing_cycle) }}</td>
                                    <td class="text-end">{{ number_format($addon->setup_fee, 2) }}</td>
                                    <td class="text-end">{{ number_format($addon->price, 2) }}</td>
                                    <td><x-adminlte.partials.status-badge :status="$addon->status" /></td>
                                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-3">No add-ons attached.</td></tr>
            @endforelse
        </tbody>
    </table>
                </div>
            </div>

            {{-- Modules — read-only: name + basics only --}}
            <div class="tab-pane fade {{ $activeTab === 'modules' ? 'show active' : '' }}" id="modules" role="tabpanel" aria-labelledby="modules-tab">
                @php
                    $enabledLinks = $product->moduleLinks->where('enabled', true);
                    $activeModules = $modules->filter(fn ($m) => $enabledLinks->firstWhere('module_id', $m->id) !== null)->values();
                @endphp
                @if ($modules->isEmpty())
                    <p class="text-muted mb-0">No modules available. Activate modules in System → Modules first.</p>
                @elseif ($activeModules->isEmpty())
                    <p class="text-muted mb-0">No active modules on this product.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Module</th>
                                    <th>Version</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($activeModules as $module)
                                    <tr>
                                        <td><strong>{{ $module->name }}</strong> <span class="text-muted small ms-1">{{ $module->slug }}</span></td>
                                        <td>@if ($module->version) <span class="badge text-bg-info">v{{ $module->version }}</span> @else <span class="text-muted">—</span> @endif</td>
                                        <td><span class="badge text-bg-success">Enabled</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
</x-adminlte.partials.detail-tabs>
    </x-adminlte-card>

    @can('products.delete')
        <x-adminlte.partials.confirm-modal
            id="delete-product-modal"
            title="Delete product"
            :message="'Delete ' . $product->name . '? This permanently removes the product, its pricing ladder, options and add-ons. Products with active or pending orders cannot be deleted.'"
            :action="route('admin.products.destroy', $product)"
            confirm-label="Delete product"
        />
    @endcan
@stop
