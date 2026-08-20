@props([
    'title' => 'Records',
    'icon' => null,
    'searchPlaceholder' => 'Search...',
    'searchValue' => '',
    'statusField' => 'status',
    'statusOptions' => [],
    'statusValue' => '',
    'columns' => [],
    'pagination' => null,
    'actionUrl' => null,
])

@php $actionUrl = $actionUrl ?: url()->current(); @endphp

<x-adminlte-card :icon="$icon" :title="$title" bodyClass="p-0">
    @isset($tools)
        <x-slot name="tools">{{ $tools }}</x-slot>
    @endisset

    {{-- Search + optional status filter (mirrors the reference DataGrid filters) --}}
    <form method="GET" action="{{ $actionUrl }}" class="p-3 border-bottom">
        <div class="row g-2 align-items-center">
            <div class="col-md-{{ $statusOptions ? 5 : 8 }}">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                    <input type="text" name="search" value="{{ $searchValue }}"
                           class="form-control" placeholder="{{ $searchPlaceholder }}">
                </div>
            </div>
            @if ($statusOptions)
                <div class="col-md-3">
                    <select name="{{ $statusField }}" class="form-select">
                        <option value="">All statuses</option>
                        @foreach ($statusOptions as $value => $label)
                            <option value="{{ $value }}" @selected($statusValue === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="col-md-4">
                <button type="submit" class="btn btn-outline-secondary">Filter</button>
                <a href="{{ $actionUrl }}" class="btn btn-outline-secondary">Reset</a>
            </div>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-striped align-middle m-0">
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th @isset($column['class']) class="{{ $column['class'] }}" @endisset>
                            {{ $column['label'] }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>{{ $slot }}</tbody>
        </table>
    </div>

    @isset($pagination)
        <x-slot name="footer">
            @if ($pagination instanceof \Illuminate\Contracts\Pagination\Paginator)
                {{ $pagination->links() }}
            @else
                {!! $pagination !!}
            @endif
        </x-slot>
    @endisset
</x-adminlte-card>
