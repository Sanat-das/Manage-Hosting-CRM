@extends('adminlte::page')

@section('title', 'Product Hosted-On Report')

@section('content_header')
    <x-ui.page-header title="Product Hosted-On" subtitle="View hosted-on product relationships" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Asset Relationships', 'url' => route('admin.asset-relationships.index')],
        ['label' => 'Product Hosted-On', 'active' => true],
    ]" />
@stop

@section('content')
    <x-adminlte-card icon="bi bi-diagram-3" :title="'Hosted-on parents — ' . $product->name" bodyClass="p-0">
        <x-slot name="tools">
            <a href="{{ route('admin.product-hosting-tree.index', ['product_id' => $product->id, 'csv' => 1]) }}"
               class="btn btn-sm btn-outline-success">
                <i class="bi bi-download me-1"></i> Export CSV
            </a>
        </x-slot>
        <div class="table-responsive">
            <table class="table table-grid table-striped align-middle m-0"
                   data-grid-resizable
                   data-grid-key="admin.product-hosted-on.index">
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
                                <span class="badge text-bg-secondary">{{ \App\Models\AssetRelationship::ASSET_KINDS[$relationship->parent_kind] ?? $relationship->parent_kind }}</span>
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
