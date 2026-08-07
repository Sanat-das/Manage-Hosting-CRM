@extends('adminlte::page')

@section('title', 'Product Hosted-On Report')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Product Hosted-On</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.asset-relationships.index') }}">Asset Relationships</a></li>
                <li class="breadcrumb-item active" aria-current="page">Product Hosted-On</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <x-adminlte-card icon="bi bi-diagram-3" :title="'Hosted-on parents — ' . $product->name">
        <div class="text-end mb-3">
            <a href="{{ route('admin.product-hosting-tree.index', ['product_id' => $product->id, 'csv' => 1]) }}"
               class="btn btn-sm btn-outline-success">
                <i class="bi bi-download me-1"></i> Export CSV
            </a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Parent Kind</th>
                        <th>Parent Name</th>
                        <th>Relationship Type</th>
                        <th>Label</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($relationships as $relationship)
                        <tr>
                            <td>
                                <span class="badge bg-secondary">{{ \App\Models\AssetRelationship::ASSET_KINDS[$relationship->parent_kind] ?? $relationship->parent_kind }}</span>
                                <code>#{{ $relationship->parent_id }}</code>
                            </td>
                            <td><strong>{{ $parentNames[$relationship->id] ?? '—' }}</strong></td>
                            <td><code>{{ $relationship->relationship_type }}</code></td>
                            <td class="text-muted">{{ $relationship->label ?? '—' }}</td>
                            <td class="text-muted">{{ $relationship->notes ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">No hosted-on relationships found for this product.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-adminlte-card>
@stop
