@extends('adminlte::page')

@section('title', 'Upgrade/Downgrade')

@section('content_header')
    @php
        $backUrl = $order->hostingAccount !== null
            ? route('client.hosting.show', $order->hostingAccount->id)
            : route('client.orders.show', $order->id);
    @endphp
    <x-ui.page-header title="Upgrade/Downgrade" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Products/Services', 'url' => route('client.hosting.index')],
        ['label' => $order->hostingAccount?->host_name ?? ('Order ' . $order->order_no), 'url' => $backUrl],
        ['label' => 'Upgrade/Downgrade', 'active' => true],
    ]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

    <div class="row">
        <div class="col-lg-8">
            @include('client.upgrades._steps', ['step' => 1, 'order' => $order])

            {{-- Current service summary — the "from" side of every quote below. --}}
            <x-adminlte-card icon="bi bi-hdd-stack" title="Current Service">
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <th class="w-25 text-muted">Product</th>
                        <td>{{ $order->product?->name ?? '—' }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted">Cycle</th>
                        <td>{{ ucfirst(str_replace('_', ' ', (string) $order->billing_cycle)) }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted">Order</th>
                        <td>{{ $order->order_no }}</td>
                    </tr>
                </table>
            </x-adminlte-card>

            @php
                // Both roles present → two headed subsections ("Upgrade to" /
                // "Downgrades to"); a single role → one undivided list.
                $sections = [];
                if ($upgradeTargets !== []) {
                    $sections[] = [
                        'title' => $downgradeTargets !== [] ? 'Upgrade to' : 'Available plans',
                        'groups' => $upgradeTargets,
                    ];
                }
                if ($downgradeTargets !== []) {
                    $sections[] = ['title' => 'Downgrades to', 'groups' => $downgradeTargets];
                }
            @endphp

            @if ($upgradeTargets === [] && $downgradeTargets === [])
                <x-adminlte-card icon="bi bi-arrow-up-circle" title="Upgrade/Downgrade">
                    <x-adminlte.partials.empty-state
                        icon="bi bi-arrow-repeat"
                        title="No upgrades are currently available for this service."
                        :action-label="'Back to Service'" :action-url="$backUrl" />
                </x-adminlte-card>
            @else
                <form method="POST" action="{{ route('client.hosting.upgrade.configure', $order) }}">
                    @csrf

                    @foreach ($sections as $section)
                        <h4 class="fw-semibold {{ $loop->first ? '' : 'mt-4' }} mb-3">{{ $section['title'] }}</h4>
                        @foreach ($section['groups'] as $group)
                            @php $product = $group['product']; @endphp
                            <x-adminlte-card icon="bi bi-box-arrow-in-right" :title="$product->name">
                                @foreach ($group['pairs'] as $pairIndex => $pair)
                                    @php
                                        $quote = $pair['quote'];
                                        $cycle = (string) $quote['to']['billing_cycle'];
                                        $cycleLabel = ucfirst(str_replace('_', ' ', $cycle));
                                        $badgeMap = ['upgrade' => 'success', 'downgrade' => 'warning', 'equal' => 'secondary'];
                                        $pairKey = $product->id.'-'.$cycle;
                                        $firstPair ??= true;
                                    @endphp
                                    <div class="d-flex flex-wrap align-items-start gap-3 @if ($pairIndex > 0) border-top pt-3 mt-3 @endif">
                                        <div class="form-check mt-1">
                                            <input class="form-check-input" type="radio" name="pair"
                                                   id="pair-{{ $pairKey }}" value="{{ $product->id }}:{{ $cycle }}"
                                                   @checked($firstPair)>
                                            <label class="form-check-label" for="pair-{{ $pairKey }}">
                                                <span class="visually-hidden">Select {{ $product->name }} — {{ $cycleLabel }}</span>
                                            </label>
                                        </div>
                                        <table class="table table-sm table-borderless mb-0 flex-fill" style="min-width: 14rem;">
                                            <tr>
                                                <th class="text-muted">Cycle</th>
                                                <td class="text-end">{{ $cycleLabel }}</td>
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
                                        <x-adminlte.partials.status-badge :status="$quote['change_type']" :map="$badgeMap" class="mt-2" />
                                    </div>
                                    @php $firstPair = false; @endphp
                                @endforeach
                            </x-adminlte-card>
                        @endforeach
                    @endforeach

                    <div class="small text-muted mt-3">
                        Shown amounts are base-only. Final charge shown after review.
                    </div>

                    <div class="d-flex justify-content-end mt-2">
                        <button type="submit" class="btn btn-primary">
                            Continue to Configure <i class="bi bi-arrow-right ms-1"></i>
                        </button>
                    </div>
                </form>
            @endif
        </div>
    </div>
@stop