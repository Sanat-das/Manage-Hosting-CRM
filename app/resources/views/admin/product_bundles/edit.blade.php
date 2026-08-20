@extends('adminlte::page')
@section('title', 'Edit Product Bundle — '.$bundle->name)
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Edit: {{ $bundle->name }}</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.product-bundles.index') }}">Product Bundles</a></li><li class="breadcrumb-item"><a href="{{ route('admin.product-bundles.show', $bundle) }}">{{ $bundle->name }}</a></li><li class="breadcrumb-item active">Edit</li></ol></div></div>
@stop
@section('content')
    @if ($errors->any()) <x-adminlte-alert theme="danger" dismissible><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-adminlte-alert> @endif
    <form method="POST" action="{{ route('admin.product-bundles.update', $bundle) }}">
        @csrf
        @method('PUT')
        <x-adminlte-card icon="bi bi-box-seam" title="Bundle Details">
            <p class="mb-0">Components of <strong>{{ $bundle->name }}</strong> ({{ $bundle->bundleChildren->count() }} row(s)).</p>
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
            <a href="{{ route('admin.product-bundles.show', $bundle) }}" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary ms-2"><i class="bi bi-check-circle me-1"></i> Update Bundle</button>
        </div>
    </form>
@stop

@push('js')
@php
    // json_encode here (not the @json directive): Blade's @json compiler
    // explode(',')s its argument, which chokes on the comma-separated array
    // literals inside the arrow functions (ParseError at compile time).
    $optionsJson = json_encode($products->map(fn ($p) => ['id' => $p->id, 'label' => $p->name])->values());
    $existingJson = json_encode($bundle->bundleChildren->map(fn ($r) => [
        'component_product_id' => $r->component_product_id,
        'quantity' => $r->quantity,
        'discount_type' => $r->discount_type,
        'discount_value' => (string) $r->discount_value,
        'sort_order' => $r->sort_order,
    ])->values());
@endphp
<script>
(function () {
    const options = {!! $optionsJson !!};
    const existing = {!! $existingJson !!};
    const tbody = document.getElementById('component-table')?.querySelector('tbody');

    function rowHtml(index, row = {}) {
        const opts = ['<option value="">— Select component —</option>']
            .concat(options.map(o => `<option value="${o.id}" ${String(o.id) === String(row.component_product_id) ? 'selected' : ''}>${o.label}</option>`)).join('');
        return `<tr>
            <td><select name="components[${index}][component_product_id]" class="form-control form-control-sm" required>${opts}</select></td>
            <td><input type="number" name="components[${index}][quantity]" class="form-control form-control-sm" min="1" value="${row.quantity ?? 1}" required></td>
            <td><select name="components[${index}][discount_type]" class="form-control form-control-sm">
                <option value="percent" ${row.discount_type === 'percent' ? 'selected' : ''}>Percent</option>
                <option value="fixed" ${row.discount_type === 'fixed' ? 'selected' : ''}>Fixed</option></select></td>
            <td><input type="number" step="0.01" min="0" name="components[${index}][discount_value]" class="form-control form-control-sm" value="${row.discount_value ?? '0'}" required></td>
            <td><input type="number" min="0" name="components[${index}][sort_order]" class="form-control form-control-sm" value="${row.sort_order ?? 0}"></td>
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
        existing.forEach((row, i) => tbody.insertAdjacentHTML('beforeend', rowHtml(i, row)));
    }
})();
</script>
@endpush
