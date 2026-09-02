@extends('adminlte::page')

@section('title', $account->host_name)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">{{ $account->host_name }}</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.hosting.index') }}">Products/Services</a></li>
                <li class="breadcrumb-item active">{{ $account->host_name }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <div class="row">
        <div class="col-lg-8">
            <x-adminlte-card icon="bi bi-hdd-stack" title="Account Details">
                <table class="table table-sm table-borderless">
                    <tr><th class="w-25 text-muted">Host name</th><td>{{ $account->host_name }}</td></tr>
                    <tr><th class="text-muted">Product</th><td>{{ $account->product?->name ?? '—' }}</td></tr>
                    <tr><th class="text-muted">Domain</th><td>{{ $account->domain ?? '—' }}</td></tr>
                    <tr><th class="text-muted">Server</th><td>{{ $account->server?->name ?? '—' }}</td></tr>
                    <tr>
                        <th class="text-muted">Status</th>
                        <td>
                            <x-adminlte.partials.status-badge :status="$account->status" />
                        </td>
                    </tr>
                </table>
            </x-adminlte-card>

            {{-- Module capability panels (cache-only, client-safe) --}}
            @if (! empty($modulePanels))
                @foreach ($modulePanels as $panel)
                    <div class="mt-3">
                        @include($panel['view'], $panel['data'])
                    </div>
                @endforeach
            @endif

            {{-- Product configuration: order-time snapshot, falling back to the
                 product's live option links when no snapshot was captured. --}}
            @php
                $configSnapshot = null;
                $linkedItem = $account->order?->items
                    ?->first(fn ($i) => (int) $i->product_id === (int) $account->product_id && ! empty($i->config_options))
                    ?? $account->order?->items?->first(fn ($i) => ! empty($i->config_options));

                if ($linkedItem !== null) {
                    $configSnapshot = $linkedItem->config_options;
                }
            @endphp
            @if ($configSnapshot !== null)
                <x-adminlte-card icon="bi bi-sliders" title="Product Configuration">
                    @include('client.partials._selected_options', [
                        'entries' => $configSnapshot['options'] ?? [],
                        'modifiersByLink' => [],
                        'cycle' => $account->order?->billing_cycle ?? 'monthly',
                        'includeUnselected' => true,
                    ])
                </x-adminlte-card>
            @else
                @php $account->product?->loadMissing('optionLinks.group', 'optionLinks.linkValues.pricing'); @endphp
                @if ($account->product !== null && $account->product->optionLinks->isNotEmpty())
                    <x-adminlte-card icon="bi bi-sliders" title="Product Configuration">
                        <ul class="list-unstyled small mb-0">
                            @foreach ($account->product->optionLinks as $liveLink)
                                <li>
                                    <strong>{{ $liveLink->group?->name }}:</strong>
                                    {{ $liveLink->linkValues->isNotEmpty() ? $liveLink->linkValues->pluck('label')->implode(', ') : '—' }}
                                </li>
                            @endforeach
                        </ul>
                    </x-adminlte-card>
                @endif
            @endif
        </div>

        <div class="col-lg-4">
            <x-adminlte-card icon="bi bi-tools" title="Quick Actions">
                @if ($account->status === 'active')
                    <a href="#" class="btn btn-outline-warning w-100 mb-2 disabled" title="Coming soon"><i class="bi bi-pause-circle me-1"></i> Suspend</a>
                @endif
                <a href="#" class="btn btn-outline-info w-100 mb-2 disabled" title="Coming soon"><i class="bi bi-key me-1"></i> Change Password</a>
                <a href="#" class="btn btn-outline-info w-100 mb-2 disabled" title="Coming soon"><i class="bi bi-envelope me-1"></i> Manage Emails</a>
                <a href="#" class="btn btn-outline-secondary w-100 disabled" title="Coming soon"><i class="bi bi-arrow-clockwise me-1"></i> Restart</a>
            </x-adminlte-card>

            @if ($billing !== null)
                @php
                    $cycleLabels = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'semi_annual' => 'Semi-Annual',
                        'annual' => 'Annual', 'biennial' => 'Biennial', 'one_time' => 'One-Time'];
                @endphp
                <x-adminlte-card icon="bi bi-receipt" title="Billing">
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <th class="text-muted">Amount</th>
                            <td class="text-end"><strong>{{ number_format((float) ($billing['amount'] ?? 0), 2) }}</strong></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Cycle</th>
                            <td class="text-end">{{ $cycleLabels[$billing['cycle'] ?? ''] ?? ($billing['cycle'] ?? '—') }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">Next Due</th>
                            <td class="text-end">{{ $billing['next_billing_date']?->format('M j, Y') ?? '—' }}</td>
                        </tr>
                        @if ($account->order)
                            <tr>
                                <th class="text-muted">Order</th>
                                <td class="text-end">{{ $account->order->order_no }}</td>
                            </tr>
                        @endif
                    </table>
                </x-adminlte-card>
            @endif

            @if ($account->ipAddresses->isNotEmpty())
                <x-adminlte-card icon="bi bi-globe2" title="IP Addresses">
                    <ul class="list-unstyled mb-0">
                        @foreach ($account->ipAddresses as $ip)
                            <li class="d-flex justify-content-between align-items-center py-1">
                                <code>{{ $ip->ip_address }}</code>
                                <span class="badge text-bg-{{ $ip->type === 'public' ? 'primary' : 'secondary' }}">
                                    {{ ucfirst($ip->type) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </x-adminlte-card>
            @endif
        </div>
    </div>
@stop
