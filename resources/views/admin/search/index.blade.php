@extends('adminlte::page')
@section('title', 'Search Results')
@section('content_header')
    <x-ui.page-header title="Search Results" subtitle="Find records across the platform" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Search Results','active' => true]]" />
@stop
@section('content')
    @php
        // Announcement text for the live region below. The page is server
        // rendered, so the count is computed here from the rows the service
        // already fetched (no extra query, no COUNT) and the inline script
        // re-applies it after load so screen readers announce this result set.
        $totalRows = 0;
        foreach ($groups as $group) {
            $totalRows += count($group['results']);
        }
        $groupCount = count($groups);
        if (mb_strlen($q) < 2) {
            $announceText = '';
        } elseif ($groups === []) {
            $announceText = 'No results for '.$q;
        } else {
            $announceText = $totalRows.' '.($totalRows === 1 ? 'result' : 'results')
                .' in '.$groupCount.' '.($groupCount === 1 ? 'group' : 'groups').' for '.$q;
        }
    @endphp

    {{-- Results-page styling. Tokens only (resources/css/tokens.css): the 8px
         spacing scale, the type scale, radii and motion under 250ms. Emitted
         inline because the layout exposes no @stack('css'); no new stylesheet
         is introduced. --}}
    <style>
        .mh-search__form .input-group { border-radius: var(--radius-md); }
        .mh-search__group .card-title { font-size: var(--text-base); font-weight: var(--font-weight-semibold); }
        .mh-search__results .list-group-item { padding: var(--space-3) var(--space-4); font-size: var(--text-sm);
            transition: background-color var(--duration-base) var(--ease-default); }
        .mh-search__results .list-group-item:hover { background-color: var(--color-bg-subtle); }
        .mh-search__subtitle { color: var(--color-text-muted); font-size: var(--text-sm); }
        .mh-search__state > .card { border-radius: var(--radius-lg); }
        .mh-search__state .card-body { padding: var(--space-5); }
    </style>

    <x-adminlte-card icon="bi bi-search" title="Search" class="mh-search__form">
        <form method="GET" action="{{ route('admin.search.index') }}" role="search" data-search-form>
            <div class="input-group">
                <input type="search" name="q" class="form-control @error('q') is-invalid @enderror"
                       placeholder="Search customers, services, invoices, tickets, products..."
                       value="{{ $q }}" aria-label="Search records" autofocus>
                <button type="submit" class="btn btn-primary" aria-label="Search">
                    <i class="bi bi-search" aria-hidden="true"></i>
                </button>
            </div>
        </form>

        {{-- A malformed or overlong `q` is rejected by SearchPageRequest, which
             redirects back with the error flashed. Without this slot the user
             saw the previous page unchanged and no reason for the redirect. --}}
        @error('q')
            <div class="mh-search__error text-danger small mt-2" role="alert" data-search-error>
                <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>{{ $message }}
            </div>
        @enderror
    </x-adminlte-card>

    {{-- Result-count announcer. Empty when the query is too short to search. --}}
    <p class="visually-hidden mb-0" role="status" aria-live="polite" data-search-announce
       data-announce="{{ $announceText }}"></p>

    @if (mb_strlen($q) >= 2)
        {{-- Shown while the form navigates; progressive enhancement only. --}}
        <div class="d-none mh-search__state mh-search__state--loading" data-search-loading aria-hidden="true">
            <x-adminlte-card title="Searching…">
                <x-adminlte.partials.loading-skeleton variant="list" :rows="3" />
            </x-adminlte-card>
        </div>

        @if ($groups === [])
            <div class="mh-search__state mh-search__state--empty" data-search-empty>
                <x-adminlte-card>
                    <x-adminlte.partials.empty-state
                        icon="bi bi-search"
                        title="No results found for “{{ $q }}”"
                        message="Try a different spelling, a customer email, an invoice number, or a domain name." />
                </x-adminlte-card>
            </div>
        @else
            @foreach ($groups as $group)
                @php
                    $shown = count($group['results']);
                    $countLabel = $shown.($group['has_more'] ? '+' : '');
                @endphp
                <x-adminlte-card :icon="$group['icon']" :title="$group['label'].' ('.$countLabel.')'" class="mh-search__group">
                    @if ($group['list_url'] !== null)
                        <x-slot name="tools">
                            <a href="{{ $group['list_url'] }}"
                               class="btn btn-sm btn-outline-secondary text-nowrap"
                               aria-label="View all {{ $group['label'] }} results">
                                View all {{ $countLabel }}
                            </a>
                        </x-slot>
                    @endif

                    <ul class="list-group list-group-flush mh-search__results" aria-label="{{ $group['label'] }} results">
                        @foreach ($group['results'] as $result)
                            <li class="list-group-item d-flex justify-content-between align-items-center gap-2" data-search-result>
                                <span class="text-truncate">
                                    <a href="{{ $result['url'] }}" class="table-link">
                                        <strong>{{ $result['label'] }}</strong>
                                    </a>
                                    @if ($result['subtitle'] !== null && $result['subtitle'] !== '')
                                        <span class="mh-search__subtitle">&mdash; {{ $result['subtitle'] }}</span>
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
    // navigates to the next query, and announce the result count / the loading
    // state through the live region. Without JS the form still submits normally.
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('[data-search-form]');
        var loading = document.querySelector('[data-search-loading]');
        var announce = document.querySelector('[data-search-announce]');

        if (announce && announce.dataset.announce) {
            // Clear-then-set so the live region registers a change for this
            // navigation instead of staying silent with pre-rendered text.
            announce.textContent = '';
            setTimeout(function () { announce.textContent = announce.dataset.announce; }, 50);
        }

        if (!form || !loading) return;
        form.addEventListener('submit', function () {
            loading.classList.remove('d-none');
            loading.removeAttribute('aria-hidden');
            if (announce) announce.textContent = 'Searching…';
        });
    });
</script>
@endpush
