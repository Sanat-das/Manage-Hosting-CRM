@extends('adminlte::page')

@section('title', 'Tax Rates')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Tax Rates</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Tax Rates</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif

    <x-adminlte-card icon="bi bi-percent" title="All Tax Rates">
        <div class="text-end mb-3">
            <a href="{{ route('admin.tax-rates.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Tax Rate</a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Name</th><th>Rate</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                    @forelse ($rates as $rate)
                        <tr>
                            <td><a href="{{ route('admin.tax-rates.show', $rate) }}"><strong>{{ $rate->name ?? '—' }}</strong></a></td>
                            <td>{{ $rate->rate }}%</td>
                            <td>
                                @if ($rate->is_active)
                                    <span class="badge badge-success">Active</span>
                                @else
                                    <span class="badge badge-secondary">Inactive</span>
                                @endif
                            </td>
                            <td class="text-end"><a href="{{ route('admin.tax-rates.edit', $rate) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-4">No tax rates found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $rates->links() }}
    </x-adminlte-card>
@stop
