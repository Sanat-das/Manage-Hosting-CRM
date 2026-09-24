@extends('adminlte::page')
@section('title', 'Search Results')
@section('content_header')
    <x-ui.page-header title="Search Results" subtitle="Find records across the platform" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Search Results','active' => true]]" />
@stop
@section('content')
    <x-adminlte-card icon="bi bi-search" title="Search">
        <form method="GET" action="{{ route('admin.search.index') }}" role="search" data-search-form>
            <div class="input-group">
                <input type="search" name="q" class="form-control"
                       placeholder="Search customers, services, invoices, tickets, products..."
                       value="{{ $q }}" aria-label="Search records" autofocus>
                <button type="submit" class="btn btn-primary" aria-label="Search">
                    <i class="bi bi-search" aria-hidden="true"></i>
                </button>
            </div>
        </form>
    </x-adminlte-card>

    @if (mb_strlen($q) >= 2)
        {{-- Shown while the form navigates; progressive enhancement only. --}}
        <div class="d-none" data-search-loading aria-hidden="true">
            <x-adminlte-card title="Searching…">
                <x-adminlte.partials.loading-skeleton variant="list" :rows="3" />
            </x-adminlte-card>
        </div>

        @if ($groups === [])
            <x-adminlte-card>
                <x-adminlte.partials.empty-state
                    icon="bi bi-search"
                    title="No results found for &ldquo;{{ $q }}&rdquo;"
                    message="Try a different spelling, a customer email, an invoice number, or a domain name." />
            </x-adminlte-card>
        @else
            @foreach ($groups as $group)
                @php
                    $shown = count($group['results']);
                    $countLabel = $shown.($group['has_more'] ? '+' : '');
                @endphp
                <x-adminlte-card :icon="$group['icon']" :title="$group['label'].' ('.$countLabel.')'">
                    @if ($group['list_url'] !== null)
                        <x-slot name="tools">
                            <a href="{{ $group['list_url'] }}"
                               class="btn btn-sm btn-outline-secondary text-nowrap"
                               aria-label="View all {{ $group['label'] }} results">
                                View all {{ $countLabel }}
                            </a>
                        </x-slot>
                    @endif

                    <ul class="list-group list-group-flush" aria-label="{{ $group['label'] }} results">
                        @foreach ($group['results'] as $result)
                            <li class="list-group-item d-flex justify-content-between align-items-center gap-2" data-search-result>
                                <span class="text-truncate">
                                    <a href="{{ $result['url'] }}" class="table-link">
                                        <strong>{{ $result['label'] }}</strong>
                                    </a>
                                    @if ($result['subtitle'] !== null && $result['subtitle'] !== '')
                                        <span class="text-muted">&mdash; {{ $result['subtitle'] }}</span>
                                    @endif
                                </span>
                                <i class="bi bi-arrow-right-short text-muted" aria-hidden="true"></i>
                            </li>
                        @endforeach
                    </ul>
                </x-adminlte-card>
            @endforeach
        @endif
    @endif
@stop

@push('js')
<script>
    // Progressive enhancement: reveal the shared skeleton while the browser
    // navigates to the next query. Without JS the form still submits normally.
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('[data-search-form]');
        var loading = document.querySelector('[data-search-loading]');
        if (!form || !loading) return;
        form.addEventListener('submit', function () {
            loading.classList.remove('d-none');
            loading.removeAttribute('aria-hidden');
        });
    });
</script>
@endpush
