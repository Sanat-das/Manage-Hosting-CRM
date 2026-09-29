@extends('adminlte::page')

@section('title', 'My Wallet')

@section('content_header')
    <x-ui.page-header title="My Wallet" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Dashboard', 'url' => route('client.dashboard')],
        ['label' => 'Wallet', 'active' => true],
    ]" />
@stop

@section('content')
    {{-- Balance summary --}}
    <div class="row mb-4">
        <div class="col-md-6">
            <x-adminlte-card icon="bi bi-wallet2" title="Balance">
                <div class="text-center py-3">
                    <div class="fs-2 fw-bold {{ $customer->balance < 0 ? 'text-danger' : 'text-success' }}">
                        <x-adminlte.partials.currency :value="$customer->balance" />
                    </div>
                    <div class="text-muted">Current balance</div>
                </div>
            </x-adminlte-card>
        </div>
        <div class="col-md-6">
            <x-adminlte-card icon="bi bi-credit-card" title="Credit">
                <div class="text-center py-3">
                    <div class="fs-2 fw-bold text-info">
                        <x-adminlte.partials.currency :value="$customer->credit" />
                    </div>
                    <div class="text-muted">Available credit</div>
                </div>
            </x-adminlte-card>
        </div>
    </div>

    {{-- Transaction history --}}
    <x-adminlte.partials.datatable
        icon="bi bi-clock-history"
        title="Transaction History"
        :search-value="$search"
        search-placeholder="Search description..."
        :columns="[
            ['label' => 'Date', 'sort' => 'created_at'],
            ['label' => 'Type', 'sort' => 'type'],
            ['label' => 'Description', 'sort' => 'description'],
            ['label' => 'Amount', 'sort' => 'amount', 'class' => 'grid-numeric'],
        ]"
        :pagination="$transactions"
    >
        @forelse ($transactions as $txn)
            <tr>
                <td class="text-muted">{{ $txn->created_at?->format('M j, Y H:i') }}</td>
                <td><span class="badge text-bg-info">{{ ucfirst($txn->type ?? 'transaction') }}</span></td>
                <td>{{ $txn->description ?? '—' }}</td>
                <td class="grid-numeric fw-bold {{ ($txn->amount ?? 0) < 0 ? 'text-danger' : 'text-success' }}">
                    <x-adminlte.partials.currency :value="abs($txn->amount ?? 0)" />
                </td>
            </tr>
        @empty
            <x-ui.empty-table-row colSpan="4" icon="bi bi-clock-history" title="No transactions yet." />
        @endforelse
    </x-adminlte.partials.datatable>
@stop
