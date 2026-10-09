@extends('adminlte::page')

@section('title', 'Domain Logs')

@section('content_header')
    <x-ui.page-header title="Domain Logs" subtitle="Registrar sync operations and availability searches" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Domain Logs','active' => true]]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

    <div class="card">
        <div class="card-header p-0 border-bottom-0">
            <ul class="nav nav-tabs px-3 pt-2" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active d-inline-flex align-items-center gap-2"
                            id="tab-sync-btn" data-bs-toggle="tab" data-bs-target="#tab-sync"
                            type="button" role="tab" aria-controls="tab-sync" aria-selected="true">
                        <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Sync Operations
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link d-inline-flex align-items-center gap-2"
                            id="tab-search-btn" data-bs-toggle="tab" data-bs-target="#tab-search"
                            type="button" role="tab" aria-controls="tab-search" aria-selected="false">
                        <i class="bi bi-search" aria-hidden="true"></i> Availability Searches
                    </button>
                </li>
            </ul>
        </div>
        <div class="card-body p-0">
            <div class="tab-content">
                <div class="tab-pane fade show active" id="tab-sync" role="tabpanel" aria-labelledby="tab-sync-btn">
                    <form method="GET" action="{{ url()->current() }}" class="grid-toolbar p-3 border-bottom">
                        <div class="row g-2 align-items-center">
                            <div class="col-md-4">
                                <label for="filter-provider" class="visually-hidden">Provider</label>
                                <select id="filter-provider" name="provider" class="form-select grid-toolbar-status" aria-label="Filter by provider">
                                    <option value="">All providers</option>
                                    @foreach ($providers as $value => $label)
                                        <option value="{{ $value }}" @selected($provider === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="filter-status" class="visually-hidden">Status</label>
                                <select id="filter-status" name="status" class="form-select grid-toolbar-status" aria-label="Filter by status">
                                    <option value="">All statuses</option>
                                    @foreach ($statuses as $value => $label)
                                        <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4 d-flex gap-2 align-items-center grid-toolbar-actions">
                                <button type="submit" class="btn btn-outline-secondary btn-sm">Filter</button>
                                <a href="{{ url()->current() }}?reset=1" class="btn btn-outline-secondary btn-sm">Reset</a>
                            </div>
                        </div>
                    </form>
                    <div class="table-responsive">
                        <table class="table table-grid align-middle m-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Provider</th>
                                    <th>Operation</th>
                                    <th>Status</th>
                                    <th>Error</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($syncLogs as $log)
                                    <tr>
                                        <td class="text-muted small text-nowrap">{{ $log->created_at?->format('M j, H:i') }}</td>
                                        <td>{{ $log->provider }}</td>
                                        <td>{{ $log->operation }}</td>
                                        <td><x-adminlte.partials.status-badge :status="$log->status" /></td>
                                        <td class="text-muted small" @if ($log->error) title="{{ $log->error }}" @endif>{{ $log->error ? Str::limit($log->error, 60) : '—' }}</td>
                                    </tr>
                                @empty
                                    <x-ui.empty-table-row colSpan="5" title="No sync operations." />
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="grid-pagination d-flex align-items-center justify-content-between flex-wrap gap-2 p-3">
                        {{ $syncLogs->links() }}
                    </div>
                </div>

                <div class="tab-pane fade" id="tab-search" role="tabpanel" aria-labelledby="tab-search-btn">
                    <div class="table-responsive">
                        <table class="table table-grid align-middle m-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Domain</th>
                                    <th>Customer</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($searchLogs as $log)
                                    <tr>
                                        <td class="text-muted small text-nowrap">{{ $log->created_at?->format('M j, H:i') }}</td>
                                        <td>{{ $log->domain_name }}</td>
                                        <td class="text-muted small">{{ $log->customer_id ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <x-ui.empty-table-row colSpan="3" title="No availability searches." />
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="grid-pagination d-flex align-items-center justify-content-between flex-wrap gap-2 p-3">
                        {{ $searchLogs->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
@stop
