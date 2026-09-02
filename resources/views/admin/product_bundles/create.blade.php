@extends('adminlte::page')
@section('title', 'Add Product Bundle')
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Add Product Bundle</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.product-bundles.index') }}">Product Bundles</a></li><li class="breadcrumb-item active">Add</li></ol></div></div>
@stop
@section('content')
    @if ($errors->any()) <x-adminlte-alert theme="danger" dismissible><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-adminlte-alert> @endif
    <form method="POST" action="{{ route('admin.product-bundles.store') }}">
        @csrf
        <x-adminlte-card icon="bi bi-box-seam" title="Bundle Details">
            <div class="row">
                <div class="col-md-6">
                    <x-adminlte-select name="bundle_product_id" label="Bundle Product" required>
                        <option value="">— Select a bundle product —</option>
                        @foreach ($bundles as $bundle)
                            <option value="{{ $bundle->id }}" @selected(old('bundle_product_id') == $bundle->id)>{{ $bundle->name }}</option>
                        @endforeach
                    </x-adminlte-select>
                    <p class="text-muted small mb-0">Create the bundle product first under Products with the “Bundle” flag enabled.</p>
                </div>
            </div>
        </x-adminlte-card>

        <x-adminlte-card icon="bi bi-diagram-3" title="Component Rows">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="component-table">
                    <thead><tr><th>Component</th><th style="width:90px">Qty</th><th style="width:140px">Discount</th><th style="width:120px">Value</th><th style="width:80px">Sort</th><th style="width:40px"></th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="add-row"><i class="bi bi-plus-lg me-1"></i> Add Component</button>
        </x-adminlte-card>

        <div class="text-end mb-3">
            <a href="{{ route('admin.product-bundles.index') }}" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary ms-2"><i class="bi bi-check-circle me-1"></i> Save Bundle</button>
        </div>
    </form>
@stop

@push('js')
@php
    // json_encode here (not the @json directive): Blade's @json compiler
    // explode(',')s its argument, which chokes on the comma-separated array
    // literal inside the arrow function (ParseError at compile time).
    $optionsJson = json_encode($products->map(fn ($p) => ['id' => $p->id, 'label' => $p->name])->values());
@endphp
<script>
(function () {
    const options = {!! $optionsJson !!};
    const tbody = document.getElementById('component-table')?.querySelector('tbody');

    function rowHtml(index) {
        const opts = ['<option value="">— Select component —</option>']
            .concat(options.map(o => `<option value="${o.id}">${o.label}</option>`)).join('');
        return `<tr>
            <td><select name="components[${index}][component_product_id]" class="form-control form-control-sm" required>${opts}</select></td>
            <td><input type="number" name="components[${index}][quantity]" class="form-control form-control-sm" min="1" value="1" required></td>
            <td><select name="components[${index}][discount_type]" class="form-control form-control-sm">
                <option value="percent">Percent</option>
                <option value="fixed">Fixed</option></select></td>
            <td><input type="number" step="0.01" min="0" name="components[${index}][discount_value]" class="form-control form-control-sm" value="0" required></td>
            <td><input type="number" min="0" name="components[${index}][sort_order]" class="form-control form-control-sm" value="0"></td>
            <td><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="bi bi-trash"></i></button></td>
        </tr>`;
    }

    if (tbody) {
        document.getElementById('add-row')?.addEventListener('click', () => {
            tbody.insertAdjacentHTML('beforeend', rowHtml(tbody.children.length));
        });
        tbody.addEventListener('click', (e) => {
            if (e.target.closest('.remove-row')) e.target.closest('tr').remove();
        });
        tbody.insertAdjacentHTML('beforeend', rowHtml(0));
    }
})();
</script>
@endpush
