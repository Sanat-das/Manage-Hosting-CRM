@extends('adminlte::page')

@section('title', $upgrade->upgrade_no)

@php
    $cycleLabels = [
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'semi_annual' => 'Semi-Annual',
        'annual' => 'Annual',
        'biennial' => 'Biennial',
        'one_time' => 'One Time',
    ];
    $cycleLabel = $cycleLabels[$upgrade->billing_cycle] ?? ucfirst(str_replace('_', ' ', (string) $upgrade->billing_cycle));
@endphp

@section('content_header')
    <x-ui.page-header :title="$upgrade->upgrade_no" subtitle="Upgrade request details" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Upgrade Requests', 'url' => route('admin.upgrade-requests.index')],
        ['label' => $upgrade->upgrade_no, 'active' => true],
    ]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

    {{-- Request header --}}
    <x-adminlte-card>
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center rounded-circle bg-primary text-white"
                 style="width: 56px; height: 56px; font-size: 1.25rem; flex-shrink: 0;">
                <i class="bi bi-arrow-up-right-circle"></i>
            </div>
            <div class="flex-grow-1">
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <h4 class="mb-0">{{ $upgrade->upgrade_no }}</h4>
                    <x-adminlte.partials.status-badge :status="$upgrade->status"
                        :map="['pending' => 'warning', 'applied' => 'success', 'cancelled' => 'secondary']" />
                    <span class="badge text-bg-info">{{ $cycleLabel }}</span>
                    @if ($upgrade->changeTypeBadge())
                        {!! $upgrade->changeTypeBadge() }}
                    @endif
                </div>
                <div class="text-muted mt-1">
                    @if ($upgrade->customer)
                        <a href="{{ route('admin.customers.show', $upgrade->customer) }}">
                            <i class="bi bi-person me-1"></i>{{ $upgrade->customer->full_name }}
                        </a>
                    @endif
                    @if ($upgrade->order)
                        <span class="mx-2">|</span>
                        <a href="{{ route('admin.orders.show', $upgrade->order) }}">
                            <i class="bi bi-cart3 me-1"></i>{{ $upgrade->order->order_no }}
                        </a>
                    @endif
                    @if ($upgrade->invoice)
                        <span class="mx-2">|</span>
                        <a href="{{ route('admin.invoices.show', $upgrade->invoice) }}">
                            <i class="bi bi-receipt me-1"></i>{{ $upgrade->invoice->invoice_no }}
                        </a>
                    @endif
                </div>
            </div>
            @can('product-upgrades.manage')
                @if ($upgrade->status === \App\Models\UpgradeRequest::STATUS_PENDING)
                    <div class="d-flex gap-2">
                        <button type="button" data-bs-toggle="modal" data-bs-target="#approve-upgrade"
                                class="btn btn-sm btn-success">
                            <i class="bi bi-check-lg me-1"></i>Approve
                        </button>
                        <button type="button" data-bs-toggle="modal" data-bs-target="#cancel-upgrade"
                                class="btn btn-sm btn-outline-danger">
                            <i class="bi bi-x-circle me-1"></i>Cancel
                        </button>
                    </div>
                @endif
            @endcan
        </div>
    </x-adminlte-card>

    {{-- Quote snapshot --}}
    <x-adminlte-card>
        <div class="row">
            <div class="col-md-6">
                <table class="table table-sm table-borderless">
                    <tbody>
                        <tr><th class="w-25 text-muted">From</th><td>{{ $upgrade->fromProduct?->name ?? '—' }}</td></tr>
                        <tr><th class="text-muted">To</th><td>{{ $upgrade->toProduct?->name ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Billing cycle</th><td>{{ $cycleLabel }}</td></tr>
                        <tr><th class="text-muted">Credited</th><td><x-adminlte.partials.currency :value="$upgrade->credited" /></td></tr>
                        <tr><th class="text-muted">Debited</th><td><x-adminlte.partials.currency :value="$upgrade->debited" /></td></tr>
                        <tr><th class="text-muted">Setup fee</th><td><x-adminlte.partials.currency :value="$upgrade->setup_fee" /></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm table-borderless">
                    <tbody>
                        <tr>
                            <th class="w-25 text-muted">Payable</th>
                            <td>
                                @if ((float) $upgrade->payable > 0)
                                    <strong><x-adminlte.partials.currency :value="$upgrade->payable" /></strong>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">Credit</th>
                            <td>
                                @if ((float) $upgrade->credit_amount > 0)
                                    <span class="text-success"><x-adminlte.partials.currency :value="$upgrade->credit_amount" /></span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        </tr>
                        <tr><th class="text-muted">Proration</th><td>{{ $upgrade->proration_days }} / {{ $upgrade->period_days }} days</td></tr>
                        <tr><th class="text-muted">Requested</th><td>{{ $upgrade->created_at?->format('M j, Y H:i') }}</td></tr>
                        <tr><th class="text-muted">Approved</th><td>{{ $upgrade->approved_at?->format('M j, Y H:i') ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Applied</th><td>{{ $upgrade->applied_at?->format('M j, Y H:i') ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Cancelled</th><td>{{ $upgrade->cancelled_at?->format('M j, Y H:i') ?? '—' }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        @if ($upgrade->notes)
            <div class="mt-2">
                <strong class="text-muted small d-block">Notes</strong>
                <p class="mb-0" style="white-space: pre-line;">{{ $upgrade->notes }}</p>
            </div>
        @endif
    </x-adminlte-card>

    @can('product-upgrades.manage')
        @if ($upgrade->status === \App\Models\UpgradeRequest::STATUS_PENDING)
            <x-adminlte.partials.confirm-modal
                :id="'approve-upgrade'"
                title="Approve upgrade"
                :message="'Approve ' . $upgrade->upgrade_no . '? ' . ((float) $upgrade->payable > 0 ? 'An invoice will be generated for the payable amount and the request stays pending until it is paid.' : ((float) $upgrade->credit_amount > 0 ? 'The credit will be granted to the customer\'s wallet and the upgrade applied immediately.' : 'The upgrade will be applied immediately.'))"
                method="POST"
                :action="route('admin.upgrade-requests.approve', $upgrade)"
                confirm-label="Approve"
                confirm-theme="success"
            />
            <x-adminlte.partials.confirm-modal
                :id="'cancel-upgrade'"
                title="Cancel upgrade"
                :message="'Cancel ' . $upgrade->upgrade_no . '? Any unpaid invoice for it will be voided.'"
                method="POST"
                :action="route('admin.upgrade-requests.cancel', $upgrade)"
                confirm-label="Cancel upgrade"
            >
                <x-slot name="fields">
                    <div class="mt-3">
                        <label class="form-label small text-muted" for="cancel-upgrade-reason">Reason <span class="fw-normal">(optional)</span></label>
                        <input type="text" class="form-control form-control-sm" id="cancel-upgrade-reason"
                               name="reason" maxlength="500" placeholder="Why is this upgrade being cancelled?">
                    </div>
                </x-slot>
            </x-adminlte.partials.confirm-modal>
        @endif
    @endcan
@stop