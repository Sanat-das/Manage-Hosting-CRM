@extends('adminlte::page')

@section('title', 'Product Upgrade Paths')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Product Upgrade Paths</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active">Product Upgrade Paths</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif

    <x-adminlte-card icon="bi bi-arrow-up-right-circle" title="All Upgrade Paths">
        <div class="text-end mb-3">
            <a href="{{ route('admin.product-upgrades.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Upgrade Path</a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>From</th><th></th><th>To</th><th>Enabled</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                    @forelse ($paths as $path)
                        <tr>
                            <td><a href="{{ route('admin.products.show', $path->fromProduct) }}"><strong>{{ $path->fromProduct->name }}</strong></a></td>
                            <td class="text-muted"><i class="bi bi-arrow-right"></i></td>
                            <td><a href="{{ route('admin.products.show', $path->toProduct) }}"><strong>{{ $path->toProduct->name }}</strong></a></td>
                            <td>
                                @if ($path->enabled)
                                    <span class="badge bg-success">Enabled</span>
                                @else
                                    <span class="badge bg-secondary">Disabled</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('admin.product-upgrades.edit', $path) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">No upgrade paths found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $paths->links() }}
    </x-adminlte-card>
@stop
