@extends('adminlte::page')

@section('title', 'Edit '.$hostingAccount->host_name)

@php
    $activeTab = (string) request()->query('tab', 'details');
    $tabs = [
        ['id' => 'details', 'label' => 'Details', 'icon' => 'bi bi-hdd-stack'],
        ['id' => 'billing', 'label' => 'Billing', 'icon' => 'bi bi-credit-card'],
        ['id' => 'lifecycle', 'label' => 'Lifecycle', 'icon' => 'bi bi-lightning'],
        ['id' => 'ips', 'label' => 'IP Addresses', 'icon' => 'bi bi-hdd-network', 'badge' => $assignedIps->count()],
        ['id' => 'assets', 'label' => 'Inventory Assets', 'icon' => 'bi bi-diagram-3', 'badge' => $assetRelationships->count()],
    ];
@endphp

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Edit {{ $hostingAccount->host_name }}</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.hosting.index') }}">Products/Services</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.hosting.show', $hostingAccount) }}">#{{ $hostingAccount->id }}</a></li>
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

    <x-adminlte-card>
        <x-adminlte.partials.detail-tabs :tabs="$tabs" :active-tab="$activeTab">
            {{-- Details --}}
            <div class="tab-pane fade {{ $activeTab === 'details' ? 'show active' : '' }}" id="details" role="tabpanel" aria-labelledby="details-tab">
                {{-- Status is changed only through the guarded lifecycle actions on
                     the Lifecycle tab (no direct dropdown — it would bypass the
                     audit trail and conflict with those actions). --}}
                <form method="POST" action="{{ route('admin.hosting.update', $hostingAccount) }}">
                    @csrf
                    @method('PUT')

                    <div class="row">
                        <div class="col-md-6">
                            <x-adminlte-select name="customer_id" label="Customer" required>
                                @foreach ($customers as $customer)
                                    <option value="{{ $customer->id }}" @selected(old('customer_id', $hostingAccount->customer_id) == $customer->id)>
                                        {{ $customer->full_name }} — {{ $customer->user?->email ?? $customer->display_id }}
                                    </option>
                                @endforeach
                            </x-adminlte-select>
                        </div>
                        <div class="col-md-6">
                            <x-adminlte-select name="product_id" label="Package (product)" required>
                                @foreach ($products as $product)
                                    <option value="{{ $product->id }}" @selected(old('product_id', $hostingAccount->product_id) == $product->id)>
                                        {{ $product->name }} — {{ number_format($product->price, 2) }}
                                    </option>
                                @endforeach
                            </x-adminlte-select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <x-adminlte-input name="host_name" label="Host name" placeholder="Auto-generated if left blank"
                                              value="{{ old('host_name', $hostingAccount->host_name) }}" />
                        </div>
                        <div class="col-md-6">
                            <x-adminlte-input name="domain" label="Primary domain" placeholder="e.g. example.com"
                                              value="{{ old('domain', $hostingAccount->domain) }}" />
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <x-adminlte-select name="server_id" label="Server (optional)">
                                <option value="">— Unassigned —</option>
                                @foreach ($servers as $server)
                                    <option value="{{ $server->id }}" @selected(old('server_id', $hostingAccount->server_id) == $server->id)>
                                        {{ $server->name }} ({{ $server->ip_address }})
                                    </option>
                                @endforeach
                            </x-adminlte-select>
                        </div>
                    </div>
                    {{-- username / username_prefix are legacy/module-managed — credentials live in modules --}}

                    <x-adminlte-textarea name="notes" label="Admin notes (internal only)" rows="3"
                                         placeholder="Internal notes — never shown to the customer">{{ old('notes', $hostingAccount->notes) }}</x-adminlte-textarea>

                    <div class="d-flex gap-2 mt-2">
                        <a href="{{ route('admin.hosting.show', $hostingAccount) }}" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1" aria-hidden="true"></i> Update Product/Service
                        </button>
                    </div>
                </form>
            </div>

            {{-- Billing --}}
            <div class="tab-pane fade {{ $activeTab === 'billing' ? 'show active' : '' }}" id="billing" role="tabpanel" aria-labelledby="billing-tab">
                <form method="POST" action="{{ route('admin.hosting.update-billing', $hostingAccount) }}">
                    @csrf
                    @method('PUT')

                    <div class="row">
                        <div class="col-md-6">
                            <x-adminlte-select name="billing_cycle" label="Billing cycle">
                                @foreach (\App\Models\Order::BILLING_CYCLES as $cycle)
                                    <option value="{{ $cycle }}" @selected(old('billing_cycle', $order?->billing_cycle ?? $hostingAccount->product?->billing_cycle) === $cycle)>
                                        {{ ucfirst(str_replace('_', ' ', $cycle)) }}
                                    </option>
                                @endforeach
                            </x-adminlte-select>
                        </div>
                        <div class="col-md-6">
                            <x-adminlte-input name="next_due_date" type="date" label="Next due date"
                                              value="{{ old('next_due_date', $hostingAccount->next_due_date?->format('Y-m-d')) }}" />
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <x-adminlte-input name="next_billing_date" type="date" label="Next billing date"
                                              value="{{ old('next_billing_date', $order?->next_billing_date?->format('Y-m-d')) }}" />
                        </div>
                        <div class="col-md-6">
                            <x-adminlte-input name="payment_method" label="Payment method" placeholder="e.g. credit_card, paypal"
                                              value="{{ old('payment_method', $order?->payment_method) }}" />
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <x-adminlte-input name="subscription_id" label="Subscription ID" placeholder="Optional gateway subscription reference"
                                              value="{{ old('subscription_id', $order?->subscription_id) }}" />
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Recurring amount</label>
                                <div class="form-control-plaintext py-2">
                                    {{ $recurringAmount !== null ? '$'.number_format($recurringAmount, 2) : '—' }}
                                </div>
                            </div>
                        </div>
                    </div>

                    @if (! $order)
                        <x-adminlte-alert theme="warning" dismissible>
                            This product/service has no linked order — order-level billing fields apply only once an order exists.
                        </x-adminlte-alert>
                    @endif

                    <div class="d-flex gap-2 mt-2">
                        <a href="{{ route('admin.hosting.show', $hostingAccount) }}" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1" aria-hidden="true"></i> Update Billing Info
                        </button>
                    </div>
                </form>
            </div>

            {{-- Lifecycle --}}
            <div class="tab-pane fade {{ $activeTab === 'lifecycle' ? 'show active' : '' }}" id="lifecycle" role="tabpanel" aria-labelledby="lifecycle-tab">
                @if ($hostingAccount->status === 'terminated')
                    <x-adminlte-alert theme="secondary" dismissible>
                        This account is <strong>terminated</strong>. No lifecycle actions are available.
                    </x-adminlte-alert>
                @else
                    <div class="row g-3">
                        {{-- Activate (pending) --}}
                        @if ($hostingAccount->status === 'pending')
                            <div class="col-md-6">
                                <div class="border rounded p-3 h-100">
                                    <h6 class="text-success"><i class="bi bi-check-circle me-1"></i> Activate account</h6>
                                    <p class="text-muted small">Sets the account to <strong>active</strong>.</p>
                                    <form method="POST" action="{{ route('admin.hosting.unsuspend', $hostingAccount) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-success btn-sm">
                                            <i class="bi bi-check-lg me-1"></i> Activate
                                        </button>
                                    </form>
                                </div>
                            </div>
                        @endif

                        {{-- Suspend (active) --}}
                        @if ($hostingAccount->status === 'active')
                            <div class="col-md-6">
                                <div class="border rounded p-3 h-100">
                                    <h6 class="text-warning"><i class="bi bi-pause-circle me-1"></i> Suspend account</h6>
                                    <form method="POST" action="{{ route('admin.hosting.suspend', $hostingAccount) }}">
                                        @csrf
                                        <div class="mb-2">
                                            <textarea name="reason" class="form-control form-control-sm" rows="2"
                                                      placeholder="Suspension reason (optional)"></textarea>
                                        </div>
                                        <button type="submit" class="btn btn-warning btn-sm">
                                            <i class="bi bi-pause me-1"></i> Suspend
                                        </button>
                                    </form>
                                </div>
                            </div>
                        @endif

                        {{-- Reactivate (suspended) --}}
                        @if ($hostingAccount->status === 'suspended')
                            <div class="col-md-6">
                                <div class="border rounded p-3 h-100">
                                    <h6 class="text-success"><i class="bi bi-play-circle me-1"></i> Reactivate account</h6>
                                    @if ($hostingAccount->suspended_reason)
                                        <p class="text-muted small">Suspension reason: {{ $hostingAccount->suspended_reason }}</p>
                                    @endif
                                    <form method="POST" action="{{ route('admin.hosting.unsuspend', $hostingAccount) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-success btn-sm">
                                            <i class="bi bi-play me-1"></i> Reactivate
                                        </button>
                                    </form>
                                </div>
                            </div>
                        @endif

                        {{-- Change package (active | suspended) --}}
                        @if (in_array($hostingAccount->status, ['active', 'suspended'], true))
                            <div class="col-md-6">
                                <div class="border rounded p-3 h-100">
                                    <h6 class="text-info"><i class="bi bi-box-arrow-up me-1"></i> Change package</h6>
                                    <p class="text-muted small">Current: <strong>{{ $hostingAccount->product?->name ?? 'none' }}</strong></p>
                                    <form method="POST" action="{{ route('admin.hosting.change-package', $hostingAccount) }}">
                                        @csrf
                                        <div class="mb-2">
                                            <select name="product_id" class="form-select form-select-sm" required>
                                                @foreach ($packages as $package)
                                                    <option value="{{ $package->id }}" @selected($package->id === $hostingAccount->product_id)>
                                                        {{ $package->name }} — {{ number_format($package->price, 2) }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <button type="submit" class="btn btn-info btn-sm">
                                            <i class="bi bi-arrow-repeat me-1"></i> Change Package
                                        </button>
                                    </form>
                                </div>
                            </div>
                        @endif

                        {{-- Change password (any non-terminated status) --}}
                        @if ($hostingAccount->status !== 'terminated')
                            <div class="col-md-6">
                                <div class="border rounded p-3 h-100">
                                    <h6 class="text-secondary"><i class="bi bi-key me-1"></i> Change password</h6>
                                    <form method="POST" action="{{ route('admin.hosting.change-password', $hostingAccount) }}">
                                        @csrf
                                        <div class="mb-2">
                                            <input type="password" name="password" class="form-control form-control-sm"
                                                   placeholder="New panel password" minlength="8" required>
                                        </div>
                                        <div class="mb-2">
                                            <input type="password" name="password_confirmation" class="form-control form-control-sm"
                                                   placeholder="Confirm new password" minlength="8" required>
                                        </div>
                                        <button type="submit" class="btn btn-outline-secondary btn-sm">
                                            <i class="bi bi-shield-lock me-1"></i> Change Password
                                        </button>
                                    </form>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            {{-- IP Addresses --}}
            <div class="tab-pane fade {{ $activeTab === 'ips' ? 'show active' : '' }}" id="ips" role="tabpanel" aria-labelledby="ips-tab">
                <div class="row g-3">
                    <div class="col-md-6">
                        @if ($assignedIps->isNotEmpty())
                            <form method="POST" action="{{ route('admin.hosting.release-ip', $hostingAccount) }}">
                                @csrf
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th style="width: 2rem;">
                                                    <input type="checkbox" class="form-check-input" id="select-all-leases" title="Select all" aria-label="Select all leases">
                                                </th>
                                                <th>IP address</th>
                                                <th>Type</th>
                                                <th>Subnet</th>
                                                <th>PTR record</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($assignedIps as $ip)
                                                <tr>
                                                    <td><input type="checkbox" name="ip_address_ids[]" value="{{ $ip->id }}" class="form-check-input lease-check" aria-label="Select {{ $ip->ip_address }}"></td>
                                                    <td><code>{{ $ip->ip_address }}</code></td>
                                                    <td>{{ $ip->type ? ucfirst(str_replace('_', ' ', $ip->type)) : '—' }}</td>
                                                    <td>{{ $ip->subnet?->name ?? '—' }}</td>
                                                    <td class="text-muted">{{ $ip->ptr_record ?? '—' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                <div class="d-flex gap-2 mt-2">
                                    <input type="text" name="reason" class="form-control form-control-sm"
                                           placeholder="Release reason (optional)" maxlength="1000" aria-label="Release reason">
                                    <button type="submit" class="btn btn-outline-danger btn-sm text-nowrap" id="release-selected-btn" disabled>
                                        <i class="bi bi-arrow-counterclockwise me-1"></i> Release selected
                                    </button>
                                </div>
                            </form>
                        @else
                            <p class="text-muted mb-0">No IP assigned</p>
                        @endif
                    </div>
                    <div class="col-md-6">
                        {{-- Pull next N available --}}
                        <form method="POST" action="{{ route('admin.hosting.pull-ip', $hostingAccount) }}" class="mb-3">
                            @csrf
                            <div class="input-group input-group-sm">
                                <input type="number" name="count" min="1" max="10" value="1" class="form-control"
                                       style="max-width: 5rem;" title="Number of IPs to pull" aria-label="Number of IPs to pull">
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="bi bi-lightning me-1"></i> Pull Next Available
                                </button>
                            </div>
                            <div class="form-text small text-muted">Pulls up to the given number of free IPs from the pool.</div>
                        </form>

                        {{-- Assign specific IPs — filter by subnet then search, Pull Next Available kept above --}}
                        <form method="POST" action="{{ route('admin.hosting.assign-ips', $hostingAccount) }}" id="assign-ips-form">
                            @csrf
                            <div class="mb-2">
                                <label class="form-label small text-muted mb-1">Subnet</label>
                                <select id="ip-subnet-filter" class="form-select form-select-sm">
                                    <option value="">All subnets</option>
                                    @foreach ($ipSubnets as $sub)
                                        <option value="{{ $sub->id }}">{{ $sub->subnet_cidr }} — {{ $sub->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="input-group input-group-sm mb-2">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="search" id="ip-search" class="form-control" placeholder="Search in selected subnet — e.g. 10.1.3.133" autocomplete="off">
                                <button type="button" class="btn btn-outline-secondary" id="ip-search-clear" title="Clear">×</button>
                            </div>
                            <div id="selected-ips" class="mb-2 d-none">
                                <div class="small text-muted mb-1">Selected (<span id="selected-count">0</span>):</div>
                                <div id="selected-chips" class="d-flex flex-wrap gap-1 mb-2"></div>
                            </div>
                            <div class="border rounded p-2 mb-2" style="max-height: 260px; overflow-y: auto;" id="available-ip-list">
                                <div class="text-muted small text-center py-3" id="ip-hint">Type at least 2 characters to search the full free pool.<br><span class="small">Try <code>10.1.3.133</code> — results come from all subnets, not just the first 100.</span></div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <button type="submit" class="btn btn-outline-primary btn-sm" id="assign-selected-btn" disabled>
                                    <i class="bi bi-link-45deg me-1"></i> Assign selected
                                </button>
                                <span class="text-muted small" id="ip-visible-count"></span>
                            </div>
                            <div class="form-text small text-muted mt-1">Search hits the full pool (10k+ IPs) — <code>Pull Next Available</code> above still works for auto-assign.</div>
                        </form>
                    </div>
                </div>
            </div>

            {{-- Inventory Assets --}}
            <div class="tab-pane fade {{ $activeTab === 'assets' ? 'show active' : '' }}" id="assets" role="tabpanel" aria-labelledby="assets-tab">
                <div class="border rounded p-3 mb-3">
                    <h6 class="text-primary mb-3"><i class="bi bi-plus-circle me-1"></i> Link an inventory asset</h6>
                    <form method="POST" action="{{ route('admin.asset-relationships.store') }}">
                        @csrf
                        <input type="hidden" name="parent_kind" value="hosting_account">
                        <input type="hidden" name="parent_id" value="{{ $hostingAccount->id }}">
                        <input type="hidden" name="child_kind" value="inventory_asset">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label small text-muted mb-1">Inventory asset</label>
                                <select name="child_id" class="form-select form-select-sm" required>
                                    <option value="">Select asset...</option>
                                    @foreach ($inventoryAssets as $asset)
                                        <option value="{{ $asset->id }}" @selected((int) old('child_id') === $asset->id)>
                                            {{ $asset->asset_tag }}{{ $asset->model ? ' — '.$asset->model : '' }} ({{ ucfirst($asset->asset_type) }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small text-muted mb-1">Relationship</label>
                                <select name="relationship_type" class="form-select form-select-sm" required>
                                    @foreach ($relationshipTypes as $type)
                                        <option value="{{ $type }}" @selected(old('relationship_type', 'hosted_on') === $type)>
                                            {{ ucfirst(str_replace('_', ' ', $type)) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small text-muted mb-1">Label (optional)</label>
                                <input type="text" name="label" maxlength="255" class="form-control form-control-sm"
                                       placeholder="e.g. Primary node" value="{{ old('label') }}">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-sm btn-primary w-100">
                                    <i class="bi bi-link-45deg me-1"></i> Link
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                @if ($assetRelationships->isEmpty())
                    <p class="text-muted mb-0">No inventory assets are linked to this account.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Parent</th>
                                    <th>Relationship</th>
                                    <th>Child</th>
                                    <th>Label</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($assetRelationships as $relationship)
                                    <tr>
                                        <td>
                                            <span class="badge text-bg-secondary">{{ $assetKinds[$relationship->parent_kind] ?? $relationship->parent_kind }}</span>
                                            @if ($relationship->parent_kind === 'inventory_asset')
                                                <a class="ms-1" href="{{ route('admin.inventory-assets.show', $relationship->parent_id) }}">
                                                    {{ $assetNames[$relationship->id]['parent'] ?? 'Asset #'.$relationship->parent_id }}
                                                </a>
                                            @elseif ($assetNames[$relationship->id]['parent'])
                                                <span class="ms-1">{{ $assetNames[$relationship->id]['parent'] }}</span>
                                            @endif
                                            <code class="ms-1">#{{ $relationship->parent_id }}</code>
                                        </td>
                                        <td><code>{{ $relationship->relationship_type }}</code></td>
                                        <td>
                                            <span class="badge text-bg-secondary">{{ $assetKinds[$relationship->child_kind] ?? $relationship->child_kind }}</span>
                                            @if ($relationship->child_kind === 'inventory_asset')
                                                <a class="ms-1" href="{{ route('admin.inventory-assets.show', $relationship->child_id) }}">
                                                    {{ $assetNames[$relationship->id]['child'] ?? 'Asset #'.$relationship->child_id }}
                                                </a>
                                            @elseif ($assetNames[$relationship->id]['child'])
                                                <span class="ms-1">{{ $assetNames[$relationship->id]['child'] }}</span>
                                            @endif
                                            <code class="ms-1">#{{ $relationship->child_id }}</code>
                                        </td>
                                        <td class="text-muted">{{ $relationship->label ?? '—' }}</td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-danger" title="Remove link"
                                                    data-bs-toggle="modal" data-bs-target="#remove-asset-{{ $relationship->id }}">
                                                <i class="bi bi-unlink"></i>
                                            </button>
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

    {{-- Remove-link confirm modals: outside the tab container so they stay
         functional regardless of which pane is active. --}}
    @foreach ($assetRelationships as $relationship)
        <x-adminlte.partials.confirm-modal
            :id="'remove-asset-' . $relationship->id"
            title="Remove asset link"
            :message="'Remove this ' . $relationship->relationship_type . ' link between ' . $relationship->parent_kind . ' #' . $relationship->parent_id . ' and ' . $relationship->child_kind . ' #' . $relationship->child_id . '?'"
            :action="route('admin.asset-relationships.destroy', $relationship)"
            confirm-label="Remove link"
        />
    @endforeach

    @push('js')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                // Full-pool searchable assign — queries the JSON endpoint so 10.1.3.133 (and any IP beyond the first 100) is findable.
                (function () {
                    var search = document.getElementById('ip-search');
                    var subnetFilter = document.getElementById('ip-subnet-filter');
                    var clearBtn = document.getElementById('ip-search-clear');
                    var list = document.getElementById('available-ip-list');
                    var countEl = document.getElementById('ip-visible-count');
                    var selectedWrap = document.getElementById('selected-ips');
                    var chipsEl = document.getElementById('selected-chips');
                    var assignBtn = document.getElementById('assign-selected-btn');
                    var endpoint = @json(route('admin.hosting.available-ips', $hostingAccount));
                    if (!search || !list) return;
                    var selected = new Map(); // id -> {id, ip_address, subnet_cidr, subnet_name}
                    var timer = null;
                    var lastQ = null;

                    function statusBadge(type) {
                        var t = (type || 'available').toLowerCase();
                        var cls = 'bg-success';
                        if (t === 'reserved') cls = 'bg-warning text-dark';
                        else if (t === 'assigned') cls = 'bg-danger';
                        else if (t === 'floating' || t === 'nat') cls = 'bg-info text-dark';
                        else if (t === 'gateway' || t === 'broadcast' || t === 'network') cls = 'bg-secondary';
                        return '<span class="badge ' + cls + ' ms-1" style="font-size:0.65rem">' + (type ? type : 'available') + '</span>';
                    }
                    function updateSelectedUI() {
                        var size = selected.size;
                        document.getElementById('selected-count').textContent = size;
                        selectedWrap.classList.toggle('d-none', size === 0);
                        assignBtn.disabled = size === 0;
                        chipsEl.innerHTML = '';
                        selected.forEach(function (ip) {
                            var chip = document.createElement('div');
                            chip.className = 'badge text-bg-primary d-inline-flex align-items-center gap-1 py-1 px-2';
                            chip.innerHTML = '<code class="text-white">' + ip.ip_address + '</code> ' + statusBadge(ip.type) + '<span class="small ms-1">' + (ip.subnet_cidr || ip.subnet_name || '') + '</span> <button type="button" class="btn-close btn-close-white ms-1" style="font-size:0.6rem" aria-label="Remove"></button><input type="hidden" name="ip_address_ids[]" value="' + ip.id + '">';
                            chip.querySelector('button').addEventListener('click', function () { selected.delete(String(ip.id)); updateSelectedUI(); renderLastResults(); });
                            chipsEl.appendChild(chip);
                        });
                    }

                    var lastResults = [];
                    function renderLastResults() {
                        // re-render with current selected state without re-fetching
                        if (lastResults.length) render(lastResults, lastQ);
                    }

                    function render(ips, q) {
                        lastResults = ips; lastQ = q;
                        list.innerHTML = '';
                        if (!ips.length) {
                            list.innerHTML = '<div class="text-muted small text-center py-3">No free IPs match <code>' + (q ? q.replace(/</g,'&lt;') : '') + '</code>.</div>';
                            if (countEl) countEl.textContent = '0 results';
                            return;
                        }
                        ips.forEach(function (ip) {
                            var isSel = selected.has(String(ip.id));
                            var row = document.createElement('div');
                            row.className = 'form-check d-flex align-items-center gap-2';
                            var label = ip.subnet_cidr || ip.subnet_name || 'no subnet';
                            if (ip.subnet_cidr && ip.subnet_name) label = ip.subnet_cidr + ' (' + ip.subnet_name + ')';
                            var vBadge = ip.ip_version ? '<span class="badge text-bg-light border ms-1" style="font-size:0.65rem">IPv' + ip.ip_version + '</span>' : '';
                            row.innerHTML = '<input class="form-check-input" type="checkbox" value="' + ip.id + '" id="avail-ip-' + ip.id + '"' + (isSel ? ' checked' : '') + '><label class="form-check-label small w-100 d-flex flex-wrap align-items-center gap-1" for="avail-ip-' + ip.id + '"><code>' + ip.ip_address + '</code>' + vBadge + statusBadge(ip.type) + ' <span class="text-muted">— ' + label + (ip.ptr_record ? ' · ' + ip.ptr_record : '') + '</span></label>';
                            var cb = row.querySelector('input');
                            cb.addEventListener('change', function () {
                                if (cb.checked) selected.set(String(ip.id), ip);
                                else selected.delete(String(ip.id));
                                updateSelectedUI();
                            });
                            list.appendChild(row);
                        });
                        if (countEl) countEl.textContent = ips.length + ' results' + (ips.length >= 100 ? ' — narrow search for more' : '');
                    }

                    function fetchResults(q) {
                        var subnetId = subnetFilter ? subnetFilter.value : '';
                        if (q !== null && q.length > 0 && q.length < 2 && !subnetId) {
                            list.innerHTML = '<div class="text-muted small text-center py-3">Select a subnet or type at least 2 characters…</div>';
                            if (countEl) countEl.textContent = '';
                            return;
                        }
                        list.innerHTML = '<div class="text-muted small text-center py-3"><span class="spinner-border spinner-border-sm me-1"></span> Searching…</div>';
                        var url = endpoint + '?q=' + encodeURIComponent(q || '') + '&limit=100';
                        if (subnetId) url += '&subnet_id=' + encodeURIComponent(subnetId);
                        fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                            .then(function (r) { if (!r.ok) throw new Error('search failed'); return r.json(); })
                            .then(function (ips) { render(ips, q); })
                            .catch(function () { list.innerHTML = '<div class="text-danger small text-center py-3">Search failed — try again.</div>'; });
                    }

                    search.addEventListener('input', function () {
                        clearTimeout(timer);
                        var q = search.value.trim();
                        timer = setTimeout(function () { fetchResults(q); }, 300);
                    });
                    if (subnetFilter) subnetFilter.addEventListener('change', function () { clearTimeout(timer); fetchResults(search.value.trim()); });
                    if (clearBtn) clearBtn.addEventListener('click', function () { search.value = ''; if (subnetFilter) subnetFilter.value = ''; selected.clear(); updateSelectedUI(); list.innerHTML = '<div class="text-muted small text-center py-3" id="ip-hint">Select a subnet above, then type to search — e.g. pick <code>10.1.3.0/24</code> and search <code>133</code> to find <code>10.1.3.133</code>.</div>'; if (countEl) countEl.textContent = ''; search.focus(); });
                    // initial load: respect subnet filter (all) — show first batch
                    fetchResults(search.value.trim());
                })();

                // Select-all for the release-leases checkboxes on the IP tab.
                var selectAll = document.getElementById('select-all-leases');
                var checkboxes = document.querySelectorAll('.lease-check');
                var releaseBtn = document.getElementById('release-selected-btn');
                if (selectAll && releaseBtn && checkboxes.length > 0) {
                    function update() {
                        var any = Array.prototype.some.call(checkboxes, function (cb) { return cb.checked; });
                        releaseBtn.disabled = !any;
                        selectAll.checked = Array.prototype.every.call(checkboxes, function (cb) { return cb.checked; });
                    }
                    checkboxes.forEach(function (cb) { cb.addEventListener('change', update); });
                    selectAll.addEventListener('change', function () {
                        checkboxes.forEach(function (cb) { cb.checked = selectAll.checked; });
                        update();
                    });
                    update();
                }

                // Keep the active pane open across the back() redirects the
                // lifecycle / IP / asset / billing actions return with. Skipped
                // when the Details form failed validation — its error fields
                // live on the Details pane (and we let the Billing tab's own
                // validation errors surface on the Billing pane instead).
                @if ($errors->hasAny(['customer_id', 'product_id', 'server_id', 'domain', 'host_name', 'notes']))
                var detailsTab = document.querySelector('[data-bs-target="#details"]');
                if (detailsTab) detailsTab.click();
                @else
                var stored = null;
                try { stored = sessionStorage.getItem('hosting-edit-active-tab'); } catch (e) {}
                if (stored) {
                    var restoreTab = document.querySelector('[data-bs-target="#' + stored + '"]');
                    if (restoreTab) restoreTab.click();
                }
                @endif

                document.querySelectorAll('[data-bs-toggle="tab"]').forEach(function (tab) {
                    tab.addEventListener('shown.bs.tab', function () {
                        var id = (tab.getAttribute('data-bs-target') || '').replace('#', '');
                        if (id) {
                            try { sessionStorage.setItem('hosting-edit-active-tab', id); } catch (e) {}
                        }
                    });
                });
            });
        </script>
    @endpush
@stop
