@extends('adminlte::page')

@section('title', 'My Wallet')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">My Wallet</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active">Wallet</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    {{-- Balance summary --}}
    <div class="row mb-4">
        <div class="col-md-6">
            <x-adminlte-card icon="bi bi-wallet2" title="Balance">
                <div class="text-center py-3">
                    <div class="fs-2 fw-bold {{ $customer->balance < 0 ? 'text-danger' : 'text-success' }}">
                        {{ number_format($customer->balance, 2) }}
                    </div>
                    <div class="text-muted">Current balance</div>
                </div>
            </x-adminlte-card>
        </div>
        <div class="col-md-6">
            <x-adminlte-card icon="bi bi-credit-card" title="Credit">
                <div class="text-center py-3">
                    <div class="fs-2 fw-bold text-info">
                        {{ number_format($customer->credit, 2) }}
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
                    {{ number_format(abs($txn->amount ?? 0), 2) }}
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="text-center text-muted py-4">No transactions yet.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
