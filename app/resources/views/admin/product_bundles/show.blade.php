@extends('adminlte::page')
@section('title', 'Product Bundle — '.$bundle->name)
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">{{ $bundle->name }}</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.product-bundles.index') }}">Product Bundles</a></li><li class="breadcrumb-item active">{{ $bundle->name }}</li></ol></div></div>
@stop
@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    <div class="d-flex justify-content-end mb-3">
        <a href="{{ route('admin.product-bundles.edit', $bundle) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i> Edit</a>
    </div>
    <x-adminlte-card icon="bi bi-info-circle" title="Details">
        <table class="table table-sm table-borderless mb-0">
            <tbody>
                <tr><th class="text-muted w-25">Name</th><td>{{ $bundle->name }}</td></tr>
                <tr><th class="text-muted">Type</th><td><span class="badge bg-info">Bundle</span></td></tr>
                <tr><th class="text-muted">Status</th><td><span class="badge bg-{{ $bundle->status === 'active' ? 'success' : 'secondary' }}">{{ ucfirst($bundle->status) }}</span></td></tr>
            </tbody>
        </table>
    </x-adminlte-card>
    <x-adminlte-card icon="bi bi-diagram-3" title="Components">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>#</th><th>Component</th><th>Qty</th><th>Discount</th><th class="text-end">Value</th></tr></thead>
                <tbody>
                    @forelse ($bundle->bundleChildren as $i => $row)
                        <tr>
                            <td>{{ $i + 1 }}</td>
                            <td><a href="{{ route('admin.products.show', $row->component) }}"><strong>{{ $row->component->name }}</strong></a></td>
                            <td>{{ $row->quantity }}</td>
                            <td><span class="badge bg-secondary">{{ ucfirst($row->discount_type) }}</span></td>
                            <td class="text-end">
                                @if ((float) $row->discount_value > 0)
                                    {{ $row->discount_type === 'percent' ? $row->discount_value.'%' : '₹'.number_format((float) $row->discount_value, 2) }}
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">No components in this bundle.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-adminlte-card>
@stop
