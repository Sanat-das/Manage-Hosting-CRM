@extends('adminlte::page')

@section('title', 'Upgrade / Downgrade')

@php
    $cycleLabels = [
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'semi_annual' => 'Semi-Annual',
        'annual' => 'Annual',
        'biennial' => 'Biennial',
        'one_time' => 'One Time',
    ];
    $cycleLabel = $cycleLabels[$order->billing_cycle] ?? ucfirst(str_replace('_', ' ', (string) $order->billing_cycle));
@endphp

@section('content_header')
    <x-ui.page-header title="Upgrade / Downgrade" :subtitle="'Order '.$order->order_no" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Orders', 'url' => route('admin.orders.index')],
        ['label' => $order->order_no, 'url' => route('admin.orders.show', $order)],
        ['label' => 'Upgrade / Downgrade', 'active' => true],
    ]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

    @include('client.upgrades._steps', ['step' => 1, 'order' => $order, 'backRoute' => route('admin.orders.upgrade', $order)])

    {{-- Order summary --}}
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
                    <span class="badge text-bg-info">{{ $cycleLabel }}</span>
                </div>
                <div class="text-muted mt-1">
                    @if ($order->customer)
                        <a href="{{ route('admin.customers.show', $order->customer) }}">
                            <i class="bi bi-person me-1"></i>{{ $order->customer->full_name }}
                        </a>
                    @endif
                    <span class="mx-2">|</span>
                    <strong>{{ $order->product?->name ?? '—' }}</strong>
                    @if ($order->next_billing_date)
                        <span class="mx-2">|</span>
                        <i class="bi bi-calendar3 me-1"></i>Next billing {{ $order->next_billing_date->format('M j, Y') }}
                    @endif
                </div>
            </div>
            <div class="text-end">
                <div class="text-muted small">Current charge</div>
                <div class="fw-bold" style="font-size: 1.1rem;"><x-adminlte.partials.currency :value="$order->total" /></div>
            </div>
        </div>
    </x-adminlte-card>

    @if (empty($groups))
        <x-adminlte-card>
            <div class="text-center py-4">
                <i class="bi bi-arrow-up-right-circle display-4 text-muted"></i>
                <h5 class="mt-3 mb-2">No upgrade targets available</h5>
                <p class="text-muted mb-0">
                    @if ($order->status !== \App\Models\Order::STATUS_ACTIVE)
                        Only active services can be upgraded.
                    @elseif ((\App\Models\Order::CYCLE_MONTHS[$order->billing_cycle] ?? 0) <= 0)
                        Upgrades require a recurring billing cycle.
                    @elseif (! $upgradesEnabled)
                        Upgrades are disabled in settings.
                    @else
                        No enabled upgrade paths from {{ $order->product?->name ?? 'this product' }}.
                    @endif
                </p>
            </div>
        </x-adminlte-card>
    @else
        @if (! $downgradesEnabled)
            <div class="alert alert-info alert-dismissible fade show mb-3" role="alert"
                 style="border-radius: var(--radius-md); box-shadow: var(--shadow-sm);">
                <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                Downgrades are disabled in settings — only upgrades are offered.
            </div>
        @endif

        <form method="POST" action="{{ route('admin.orders.upgrade.configure', $order) }}">
            @csrf
            <x-adminlte-card title="Choose a target product" icon="bi bi-arrow-up-right-circle">
                <div class="row g-2">
                    @foreach ($groups as $group)
                        @php $groupProduct = $group['product']; @endphp
                        @foreach ($group['pairs'] as $pairIndex => $pair)
                            @php
                                $quote = $pair['quote'];
                                $cycle = (string) $quote['to']['billing_cycle'];
                                $pairKey = $groupProduct->id.'-'.$cycle;
                                $changeType = ucfirst($quote['change_type']);
                                $changeBadge = $quote['change_type'] === 'upgrade' ? 'text-bg-success' : ($quote['change_type'] === 'downgrade' ? 'text-bg-warning' : 'text-bg-secondary');
                                $firstPair ??= true;
                            @endphp
                            <div class="col-md-6">
                                <div class="border rounded p-3 h-100">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="pair"
                                               value="{{ $groupProduct->id }}:{{ $cycle }}"
                                               id="pair-{{ $pairKey }}" @checked($firstPair) required>
                                        <label class="form-check-label fw-medium" for="pair-{{ $pairKey }}">
                                            {{ $groupProduct->name }} — {{ $cycleLabels[$cycle] ?? ucfirst(str_replace('_', ' ', $cycle)) }}
                                        </label>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 mt-2">
                                        <span class="badge {{ $changeBadge }}">{{ $changeType }}</span>
                                        <span class="text-muted small">
                                            <x-adminlte.partials.currency :value="$quote['to']['price']" /> / {{ $cycleLabels[$quote['to']['billing_cycle']] ?? ucfirst(str_replace('_', ' ', (string) $quote['to']['billing_cycle'])) }}
                                        </span>
                                    </div>
                                    <div class="mt-2 small">
                                        @if ((float) $quote['payable'] > 0)
                                            <span class="text-muted">Payable</span>
                                            <strong><x-adminlte.partials.currency :value="$quote['payable']" /></strong>
                                        @elseif ((float) $quote['credit'] > 0)
                                            <span class="text-muted">Credit</span>
                                            <span class="text-success"><x-adminlte.partials.currency :value="$quote['credit']" /></span>
                                        @else
                                            <span class="text-muted">No charge</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            @php $firstPair = false; @endphp
                        @endforeach
                    @endforeach

                    @error('to_product_id')
                        <div class="col-12"><div class="text-danger small">{{ $message }}</div></div>
                    @enderror
                    @error('pair')
                        <div class="col-12"><div class="text-danger small">{{ $message }}</div></div>
                    @enderror
                </div>

                <div class="small text-muted mt-3">
                    Shown amounts are base-only. Options and the final charge are set on the next step.
                </div>

                <div class="mt-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        Continue to Configure <i class="bi bi-arrow-right ms-1"></i>
                    </button>
                    <a href="{{ route('admin.orders.show', $order) }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </x-adminlte-card>
        </form>
    @endif
@stop
