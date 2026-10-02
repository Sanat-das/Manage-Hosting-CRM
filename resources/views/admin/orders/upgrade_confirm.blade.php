@extends('adminlte::page')

@section('title', 'Review & Confirm Upgrade')

@section('content_header')
    @php
        $cycleLabel = ucfirst(str_replace('_', ' ', (string) $quote['to']['billing_cycle']));
    @endphp
    <x-ui.page-header title="Review & Confirm Upgrade" :subtitle="'Order '.$order->order_no" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Orders', 'url' => route('admin.orders.index')],
        ['label' => $order->order_no, 'url' => route('admin.orders.show', $order)],
        ['label' => 'Upgrade / Downgrade', 'url' => route('admin.orders.upgrade', $order)],
        ['label' => 'Review & Confirm', 'active' => true],
    ]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

    @include('client.upgrades._steps', ['step' => 3, 'order' => $order, 'backRoute' => route('admin.orders.upgrade', $order)])

    @php
        $changeBanners = [
            'upgrade' => ['class' => 'alert-success', 'icon' => 'bi-arrow-up-circle', 'text' => 'This is an upgrade — the recurring cost increases.'],
            'downgrade' => ['class' => 'alert-warning', 'icon' => 'bi-arrow-down-circle', 'text' => 'This is a downgrade — any credit is applied to the customer wallet.'],
            'equal' => ['class' => 'alert-secondary', 'icon' => 'bi-arrow-left-right', 'text' => 'This is a like-for-like change — no recurring price difference.'],
        ];
        $changeBanner = $changeBanners[$quote['change_type']] ?? $changeBanners['equal'];
    @endphp
    <div class="alert {{ $changeBanner['class'] }} mb-3" role="alert"
         style="border-radius: var(--radius-md); box-shadow: var(--shadow-sm);">
        <i class="bi {{ $changeBanner['icon'] }} me-1" aria-hidden="true"></i>
        {{ $changeBanner['text'] }}
    </div>

    <div class="row">
        <div class="col-lg-8">
            <x-adminlte-card icon="bi bi-clipboard-check" title="Quote Preview">
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <th class="w-25 text-muted">Target product</th>
                        <td>{{ $quote['to']['product_name'] }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted">Billing cycle</th>
                        <td>{{ $cycleLabel }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted">Credited (current plan, prorated)</th>
                        <td class="text-end"><x-adminlte.partials.currency :value="$quote['credited']" /></td>
                    </tr>
                    <tr>
                        <th class="text-muted">Debited (new plan, prorated)</th>
                        <td class="text-end"><x-adminlte.partials.currency :value="$quote['debited']" /></td>
                    </tr>
                    @if ((float) $quote['option_adjustment'] != 0)
                        <tr>
                            <th class="text-muted">Option adjustment</th>
                            <td class="text-end">{{ (float) $quote['option_adjustment'] > 0 ? '+' : '' }}<x-adminlte.partials.currency :value="abs((float) $quote['option_adjustment'])" /></td>
                        </tr>
                    @endif
                    @if ((float) $quote['setup_fee_difference'] > 0)
                        <tr>
                            <th class="text-muted">Setup fee difference</th>
                            <td class="text-end"><x-adminlte.partials.currency :value="$quote['setup_fee_difference']" /></td>
                        </tr>
                    @endif
                    <tr>
                        <th class="text-muted">{{ (float) $quote['payable'] > 0 ? 'Payable' : ((float) $quote['credit'] > 0 ? 'Credit to wallet' : 'Net amount') }}</th>
                        <td class="text-end">
                            @if ((float) $quote['payable'] > 0)
                                <x-adminlte.partials.currency :value="$quote['payable']" />
                            @elseif ((float) $quote['credit'] > 0)
                                <x-adminlte.partials.currency :value="$quote['credit']" />
                            @else
                                <span class="text-muted">No charge</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <th class="text-muted">Proration</th>
                        <td class="text-end">
                            @if ($quote['prorated'])
                                {{ $quote['proration_days'] }} of {{ $quote['period_days'] }} days
                            @else
                                Not prorated
                            @endif
                        </td>
                    </tr>
                </table>
            </x-adminlte-card>

            <x-adminlte-card icon="bi bi-sliders" title="Configuration Changes">
                @if ($option_rows === [])
                    <p class="text-muted mb-0">No configuration changes.</p>
                @else
                    @php
                        $cycleSuffixMap = ['free' => 'free', 'one_time' => 'once', 'monthly' => 'mo', 'quarterly' => 'qtr', 'semi_annual' => '6mo', 'annual' => 'yr', 'biennial' => '2yr', 'triennial' => '3yr'];
                        $cycleSuffix = $cycleSuffixMap[(string) $quote['to']['billing_cycle']] ?? 'mo';
                    @endphp
                    <table class="table table-sm table-borderless mb-0">
                        <thead>
                            <tr>
                                <th class="text-muted">Option</th>
                                <th class="text-muted">Current</th>
                                <th class="text-muted">New</th>
                                <th class="text-muted text-end">Unit rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($option_rows as $row)
                                <tr>
                                    <td>{{ $row['name'] }}</td>
                                    <td>{{ $row['old'] ?? '—' }}</td>
                                    <td>{{ $row['new'] ?? '—' }}{{ $row['new'] !== null && $row['unit'] !== '' ? ' '.$row['unit'] : '' }}</td>
                                    <td class="text-end">
                                        @if ($row['price_unit'] !== null)
                                            +<x-adminlte.partials.currency :value="$row['price_unit']" />/{{ $cycleSuffix }}
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-adminlte-card>

            <x-adminlte-card icon="bi bi-list-check" title="What you are confirming">
                <ul class="list-unstyled mb-0">
                    <li class="d-flex gap-2 mb-2">
                        <i class="bi bi-check2 text-success mt-1"></i>
                        <span>Target product: <strong>{{ $quote['to']['product_name'] }}</strong></span>
                    </li>
                    <li class="d-flex gap-2 mb-2">
                        <i class="bi bi-check2 text-success mt-1"></i>
                        <span>New billing cycle: <strong>{{ $cycleLabel }}</strong></span>
                    </li>
                    @forelse ($option_rows as $row)
                        <li class="d-flex gap-2 mb-2">
                            <i class="bi bi-check2 text-success mt-1"></i>
                            <span>{{ $row['name'] }}: <strong>{{ $row['old'] ?? '—' }}</strong> → <strong>{{ $row['new'] ?? '—' }}</strong></span>
                        </li>
                    @empty
                        <li class="d-flex gap-2 mb-2">
                            <i class="bi bi-check2 text-success mt-1"></i>
                            <span>Configuration changes: <strong>none</strong></span>
                        </li>
                    @endforelse
                    <li class="d-flex gap-2">
                        <i class="bi bi-check2 text-success mt-1"></i>
                        <span>
                            @if ((float) $quote['payable'] > 0)
                                One-time charge of <x-adminlte.partials.currency :value="$quote['payable']" />
                            @elseif ((float) $quote['credit'] > 0)
                                Wallet credit of <x-adminlte.partials.currency :value="$quote['credit']" />
                            @else
                                No charge
                            @endif
                        </span>
                    </li>
                </ul>
            </x-adminlte-card>

            <x-adminlte-card icon="bi bi-send" title="Confirm Upgrade">
                <p class="text-muted small">
                    Review the figures above. Submitting places AND approves this change for the customer —
                    a payable raises an upgrade invoice, a credit is applied to the wallet immediately.
                </p>
                <form method="POST" action="{{ route('admin.orders.upgrade.store', $order) }}">
                    @csrf
                    <input type="hidden" name="to_product_id" value="{{ $quote['to']['product_id'] }}">
                    @if ($cycle !== null)
                        <input type="hidden" name="to_billing_cycle" value="{{ $cycle }}">
                    @endif
                    @foreach ($options ?? [] as $linkId => $value)
                        @if (is_array($value))
                            @foreach ($value as $v)
                                <input type="hidden" name="options[{{ $linkId }}][]" value="{{ $v }}">
                            @endforeach
                        @else
                            <input type="hidden" name="options[{{ $linkId }}]" value="{{ $value }}">
                        @endif
                    @endforeach

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="confirm" value="1"
                               id="confirm-upgrade" required @error('confirm') aria-invalid="true" @enderror>
                        <label class="form-check-label" for="confirm-upgrade">
                            I confirm the details above are correct and I want to place and approve this change.
                        </label>
                        @error('confirm')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="d-flex flex-wrap justify-content-end align-items-center gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Place & Approve Upgrade
                        </button>
                    </div>
                </form>

                <hr>

                <form method="POST" action="{{ route('admin.orders.upgrade.configure', $order) }}" class="d-flex justify-content-start">
                    @csrf
                    <input type="hidden" name="to_product_id" value="{{ $quote['to']['product_id'] }}">
                    @if ($cycle !== null)
                        <input type="hidden" name="to_billing_cycle" value="{{ $cycle }}">
                    @endif
                    @foreach ($options ?? [] as $linkId => $value)
                        @if (is_array($value))
                            @foreach ($value as $v)
                                <input type="hidden" name="options[{{ $linkId }}][]" value="{{ $v }}">
                            @endforeach
                        @else
                            <input type="hidden" name="options[{{ $linkId }}]" value="{{ $value }}">
                        @endif
                    @endforeach
                    <button type="submit" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i> Edit Configuration
                    </button>
                </form>
            </x-adminlte-card>
        </div>
    </div>
@stop
