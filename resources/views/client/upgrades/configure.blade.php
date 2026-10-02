@extends('adminlte::page')

@section('title', 'Configure Upgrade')

@section('content_header')
    @php
        $backUrl = $order->hostingAccount !== null
            ? route('client.hosting.show', $order->hostingAccount->id)
            : route('client.orders.show', $order->id);
        $cycleLabel = ucfirst(str_replace('_', ' ', (string) $quote['to']['billing_cycle']));
    @endphp
    <x-ui.page-header title="Configure Upgrade" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Products/Services', 'url' => route('client.hosting.index')],
        ['label' => $order->hostingAccount?->host_name ?? ('Order ' . $order->order_no), 'url' => $backUrl],
        ['label' => 'Upgrade/Downgrade', 'url' => route('client.hosting.upgrade', $order)],
        ['label' => 'Configure', 'active' => true],
    ]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

    <div class="row">
        <div class="col-lg-8">
            @include('client.upgrades._steps', ['step' => 2, 'order' => $order])

            <x-adminlte-card icon="bi bi-clipboard-check" title="Plan Summary">
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <th class="w-25 text-muted">Current product</th>
                        <td>{{ $quote['from']['product_name'] }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted">Target product</th>
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
                </table>
            </x-adminlte-card>

            <form method="POST" action="{{ route('client.hosting.upgrade.preview', $order) }}">
                @csrf
                <input type="hidden" name="to_product_id" value="{{ $to->id }}">
                @if ($cycle !== null)
                    <input type="hidden" name="to_billing_cycle" value="{{ $cycle }}">
                @endif

                @php
                    // Reposted selections win; otherwise preselect the served
                    // configuration only when the target is the current
                    // product (an option change) — a product switch starts
                    // from the declared defaults.
                    $preselected = $submittedPreselection !== null
                        ? $submittedPreselection
                        : ((int) $order->product_id === (int) $to->id && $servedSnapshot !== null ? $servedSnapshot : null);
                @endphp

                @if ($links->isNotEmpty())
                    <x-adminlte-card icon="bi bi-sliders" title="Configuration Options">
                        @foreach ($links as $link)
                            @php
                                $optionType = $link->group?->type ?? 'dropdown';
                                $renderable = $link->linkValues->isNotEmpty()
                                    || in_array($optionType, ['quantity', 'text', 'number', 'slider'], true);
                                $old = $preselected !== null ? ($preselected[$link->id] ?? null) : null;
                                $checkedId = null;
                                if ($old !== null && in_array($optionType, ['dropdown', 'radio'], true)) {
                                    $checkedId = $old['value_id'] ?? null;
                                    if ($checkedId === null && isset($old['selected']) && is_scalar($old['selected'])) {
                                        $checkedId = $link->linkValues
                                            ->first(fn ($v) => (string) $v->label === (string) $old['selected'])?->id;
                                    }
                                }
                            @endphp
                            @if ($renderable)
                                <div class="mb-3">
                                    <label class="form-label" for="upgrade-option-{{ $link->id }}">{{ $link->group?->name ?? 'Option' }}</label>
                                    @switch($optionType)
                                        @case('dropdown')
                                            <select class="form-select" name="options[{{ $link->id }}]" id="upgrade-option-{{ $link->id }}">
                                                @foreach ($link->linkValues as $value)
                                                    <option value="{{ $value->id }}" @selected($checkedId !== null ? (int) $checkedId === (int) $value->id : (bool) $value->is_default)>
                                                        {{ $value->label }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            @break

                                        @case('radio')
                                            @foreach ($link->linkValues as $value)
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" name="options[{{ $link->id }}]"
                                                           id="upgrade-option-{{ $link->id }}-{{ $value->id }}" value="{{ $value->id }}"
                                                           @checked($checkedId !== null ? (int) $checkedId === (int) $value->id : (bool) $value->is_default)>
                                                    <label class="form-check-label" for="upgrade-option-{{ $link->id }}-{{ $value->id }}">
                                                        {{ $value->label }}
                                                    </label>
                                                </div>
                                            @endforeach
                                            @break

                                        @case('checkbox')
                                            @php
                                                $checkedIds = $old['value_ids'] ?? [];
                                                if ($checkedIds === [] && isset($old['selected']) && is_array($old['selected'])) {
                                                    $checkedIds = $link->linkValues
                                                        ->filter(fn ($v) => in_array((string) $v->label, array_map('strval', $old['selected']), true))
                                                        ->pluck('id')
                                                        ->all();
                                                }
                                                $hasServedSelection = $old !== null && ($old['value_ids'] ?? []) !== [];
                                            @endphp
                                            @foreach ($link->linkValues as $value)
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="options[{{ $link->id }}][]"
                                                           id="upgrade-option-{{ $link->id }}-{{ $value->id }}" value="{{ $value->id }}"
                                                           @checked($hasServedSelection ? in_array($value->id, $checkedIds, true) : (bool) $value->is_default)>
                                                    <label class="form-check-label" for="upgrade-option-{{ $link->id }}-{{ $value->id }}">
                                                        {{ $value->label }}
                                                    </label>
                                                </div>
                                            @endforeach
                                            @break

                                        @case('quantity')
                                        @case('number')
                                        @case('slider')
                                            @php
                                                $min = \App\Support\OptionNumber::format($link->input_min ?? $link->group?->input_min, '0');
                                                $max = \App\Support\OptionNumber::format($link->input_max ?? $link->group?->input_max);
                                                $step = $optionType === 'quantity'
                                                    ? '1'
                                                    : \App\Support\OptionNumber::format($link->input_step ?? $link->group?->input_step, '1');
                                                $amount = isset($old['selected']) && is_numeric($old['selected'])
                                                    ? \App\Support\OptionNumber::format($old['selected'])
                                                    : $min;
                                            @endphp
                                            <input type="number" class="form-control" name="options[{{ $link->id }}]"
                                                   id="upgrade-option-{{ $link->id }}"
                                                   min="{{ $min }}" max="{{ $max }}" step="{{ $step }}" value="{{ $amount }}">
                                            @break

                                        @default
                                            <input type="text" class="form-control" name="options[{{ $link->id }}]"
                                                   id="upgrade-option-{{ $link->id }}" maxlength="255" required
                                                   value="{{ $old['selected'] ?? '' }}"
                                                   placeholder="{{ $link->input_placeholder ?? $link->group?->input_placeholder }}">
                                    @endswitch
                                </div>
                            @endif
                        @endforeach
                        <div class="small text-muted">
                            Your choices are included in the total computed at review.
                        </div>
                    </x-adminlte-card>
                @endif

                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                    <a href="{{ route('client.hosting.upgrade', $order) }}" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i> Back to Plan
                    </a>
                    <button type="submit" class="btn btn-primary">
                        Continue to Review <i class="bi bi-arrow-right ms-1"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>
@stop