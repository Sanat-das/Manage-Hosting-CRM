@extends('adminlte::page')

@section('title', 'Quotes')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Quotes</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active">Quotes</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif

    <x-adminlte.partials.metric-cards :items="[
        ['title' => $stats->get('draft', 0), 'text' => 'Draft', 'icon' => 'bi bi-pencil', 'theme' => 'secondary'],
        ['title' => $stats->get('delivered', 0), 'text' => 'Delivered', 'icon' => 'bi bi-envelope', 'theme' => 'primary'],
        ['title' => $stats->get('accepted', 0), 'text' => 'Accepted', 'icon' => 'bi bi-check-circle', 'theme' => 'success'],
        ['title' => $stats->get('rejected', 0) + $stats->get('dead', 0), 'text' => 'Rejected/Dead', 'icon' => 'bi bi-x-circle', 'theme' => 'danger'],
    ]" />

    <x-adminlte.partials.datatable icon="bi bi-file-text" title="All Quotes"
        :search-value="$search" search-placeholder="Search quote #, subject..."
        :status-options="$stages" :status-value="$stage"
        :columns="[
            ['label' => 'Quote #', 'sort' => 'quote_no'], ['label' => 'Customer', 'sort' => 'customer'], ['label' => 'Subject', 'sort' => 'subject'],
            ['label' => 'Total', 'sort' => 'total', 'class' => 'text-end'], ['label' => 'Stage', 'sort' => 'stage'],
            ['label' => 'Valid until', 'sort' => 'valid_until'], ['label' => 'Actions', 'class' => 'text-end'],
        ]" :pagination="$quotes">
        <x-slot name="tools">
            @can('invoices.create')
                <a href="{{ route('admin.quotes.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> New Quote</a>
            @endcan
        </x-slot>
        @forelse ($quotes as $quote)
            <tr>
                <td><a href="{{ route('admin.quotes.show', $quote) }}"><strong>{{ $quote->quote_no }}</strong></a></td>
                <td>{{ $quote->customer?->full_name ?? '—' }}</td>
                <td>{{ $quote->subject }}</td>
                <td class="text-end fw-bold">{{ number_format($quote->total, 2) }}</td>
                <td><x-adminlte.partials.status-badge :status="$quote->stage" /></td>
                <td class="text-muted">{{ $quote->valid_until?->format('M j, Y') ?? '—' }}</td>
                <td class="text-end"><span class="text-muted">—</span></td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center text-muted py-4">No quotes found.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
