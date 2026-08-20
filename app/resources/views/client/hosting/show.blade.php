@extends('adminlte::page')

@section('title', $account->domain ?? 'Product/Service Detail')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">{{ $account->domain ?? $account->product?->name }}</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.hosting.index') }}">Products/Services</a></li>
                <li class="breadcrumb-item active">{{ $account->domain ?? 'Details' }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <div class="row">
        <div class="col-lg-8">
            <x-adminlte-card icon="bi bi-hdd-stack" title="Account Details">
                <table class="table table-sm table-borderless">
                    <tr><th class="w-25 text-muted">Product</th><td>{{ $account->product?->name ?? '—' }}</td></tr>
                    <tr><th class="text-muted">Domain</th><td>{{ $account->domain ?? '—' }}</td></tr>
                    <tr><th class="text-muted">Username</th><td><code>{{ $account->username ?? '—' }}</code></td></tr>
                    <tr><th class="text-muted">Server</th><td>{{ $account->server?->name ?? '—' }}</td></tr>
                    <tr>
                        <th class="text-muted">Status</th>
                        <td>
                            @php $badge = ['active'=>'success','suspended'=>'warning','terminated'=>'danger','pending'=>'info'][$account->status] ?? 'secondary'; @endphp
                            <span class="badge bg-{{ $badge }}">{{ ucfirst($account->status) }}</span>
                        </td>
                    </tr>
                </table>
            </x-adminlte-card>

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

            {{-- Usage meters --}}
            <x-adminlte-card icon="bi bi-speedometer2" title="Resource Usage">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold text-muted small">Disk Usage</label>
                        <div class="progress" style="height: 24px;">
                            @php $disk = $account->diskUsagePercent(); @endphp
                            <div class="progress-bar bg-{{ $disk > 90 ? 'danger' : ($disk > 70 ? 'warning' : 'success') }}"
                                 style="width: {{ min($disk, 100) }}%">{{ $disk }}%</div>
                        </div>
                        <small class="text-muted">{{ number_format($account->disk_used) }} / {{ number_format($account->disk_quota) }} MB</small>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold text-muted small">Bandwidth Usage</label>
                        <div class="progress" style="height: 24px;">
                            @php $bw = $account->bandwidthUsagePercent(); @endphp
                            <div class="progress-bar bg-{{ $bw > 90 ? 'danger' : ($bw > 70 ? 'warning' : 'info') }}"
                                 style="width: {{ min($bw, 100) }}%">{{ $bw }}%</div>
                        </div>
                        <small class="text-muted">{{ number_format($account->bandwidth_used) }} / {{ number_format($account->bandwidth_quota) }} MB</small>
                    </div>
                </div>
            </x-adminlte-card>
        </div>

        <div class="col-lg-4">
            <x-adminlte-card icon="bi bi-tools" title="Quick Actions">
                @if ($account->status === 'active')
                    <a href="#" class="btn btn-outline-warning btn-block mb-2 disabled" title="Coming soon"><i class="bi bi-pause-circle me-1"></i> Suspend</a>
                @endif
                <a href="#" class="btn btn-outline-info btn-block mb-2 disabled" title="Coming soon"><i class="bi bi-key me-1"></i> Change Password</a>
                <a href="#" class="btn btn-outline-info btn-block mb-2 disabled" title="Coming soon"><i class="bi bi-envelope me-1"></i> Manage Emails</a>
                <a href="#" class="btn btn-outline-secondary btn-block disabled" title="Coming soon"><i class="bi bi-arrow-clockwise me-1"></i> Restart</a>
            </x-adminlte-card>

            @if ($account->order)
                <x-adminlte-card icon="bi bi-receipt" title="Linked Order">
                    <span class="text-muted">Order #{{ $account->order_id }}</span>
                </x-adminlte-card>
            @endif
        </div>
    </div>
@stop
