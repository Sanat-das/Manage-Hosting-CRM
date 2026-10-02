@extends('adminlte::page')

@section('title', 'Review & Confirm Upgrade')

@section('content_header')
    @php
        $backUrl = $order->hostingAccount !== null
            ? route('client.hosting.show', $order->hostingAccount->id)
            : route('client.orders.show', $order->id);
        $cycleLabel = ucfirst(str_replace('_', ' ', (string) $quote['to']['billing_cycle']));
    @endphp
    <x-ui.page-header title="Review & Confirm Upgrade" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Products/Services', 'url' => route('client.hosting.index')],
        ['label' => $order->hostingAccount?->host_name ?? ('Order ' . $order->order_no), 'url' => $backUrl],
        ['label' => 'Upgrade/Downgrade', 'url' => route('client.hosting.upgrade', $order)],
        ['label' => 'Review & Confirm', 'active' => true],
    ]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

    <div class="row">
        <div class="col-lg-8">
            @include('client.upgrades._steps', ['step' => 3, 'order' => $order])

            @if ($quote['change_type'] === 'upgrade')
                <div class="alert alert-success d-flex align-items-center gap-2 mb-3" role="alert">
                    <i class="bi bi-arrow-up-circle"></i>
                    <span>You're upgrading to <strong>{{ $quote['to']['product_name'] }}</strong> — a one-time charge of <x-adminlte.partials.currency :value="$quote['payable']" /> applies.</span>
                </div>
            @elseif ($quote['change_type'] === 'downgrade')
                <div class="alert alert-info d-flex align-items-center gap-2 mb-3" role="alert">
                    <i class="bi bi-arrow-down-circle"></i>
                    <span>You're downgrading to <strong>{{ $quote['to']['product_name'] }}</strong> — a wallet credit of <x-adminlte.partials.currency :value="$quote['credit']" /> will be applied.</span>
                </div>
            @else
                <div class="alert alert-secondary d-flex align-items-center gap-2 mb-3" role="alert">
                    <i class="bi bi-arrow-repeat"></i>
                    <span>You're moving to <strong>{{ $quote['to']['product_name'] }}</strong> at the same rate.</span>
                </div>
            @endif

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
                        <th class="text-muted">{{ (float) $quote['payable'] > 0 ? 'You pay' : ((float) $quote['credit'] > 0 ? 'Credit to your wallet' : 'Net amount') }}</th>
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
                    Review the figures above. Submitting this request files it for the prorated
                    charge shown — a confirmation email follows. You can go back to adjust your
                    choice at any time before submitting.
                </p>
                <form method="POST" action="{{ route('client.hosting.upgrade.store', $order) }}">
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
                            I confirm the details above are correct and I want to proceed.
                        </label>
                        @error('confirm')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="d-flex flex-wrap justify-content-end align-items-center gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-send me-1"></i> Submit Upgrade Request
                        </button>
                    </div>
                </form>

                <hr>

                <form method="POST" action="{{ route('client.hosting.upgrade.configure', $order) }}" class="d-flex justify-content-start">
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