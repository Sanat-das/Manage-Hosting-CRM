@extends('adminlte::page')

@section('title', $order->order_no)

@section('content_header')
    <x-ui.page-header title="{{ $order->order_no }}" subtitle="View order details and history" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Orders', 'url' => route('admin.orders.index')],
        ['label' => $order->order_no, 'active' => true],
    ]" />
@stop

@php
    /**
     * How each status transition is presented. These are no longer bare status
     * flips — suspending or terminating an order calls the product's
     * provisioning module and really does stop serving the customer — so a
     * destructive target must not render as a green "confirm" button, and the
     * confirmation must say what it is about to do.
     */
    $transitionMeta = static function (string $target): array {
        return match ($target) {
            \App\Models\Order::STATUS_SUSPENDED => [
                'button' => 'btn-outline-warning',
                'icon' => 'bi-pause-circle',
                'theme' => 'warning',
                'note' => 'The service will be suspended on the control panel. You can reactivate it later.',
            ],
            \App\Models\Order::STATUS_CANCELLED => [
                'button' => 'btn-outline-danger',
                'icon' => 'bi-x-circle',
                'theme' => 'danger',
                'note' => 'This cannot be undone. Any provisioned service is terminated on the control panel, and unpaid invoices for this order are voided.',
            ],
            \App\Models\Order::STATUS_TERMINATED => [
                'button' => 'btn-outline-danger',
                'icon' => 'bi-trash3',
                'theme' => 'danger',
                'note' => 'This cannot be undone. The account is destroyed on the control panel, and unpaid invoices for this order are voided.',
            ],
            default => [
                'button' => 'btn-success',
                'icon' => 'bi-check-lg',
                'theme' => 'success',
                'note' => null,
            ],
        };
    };

    $activeTab = (string) request()->query('tab', 'order-info');
    $tabs = [
        ['id' => 'order-info', 'label' => 'Order Info', 'icon' => 'bi bi-receipt'],
        ['id' => 'items', 'label' => 'Items', 'icon' => 'bi bi-box-seam', 'badge' => $order->items->count()],
        ['id' => 'addons', 'label' => 'Add-ons', 'icon' => 'bi bi-puzzle', 'badge' => $order->items->whereNotNull('product_addon_id')->count()],
        ['id' => 'status-history', 'label' => 'Status History', 'icon' => 'bi bi-clock-history', 'badge' => $statusHistory->count()],
    ];

    $billingCycleLabels = [
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'semi_annual' => 'Semi-Annual',
        'annual' => 'Annual',
        'biennial' => 'Biennial',
        'one_time' => 'One Time',
    ];
    $cycleLabel = $billingCycleLabels[$order->billing_cycle] ?? ucfirst(str_replace('_', ' ', (string) $order->billing_cycle));

    // Add-on rows live on the order's items. A recurring row with no
    // next_billing_date has been cancelled at period end; one_time rows (a
    // setup fee, a one-time add-on) are unscheduled by design and have no
    // schedule to cancel.
    $addonRows = $order->items->whereNotNull('product_addon_id');
    $liveAddons = $addonRows->filter(fn (\App\Models\OrderItem $item) => $item->next_billing_date !== null);

    $paymentMethodLabels = [
        'bank_transfer' => 'Bank Transfer',
        'razorpay' => 'Razorpay',
        'stripe' => 'Stripe',
        'paypal' => 'PayPal',
        'wallet' => 'Wallet',
        'manual' => 'Manual',
    ];
@endphp

@section('content')
    <x-adminlte.partials.flash-alert />

    {{-- Order header --}}
    <x-adminlte-card>
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center rounded-circle bg-primary text-white"
                 style="width: 56px; height: 56px; font-size: 1.25rem; flex-shrink: 0;">
                <i class="bi bi-cart3"></i>
            </div>
            <div class="flex-grow-1">
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <h4 class="mb-0">{{ $order->order_no }}</h4>
                    <x-adminlte.partials.status-badge :status="$order->status" />
                    @if ($order->billing_cycle)
                        <span class="badge text-bg-info">{{ $cycleLabel }}</span>
                    @endif
                </div>
                <div class="text-muted mt-1">
                    @if ($order->customer)
                        <a href="{{ route('admin.customers.show', $order->customer) }}">
                            <i class="bi bi-person me-1"></i>{{ $order->customer->full_name }}
                        </a>
                        @if ($order->customer->user?->email)
                            <span class="mx-2">|</span>{{ $order->customer->user->email }}
                        @endif
                    @endif
                    <span class="mx-2">|</span>
                    <i class="bi bi-calendar3 me-1"></i>Ordered {{ $order->created_at?->format('M j, Y H:i') }}
                </div>
            </div>
            @can('orders.edit')
                @if ($allowedTransitions)
                    <div class="d-flex gap-2">
                        @foreach ($allowedTransitions as $target => $label)
                                @php $meta = $transitionMeta($target); @endphp
                                <button type="button"
                                        data-bs-toggle="modal" data-bs-target="#order-status-{{ $target }}"
                                        class="btn btn-sm {{ $meta['button'] }}">
                                    <i class="bi {{ $meta['icon'] }} me-1"></i>
                                    {{ $label }}
                                </button>
                        @endforeach
                    </div>
                @endif
            @endcan
        </div>
    </x-adminlte-card>

    {{-- Tabbed detail --}}
    <x-adminlte-card>
        <x-adminlte.partials.detail-tabs :tabs="$tabs" :active-tab="$activeTab">
            {{-- Order Info --}}
            <div class="tab-pane fade {{ $activeTab === 'order-info' ? 'show active' : '' }}" id="order-info"
                 role="tabpanel" aria-labelledby="order-info-tab">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tbody>
                                <tr><th class="w-25 text-muted">Order number</th><td>{{ $order->order_no }}</td></tr>
                                <tr><th class="text-muted">Customer</th><td>{{ $order->customer?->full_name ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Product</th><td>{{ $order->product?->name ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Billing cycle</th><td>{{ $cycleLabel }}</td></tr>
                                <tr><th class="text-muted">Quantity</th><td>{{ $order->quantity }}</td></tr>
                                <tr><th class="text-muted">Total</th><td class="fw-bold">₹{{ number_format((float) $order->total, 2) }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tbody>
                                <tr>
                                    <th class="w-25 text-muted">Status</th>
                                    <td><x-adminlte.partials.status-badge :status="$order->status" /></td>
                                </tr>
                                <tr><th class="text-muted">Domain</th><td>{{ $order->domain_name ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Payment method</th><td>{{ $order->payment_method ? ($paymentMethodLabels[$order->payment_method] ?? ucfirst(str_replace('_', ' ', $order->payment_method))) : '—' }}</td></tr>
                                <tr><th class="text-muted">Next billing</th><td>{{ $order->next_billing_date?->format('M j, Y') ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Last billing</th><td>{{ $order->last_billing_date?->format('M j, Y') ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Created</th><td>{{ $order->created_at?->format('M j, Y H:i') }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                @if ($order->notes)
                    <div class="mt-2">
                        <strong class="text-muted small d-block">Notes</strong>
                        <p class="mb-0">{{ $order->notes }}</p>
                    </div>
                @endif

                {{-- Related records (controller eager-loads invoices/hostingAccount/domain) --}}
                <div class="row mt-3">
                    <div class="col-md-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="text-muted text-uppercase small mb-0"><i class="bi bi-receipt me-1"></i> Invoices</h6>
                            @can('invoices.create')
                                @if (! in_array($order->status, [\App\Models\Order::STATUS_CANCELLED, \App\Models\Order::STATUS_TERMINATED], true))
                                    <form method="POST" action="{{ route('admin.orders.generate-invoice', $order) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg me-1"></i> Generate Invoice</button>
                                    </form>
                                @endif
                            @endcan
                        </div>
                        @forelse ($order->invoices as $invoice)
                            <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                                <div>
                                    <a href="{{ route('admin.invoices.show', $invoice) }}" class="text-decoration-none">
                                        <strong>{{ $invoice->invoice_no }}</strong>
                                    </a>
                                    <div class="text-muted small">{{ $invoice->due_date?->format('M j, Y') ?? '—' }}</div>
                                </div>
                                <div class="text-end">
                                    <div>₹{{ number_format((float) $invoice->total, 2) }}</div>
                                    <x-adminlte.partials.status-badge :status="$invoice->status" />
                                </div>
                            </div>
                        @empty
                            <p class="text-muted small mb-0">No invoices yet.</p>
                        @endforelse
                    </div>
                    <div class="col-md-4">
                        <h6 class="text-muted text-uppercase small mb-2"><i class="bi bi-hdd-stack me-1"></i> Hosting account</h6>
                        @if ($order->hostingAccount)
                            <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                                <div>
                                    <a href="{{ route('admin.hosting.show', $order->hostingAccount) }}" class="text-decoration-none">
                                        <strong>{{ $order->hostingAccount->username }}</strong>
                                    </a>
                                    @if ($order->hostingAccount->domain)
                                        <div class="text-muted small">{{ $order->hostingAccount->domain }}</div>
                                    @endif
                                </div>
                                <x-adminlte.partials.status-badge :status="$order->hostingAccount->status" />
                            </div>
                        @else
                            <p class="text-muted small mb-0">No hosting account yet.</p>
                        @endif
                    </div>
                    <div class="col-md-4">
                        <h6 class="text-muted text-uppercase small mb-2"><i class="bi bi-globe2 me-1"></i> Domain</h6>
                        @if ($order->domain)
                            <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                                <div>
                                    <strong>{{ $order->domain->name }}</strong>
                                    <div class="text-muted small">{{ ucfirst($order->domain->type) }}</div>
                                </div>
                                <x-adminlte.partials.status-badge :status="$order->domain->status" />
                            </div>
                        @else
                            <p class="text-muted small mb-0">No domain yet.</p>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Items --}}
            <div class="tab-pane fade {{ $activeTab === 'items' ? 'show active' : '' }}" id="items"
                 role="tabpanel" aria-labelledby="items-tab">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Cycle</th>
                                <th>Domain</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Unit price</th>
                                <th class="text-end">Total</th>
                                <th>Next billing</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($order->items as $item)
                                <tr>
                                    <td>
                                        {{ $item->product_name }}
                                        @if ($item->product)
                                            <div class="text-muted small">{{ $item->product->name }}</div>
                                        @endif
                                        {{-- The line's configuration, through the shared
                                             renderer the storefront, invoices and the
                                             service page use — so units and applied
                                             prices read identically everywhere. The
                                             hand-rolled mapping this replaces printed a
                                             bare "RAM:" for any option with no value. --}}
                                        <div class="text-muted small mt-1">
                                            @include('partials._selected_options', [
                                                'entries' => $item->optionSnapshot()['options'] ?? [],
                                                'modifiersByLink' => [],
                                                'cycle' => $item->optionSnapshot()['billing_cycle'] ?? $item->billing_cycle ?? $order->billing_cycle,
                                                'includeUnselected' => false,
                                            ])
                                        </div>
                                    </td>
                                    <td>{{ $billingCycleLabels[$item->billing_cycle ?? $order->billing_cycle] ?? ucfirst(str_replace('_', ' ', (string) ($item->billing_cycle ?? $order->billing_cycle))) }}</td>
                                    <td>{{ $item->domain_name ?? '—' }}</td>
                                    <td class="text-end">{{ $item->quantity }}</td>
                                    <td class="text-end">₹{{ number_format((float) $item->unit_price, 2) }}</td>
                                    <td class="text-end fw-bold">₹{{ number_format((float) $item->total, 2) }}</td>
                                    <td>{{ $item->next_billing_date?->format('M j, Y') ?? '—' }}</td>
                                </tr>
                            @empty
                                <x-ui.empty-table-row colSpan="7" icon="bi bi-box-seam" title="No items on this order." />
                            @endforelse
                        </tbody>
                        @if ($order->items->isNotEmpty())
                            <tfoot>
                                <tr>
                                    <th colspan="3" class="text-end">Total</th>
                                    <th class="text-end">₹{{ number_format((float) $order->total, 2) }}</th>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>

            {{-- Add-ons (live add-on rows + post-signup attach) --}}
            <div class="tab-pane fade {{ $activeTab === 'addons' ? 'show active' : '' }}" id="addons"
                 role="tabpanel" aria-labelledby="addons-tab">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Add-on</th>
                                <th>Cycle</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Unit price</th>
                                <th>Next billing</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($addonRows as $item)
                                <tr>
                                    <td>{{ $item->product_name }}</td>
                                    <td>{{ $billingCycleLabels[$item->billing_cycle] ?? ucfirst(str_replace('_', ' ', (string) $item->billing_cycle)) }}</td>
                                    <td class="text-end">{{ $item->quantity }}</td>
                                    <td class="text-end">₹{{ number_format((float) $item->unit_price, 2) }}</td>
                                    <td>
                                        @if ($item->next_billing_date)
                                            {{ $item->next_billing_date->format('M j, Y') }}
                                        @elseif ((\App\Models\Order::CYCLE_MONTHS[$item->billing_cycle] ?? 0) > 0)
                                            <x-adminlte.partials.status-badge status="cancelled" />
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if ($item->next_billing_date)
                                            @can('orders.edit')
                                                <button type="button" class="btn btn-sm btn-outline-danger"
                                                        data-bs-toggle="modal" data-bs-target="#cancel-addon-{{ $item->id }}">
                                                    <i class="bi bi-x-circle me-1"></i>Cancel
                                                </button>
                                            @endcan
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <x-ui.empty-table-row colSpan="6" icon="bi bi-puzzle" title="No add-ons on this order." />
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Attach: live orders only — AddOnService enforces the same
                     rule server-side, this keeps the form honest. --}}
                @if (in_array($order->status, [\App\Models\Order::STATUS_ACTIVE, \App\Models\Order::STATUS_SUSPENDED], true))
                    @can('orders.edit')
                        <div class="border-top mt-3 pt-3">
                            <h6 class="text-muted text-uppercase small mb-2"><i class="bi bi-plus-circle me-1"></i> Attach Add-on</h6>
                            @if ($applicableAddons->isEmpty())
                                <p class="text-muted small mb-0">No active add-ons are available for this product.</p>
                            @else
                                <form method="POST" action="{{ route('admin.orders.addons.store', $order) }}" class="row g-2">
                                    @csrf
                                    <div class="col-md-6">
                                        <label class="form-label small text-muted" for="addon-attach-id">Add-on</label>
                                        <select name="addon_id" id="addon-attach-id"
                                                class="form-select form-select-sm @error('addon_id') is-invalid @enderror" required>
                                            <option value="">Select an add-on…</option>
                                            @foreach ($applicableAddons as $addon)
                                                <option value="{{ $addon->id }}" @selected(old('addon_id') == $addon->id)>
                                                    {{ $addon->name }} — ₹{{ number_format((float) $addon->price, 2) }} / {{ $billingCycleLabels[$addon->billing_cycle] ?? ucfirst(str_replace('_', ' ', (string) $addon->billing_cycle)) }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @error('addon_id')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small text-muted" for="addon-attach-quantity">Quantity</label>
                                        <input type="number" name="quantity" id="addon-attach-quantity"
                                               class="form-control form-control-sm @error('quantity') is-invalid @enderror"
                                               value="{{ old('quantity', 1) }}" min="1" max="{{ \App\Models\Order::MAX_QUANTITY }}" required>
                                        @error('quantity')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-sm btn-primary">
                                            <i class="bi bi-plus-lg me-1"></i>Attach Add-on
                                        </button>
                                    </div>
                                </form>
                            @endif
                        </div>
                    @endcan
                @endif
            </div>

            {{-- Status History (order.* activity trail) --}}
            <div class="tab-pane fade {{ $activeTab === 'status-history' ? 'show active' : '' }}" id="status-history"
                 role="tabpanel" aria-labelledby="status-history-tab">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Action</th>
                                <th>Description</th>
                                <th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($statusHistory as $entry)
                                <tr>
                                    <td class="text-muted" style="white-space: nowrap;">{{ $entry->created_at?->format('M j, Y H:i') }}</td>
                                    <td><span class="badge text-bg-info">{{ $entry->from_status ?? '—' }} → {{ $entry->to_status }}</span></td>
                                    <td>{{ $entry->notes ?? '—' }}</td>
                                    <td class="text-muted">{{ $entry->user?->full_name ?? 'System' }}</td>
                                </tr>
                            @empty
                                <x-ui.empty-table-row colSpan="4" icon="bi bi-clock-history" title="No status history recorded." />
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </x-adminlte.partials.detail-tabs>
    </x-adminlte-card>

    @can('orders.edit')
        {{-- Per-row cancel confirmations for live add-on rows. Rendered
             outside the table, per the confirm-modal component contract; the
             optional reason is submitted with the DELETE form. --}}
        @foreach ($liveAddons as $item)
            <x-adminlte.partials.confirm-modal
                :id="'cancel-addon-' . $item->id"
                title="Cancel add-on"
                :message="'Cancel ' . $item->product_name . ' on ' . $order->order_no . '? It stops renewing at the end of the current period — the current period is neither refunded nor voided.'"
                method="DELETE"
                :action="route('admin.orders.addons.destroy', [$order, $item])"
                confirm-label="Cancel add-on"
            >
                <x-slot name="fields">
                    <div class="mt-3">
                        <label class="form-label small text-muted" for="cancel-addon-reason-{{ $item->id }}">Reason <span class="fw-normal">(optional)</span></label>
                        <input type="text" class="form-control form-control-sm" id="cancel-addon-reason-{{ $item->id }}"
                               name="reason" maxlength="500" placeholder="Why is this add-on being cancelled?">
                    </div>
                </x-slot>
            </x-adminlte.partials.confirm-modal>
        @endforeach

        @foreach ($allowedTransitions as $target => $label)
            @php $meta = $transitionMeta($target); @endphp
            <x-adminlte.partials.confirm-modal
                :id="'order-status-' . $target"
                :title="$label . ' order'"
                :message="$label . ' order ' . $order->order_no . '?' . ($meta['note'] ? ' ' . $meta['note'] : '')"
                method="PUT"
                :action="route('admin.orders.status', $order)"
                :confirm-label="$label"
                :confirm-theme="$meta['theme']"
            >
                <x-slot name="fields">
                    <input type="hidden" name="status" value="{{ $target }}">
                </x-slot>
            </x-adminlte.partials.confirm-modal>
        @endforeach
    @endcan
@stop
