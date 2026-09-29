@extends('adminlte::page')

@section('title', 'Products/Services')

@section('content_header')
    <x-ui.page-header title="Products/Services" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Dashboard', 'url' => route('client.dashboard')],
        ['label' => 'Products/Services', 'active' => true],
    ]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

    {{-- Status summary cards (click to filter) --}}
    @php
        $statusCards = [
            'active'     => ['label' => 'Active',     'theme' => 'success'],
            'suspended'  => ['label' => 'Suspended',  'theme' => 'danger'],
            'pending'    => ['label' => 'Pending',    'theme' => 'warning'],
            'terminated' => ['label' => 'Terminated', 'theme' => 'secondary'],
        ];
    @endphp
    <div class="row">
        @foreach ($statusCards as $key => $card)
            <div class="col-lg-3 col-6 mb-3">
                <a href="{{ route('client.hosting.index', ['status' => $key] + request()->except(['status', 'page'])) }}"
                   class="d-block text-decoration-none {{ $status === $key ? 'opacity-75' : '' }}">
                    <x-adminlte-small-box :title="$counts[$key] ?? 0" :text="$card['label']"
                                          icon="bi bi-hdd-stack" :theme="$card['theme']" />
                </a>
            </div>
        @endforeach
    </div>

    @php
        $cycleLabels = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'semi_annual' => 'Semi-Annual',
            'annual' => 'Annual', 'biennial' => 'Biennial', 'one_time' => 'One-Time'];
    @endphp

    <x-adminlte.partials.datatable
        icon="bi bi-hdd-stack"
        title="Products/Services"
        :search-value="$search"
        search-placeholder="Search domain, product or server..."
        :status-options="collect($statusCards)->map(fn ($card) => $card['label'])->all()"
        :status-value="$status ?? ''"
        :columns="[
            ['label' => 'Host Name', 'sort' => 'host_name'],
            ['label' => 'Product', 'sort' => 'product'],
            ['label' => 'Domain', 'sort' => 'domain'],
            ['label' => 'IP Address'],
            ['label' => 'Billing'],
            ['label' => 'Next Due', 'sort' => 'next_due'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$accounts"
    >
                @forelse ($accounts as $account)
                    @php
                        $info = $billing[$account->id] ?? null;
                        $nextDue = $info['next_billing_date'] ?? $account->next_due_date;
                        $ips = $account->ipAddresses->pluck('ip_address');
                    @endphp
                    <tr data-row-link="{{ route('client.hosting.show', $account) }}">
                        <td><strong>{{ $account->host_name }}</strong></td>
                        <td>{{ $account->product?->name ?? '—' }}</td>
                        <td>{{ $account->domain ?? '—' }}</td>
                        <td>
                            @if ($ips->isNotEmpty())
                                @foreach ($ips as $ip)
                                    <code>{{ $ip }}</code>@if (! $loop->last)<br>@endif
                                @endforeach
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if ($info && $info['amount'] !== null)
                                <div><strong><x-adminlte.partials.currency :value="$info['amount']" /></strong></div>
                                <div class="text-muted small">{{ $cycleLabels[$info['cycle']] ?? ($info['cycle'] ?? '—') }}</div>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-muted">
                            {{ $nextDue?->format('M j, Y') ?? '—' }}
                        </td>
                        <td>
                            <x-adminlte.partials.status-badge :status="$account->status" />
                        </td>
                        <td class="text-end">
                            <div class="table-actions">
                                <a href="{{ route('client.hosting.show', $account) }}" class="btn btn-sm btn-outline-primary btn-icon" title="Details" aria-label="Details"><i class="bi bi-eye"></i></a>
                            </div>
                        </td>
                    </tr>
                @empty
                    @if ($search !== '' || $status !== null)
                        <x-ui.empty-table-row colSpan="8" icon="bi bi-hdd-stack" title="No products/services match your filters." />
                    @else
                        <x-ui.empty-table-row colSpan="8" icon="bi bi-hdd-stack" title="You don't have any products or services yet." actionLabel="Browse the Store" :actionUrl="route('client.store.index')" />
                    @endif
                @endforelse
    </x-adminlte.partials.datatable>

    @push('js')
        <script>
            document.querySelectorAll('tr[data-row-link]').forEach(function (row) {
                row.style.cursor = 'pointer';
                row.addEventListener('click', function (e) {
                    if (e.target.closest('a, button, input, select')) return;
                    window.location = row.dataset.rowLink;
                });
            });
        </script>
    @endpush
@stop
