@extends('adminlte::page')

@section('title', 'Product — '.$product->name)

@php
    $cycleLabels = \App\Models\Product::BILLING_CYCLES;
    // The 'free' cycle is a payment-type marker row; a paid product may
    // carry a stale 0.00 row from before savePricing stopped writing it —
    // never show it unless the product is actually free.
    $pricingRows = ($product->payment_type ?? 'recurring') === 'free'
        ? $product->pricing
        : $product->pricing->reject(fn ($row) => $row->billing_cycle === 'free');
    $activeTab = (string) request()->query('tab', 'overview');
    // The Modules tab lists plugin modules only (the provisioning builtins are
    // configured on the Details tab), so count only the links it displays.
    $enabledModuleCount = collect($linkableModules ?? [])->filter(
        fn ($mod) => (bool) ($product->moduleLinks->firstWhere('module_slug', $mod['slug'])?->enabled)
    )->count();

    $tabs = [
        ['id' => 'overview', 'label' => 'Overview', 'icon' => 'bi bi-info-circle'],
        ['id' => 'pricing', 'label' => 'Pricing', 'icon' => 'bi bi-currency-rupee', 'badge' => $pricingRows->count()],
        ['id' => 'options', 'label' => 'Options', 'icon' => 'bi bi-sliders', 'badge' => $product->options->count()],
        ['id' => 'addons', 'label' => 'Add-ons', 'icon' => 'bi bi-plus-square', 'badge' => $product->addons->count()],
        ['id' => 'modules', 'label' => 'Modules', 'icon' => 'bi bi-puzzle', 'badge' => $enabledModuleCount],
    ];
@endphp

@section('content_header')
    <x-ui.page-header title="{{ $product->name }}" subtitle="View product details" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')], ['label' => 'Products', 'url' => route('admin.products.index')], ['label' => $product->name, 'active' => true]]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

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
        ['title' => $pricingRows->count(), 'text' => 'Price Points', 'icon' => 'bi bi-currency-rupee', 'theme' => 'primary'],
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
                                @php
                                    $provSelection = $product->provisioningSelection();
                                    $provParts = explode('|', $provSelection, 2);
                                    $provSlug = $provParts[0];
                                    $provMode = $provParts[1] ?? null;
                                    $provName = in_array($provSlug, ['manual', 'custom'], true)
                                        ? ucfirst($provSlug)
                                        : app(\App\Services\Integrations\IntegrationRegistry::class)->nameFor($provSlug);
                                @endphp
                                <tr>
                                    <th class="text-muted">Provisioning</th>
                                    <td>
                                        {{ $provName }}
                                        @if($provMode !== null)
                                            <span class="badge text-bg-{{ $provMode === 'manual' ? 'warning' : 'success' }} ms-1">{{ ucfirst($provMode) }}</span>
                                        @endif
                                    </td>
                                </tr>
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
                            @forelse ($pricingRows->sortBy(fn ($row) => array_search($row->billing_cycle, array_keys($cycleLabels), true) ?: 99) as $row)
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
                                <x-ui.empty-table-row colSpan="5" icon="bi bi-currency-rupee" title="No pricing configured." />
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
                            @php
                                $fixed = \App\Services\OptionPricingResolver::fixedDisplay($link);
                            @endphp
                            <p class="text-muted small mb-0">{{ $fixed !== null && ! is_array($fixed) ? 'Fixed value: '.$fixed : 'No values defined.' }}</p>
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
                <x-ui.empty-table-row colSpan="5" icon="bi bi-plus-square" title="No add-ons attached." />
            @endforelse
        </tbody>
    </table>
                </div>
            </div>

            {{-- Modules — plugin modules only (the provisioning builtins are
                 configured on the Details tab): per-product enable + per-link
                 Auto/Manual mode --}}
            <div class="tab-pane fade {{ $activeTab === 'modules' ? 'show active' : '' }}" id="modules" role="tabpanel" aria-labelledby="modules-tab">
                @php $linkableModules = $linkableModules ?? []; @endphp
                @if (empty($linkableModules))
                    <p class="text-muted mb-0">No modules available. Activate modules in System → Modules first.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Module</th>
                                    <th>Group</th>
                                    <th>Status</th>
                                    <th>Provisioning</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($linkableModules as $mod)
                                    @php
                                        $slug = $mod['slug'];
                                        $link = $product->moduleLinks->firstWhere('module_slug', $slug);
                                        $isEnabled = $link !== null && (bool) $link->enabled;
                                        $mode = $link?->provisioning_mode ?? 'auto';
                                        $isBuiltin = (bool) ($mod['builtin'] ?? false);
                                    @endphp
                                    <tr>
                                        <td><strong>{{ $mod['name'] }}</strong> <span class="text-muted small ms-1">{{ $slug }}</span> @if($isBuiltin)<span class="badge text-bg-light border ms-1">builtin</span>@endif</td>
                                        <td><span class="badge text-bg-secondary">{{ $mod['group'] }}</span></td>
                                        <td>
                                            @if ($isEnabled)
                                                <span class="badge text-bg-success">Enabled</span>
                                            @else
                                                <span class="badge text-bg-secondary">Disabled</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($isEnabled)
                                                <form method="POST" action="{{ route('admin.products.modules.mode', [$product, $slug]) }}" class="d-inline-flex align-items-center gap-2">
                                                    @csrf
                                                    @method('PUT')
                                                    <select name="provisioning_mode" class="form-select form-select-sm" style="width: auto;" aria-label="Provisioning mode" onchange="this.form.submit()" @cannot('products.edit') disabled @endcannot>
                                                        <option value="auto" {{ $mode === 'auto' ? 'selected' : '' }}>Auto</option>
                                                        <option value="manual" {{ $mode === 'manual' ? 'selected' : '' }}>Manual</option>
                                                    </select>
                                                </form>
                                                <div class="text-muted small mt-1">{{ $mode === 'auto' ? 'Provisions without operator action.' : 'Operator creates via hosting actions.' }}</div>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            @can('products.edit')
                                                <form method="POST" action="{{ route('admin.products.modules.toggle', [$product, $slug]) }}" class="d-inline">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm {{ $isEnabled ? 'btn-outline-danger' : 'btn-outline-success' }}">
                                                        {{ $isEnabled ? 'Disable' : 'Enable' }}
                                                    </button>
                                                </form>
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
</x-adminlte.partials.detail-tabs>
    </x-adminlte-card>

    @include('admin.chat.partials.entity-timeline', ['entity' => $product])

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
