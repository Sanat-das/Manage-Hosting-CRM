@extends('adminlte::page')

@section('title', 'SNMP Monitor')

@section('content_header')
    <div class="row align-items-center">
        <div class="col-sm-6">
            <h1 class="m-0">SNMP Monitor</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/admin') }}">Home</a></li>
                <li class="breadcrumb-item active" aria-current="page">SNMP Monitor</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <div class="row mb-4" id="snmp-stat-cards">
        <div class="col-6 col-lg mb-3">
            <div class="card text-center h-100">
                <div class="card-body py-3">
                    <div class="h4 mb-0" id="snmp-stat-total">{{ $stats['total'] }}</div>
                    <div class="text-muted small">Total hosts</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg mb-3">
            <div class="card text-center h-100 border-success">
                <div class="card-body py-3">
                    <div class="h4 mb-0 text-success" id="snmp-stat-up">{{ $stats['up'] }}</div>
                    <div class="text-muted small">Up</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg mb-3">
            <div class="card text-center h-100 border-danger">
                <div class="card-body py-3">
                    <div class="h4 mb-0 text-danger" id="snmp-stat-down">{{ $stats['down'] }}</div>
                    <div class="text-muted small">Down</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg mb-3">
            <div class="card text-center h-100">
                <div class="card-body py-3">
                    <div class="h4 mb-0 text-secondary" id="snmp-stat-unknown">{{ $stats['unknown'] }}</div>
                    <div class="text-muted small">Unknown</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg mb-3">
            <div class="card text-center h-100 border-warning">
                <div class="card-body py-3">
                    <div class="h4 mb-0 text-warning" id="snmp-stat-failing">{{ $stats['failing'] }}</div>
                    <div class="text-muted small">Failing polls</div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end align-items-center gap-2 mb-2 small text-muted">
        <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" id="snmp-auto-refresh" checked>
            <label class="form-check-label" for="snmp-auto-refresh">Auto-refresh (30s)</label>
        </div>
        <span id="snmp-last-updated"></span>
    </div>

    <div class="card card-outline card-primary mb-4">
        <div class="card-header">
            <h3 class="card-title"><i class="bi bi-funnel me-1"></i>Filters</h3>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('admin.snmp-monitor.dashboard') }}" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label" for="snmp-filter-account">Account</label>
                    <select id="snmp-filter-account" name="account" class="form-select">
                        <option value="">All accounts</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}" @selected((string) ($filters['account'] ?? '') === (string) $account->id)>
                                {{ $account->username }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="snmp-filter-status">Status</label>
                    <select id="snmp-filter-status" name="status" class="form-select">
                        <option value="">Any status</option>
                        @foreach (['up' => 'Up', 'down' => 'Down', 'unknown' => 'Unknown'] as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="snmp-filter-q">Hostname</label>
                    <input id="snmp-filter-q" type="text" name="q" value="{{ $filters['q'] ?? '' }}"
                           class="form-control" placeholder="Search host or username&hellip;" autocomplete="off">
                </div>
                @php
                    $thresholdOptions = ['' => 'Any', '50' => '&ge; 50%', '70' => '&ge; 70%', '85' => '&ge; 85%', '90' => '&ge; 90%'];
                @endphp
                <div class="col-md-3">
                    <label class="form-label" for="snmp-filter-cpu-min">CPU usage</label>
                    <select id="snmp-filter-cpu-min" name="cpu_min" class="form-select">
                        @foreach ($thresholdOptions as $value => $label)
                            <option value="{{ $value }}" @selected((string) ($filters['cpu_min'] ?? '') === $value)>{!! $label !!}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="snmp-filter-mem-min">RAM usage</label>
                    <select id="snmp-filter-mem-min" name="mem_min" class="form-select">
                        @foreach ($thresholdOptions as $value => $label)
                            <option value="{{ $value }}" @selected((string) ($filters['mem_min'] ?? '') === $value)>{!! $label !!}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="snmp-filter-disk-min">Disk usage</label>
                    <select id="snmp-filter-disk-min" name="disk_min" class="form-select">
                        @foreach ($thresholdOptions as $value => $label)
                            <option value="{{ $value }}" @selected((string) ($filters['disk_min'] ?? '') === $value)>{!! $label !!}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Apply</button>
                    <a href="{{ route('admin.snmp-monitor.dashboard') }}" class="btn btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Monitored hosts ({{ $total }})</h3>
        </div>
        <div class="card-body table-responsive p-0">
            @forelse ($rows as $row)
                @if ($loop->first)
                    {{-- Standard AdminLTE table classes. Width is kept in
                         check structurally — one fewer column and shorter
                         headers — rather than by shrinking the type, which
                         would drift from every other listing in the admin. --}}
                    <table class="table table-striped table-hover align-middle mb-0">
                        <thead>
                            {{-- Explicit widths on the fixed-size columns. Auto-layout
                                 sizes a column to its HEADER, so "Actions" (short) was
                                 allotted 96px while its buttons need ~115px, pushing the
                                 table past the card and hiding Delete behind a scrollbar.
                                 Host and IP are left fluid to absorb the remainder. --}}
                            <tr class="text-nowrap">
                                <th>Host</th>
                                <th>IP</th>
                                <th>Status</th>
                                <th style="width:90px;">Last poll</th>
                                <th style="width:110px;">CPU</th>
                                <th style="width:110px;">RAM</th>
                                <th style="width:110px;">Disk</th>
                                <th style="width:110px;">Trend</th>
                                <th class="text-end" style="width:100px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                @endif
                            @php
                                $target = $row['t'];
                                $pollBadges = ['up' => 'success', 'down' => 'danger', 'unknown' => 'secondary'];
                                // hosting_accounts.host_name — the product's identifier
                                // across the application, exactly as the hosting account
                                // page shows it. Deliberately NOT the sysName the agent
                                // reports: that only exists after a successful poll, so an
                                // unreachable host would have no name at all.
                                //
                                // The column is null on accounts predating the field, and
                                // this row came from raw SQL so the model accessor never
                                // ran — apply its fallback explicitly.
                                $displayHost = trim((string) ($target->account_host_name ?? '')) !== ''
                                    ? $target->account_host_name
                                    : \App\Models\HostingAccount::fallbackHostName($target->hosting_account_id);
                            @endphp
                            <tr data-account-id="{{ $target->hosting_account_id }}">
                                <td class="text-nowrap">
                                    <span class="fw-semibold">{{ $displayHost }}</span>
                                    {{-- OS folded in as a badge rather than its own column;
                                         it is one short word and was costing ~90px. --}}
                                    <span class="badge text-bg-light ms-1">{{ strtoupper($target->target_os ?? '') }}</span>
                                </td>
                                <td class="font-monospace text-nowrap small">{{ $target->host ?? '—' }}</td>
                                <td class="text-nowrap">
                                    <span class="badge text-bg-{{ $pollBadges[$target->status] ?? 'secondary' }} snmp-row-status">
                                        {{ ucfirst($target->status ?? 'unknown') }}
                                    </span>
                                    <span class="badge text-bg-warning snmp-row-fails {{ (int) ($target->consecutive_failures ?? 0) > 0 ? '' : 'd-none' }}">
                                        {{ $target->consecutive_failures }} fails
                                    </span>
                                </td>
                                <td class="snmp-row-last-poll text-nowrap">
                                    @if ($target->last_polled_at)
                                        {{-- Short form ("3m ago"): the long form wrapped to
                                             two lines and stretched every row. Must match
                                             DashboardController::refresh(), which rewrites
                                             this cell on the auto-refresh tick. --}}
                                        {{ \Illuminate\Support\Carbon::parse($target->last_polled_at)->diffForHumans(short: true) }}
                                    @else
                                        &mdash;
                                    @endif
                                </td>
                                @php $gauges = $metrics[$target->id] ?? []; @endphp
                                <td class="snmp-row-cpu">
                                    @include('snmp-monitor::partials.gauge', ['value' => $gauges['cpu_pct'] ?? null])
                                </td>
                                <td class="snmp-row-mem">
                                    @include('snmp-monitor::partials.gauge', ['value' => $gauges['mem_pct'] ?? null])
                                </td>
                                <td class="snmp-row-disk">
                                    @include('snmp-monitor::partials.gauge', ['value' => $gauges['disk_pct'] ?? null])
                                </td>
                                <td>
                                    @include('snmp-monitor::partials.sparkline', ['points' => $sparklines[$target->id] ?? []])
                                </td>
                                <td class="text-end" style="white-space:nowrap;">
                                    {{-- Icon-only: the "Charts" label made this cell ~115px
                                         against a 96px column, and under table-layout:auto a
                                         width hint on the th is advisory — the table simply
                                         overflowed and hid Delete behind a scrollbar. --}}
                                    <a href="{{ route('admin.snmp-monitor.host.show', $target->hosting_account_id) }}"
                                       class="btn btn-sm btn-outline-primary"
                                       title="Charts and full inspection"
                                       aria-label="Charts and full inspection">
                                        <i class="bi bi-graph-up"></i>
                                    </a>
                                    @can('hosting.manage')
                        <button type="button" class="btn btn-sm btn-outline-danger"
                                title="Remove this target and all of its collected samples"
                                aria-label="Remove this target and all of its collected samples"
                                data-bs-toggle="modal" data-bs-target="#delete-snmp-target-{{ $target->hosting_account_id }}">
                                <i class="bi bi-trash"></i>
                            </button>
                                    @endcan
                                </td>
                            </tr>
                @if ($loop->last)
                        </tbody>
                    </table>
                @endif
            @empty
                <div class="p-5 text-center text-muted">
                    <i class="bi bi-activity fs-1 d-block mb-2"></i>
                    No monitored hosts match the current filters.
                </div>
            @endforelse
        </div>

        @if ($total > count($rows))
            <div class="card-footer clearfix">
                @php
                    $page = (int) request()->query('page', '1');
                    $pages = (int) ceil($total / max(1, count($rows) ?: 1));
                    $linkFor = function (int $p): string {
                        return route('admin.snmp-monitor.dashboard', array_filter([
                            'account' => $filters['account'] ?? null,
                            'status' => $filters['status'] ?? null,
                            'q' => $filters['q'] ?? null,
                            'cpu_min' => $filters['cpu_min'] ?? null,
                            'mem_min' => $filters['mem_min'] ?? null,
                            'disk_min' => $filters['disk_min'] ?? null,
                            'page' => $p > 1 ? $p : null,
                        ]));
                    };
                @endphp
                <a href="{{ $linkFor(max(1, $page - 1)) }}" class="btn btn-sm btn-outline-secondary float-start {{ $page <= 1 ? 'disabled' : '' }}">
                    &laquo; Previous
                </a>
                <span class="align-middle">Page {{ min($page, $pages) }} / {{ $pages }}</span>
                <a href="{{ $linkFor(min($pages, $page + 1)) }}" class="btn btn-sm btn-outline-secondary float-end {{ $page >= $pages ? 'disabled' : '' }}">
                    Next &raquo;
                </a>
            </div>
        @endif
    </div>

    @can('hosting.manage')
        @foreach ($rows as $row)
            @php
                $target = $row['t'];
                $displayHost = trim((string) ($target->account_host_name ?? '')) !== ''
                    ? $target->account_host_name
                    : \App\Models\HostingAccount::fallbackHostName($target->hosting_account_id);
            @endphp
            <x-adminlte.partials.confirm-modal
                :id="'delete-snmp-target-' . $target->hosting_account_id"
                title="Delete monitoring"
                :message="'Delete monitoring for ' . $displayHost . ' (' . ($target->host ?? 'no address') . ')? This removes the target and ALL collected samples - it cannot be undone.'"
                :action="route('admin.snmp-monitor.target.destroy', $target->hosting_account_id)"
                confirm-label="Delete monitoring"
            >
                The account still has SNMP enabled on its product, so the target is
                recreated (with no history) the next time someone opens its hosting
                page. To stop monitoring for good, disable the snmp-monitor module
                link on the product.
            </x-adminlte.partials.confirm-modal>
        @endforeach
    @endcan

    <script>
        (function () {
            var refreshUrl = @json(route('admin.snmp-monitor.dashboard.refresh'));
            var badgeClass = { up: 'success', down: 'danger', unknown: 'secondary' };
            var toggle = document.getElementById('snmp-auto-refresh');
            var updatedEl = document.getElementById('snmp-last-updated');
            var timer = null;

            function applyBadge(el, status) {
                var color = badgeClass[status] || 'secondary';
                el.className = el.className.replace(/\bbadge-\S+|\btext-bg-\S+/g, '').trim();
                el.classList.add('badge', 'text-bg-' + color);
                el.textContent = status ? status.charAt(0).toUpperCase() + status.slice(1) : 'Unknown';
            }

            function gaugeLevel(value) {
                if (value === null || value === undefined) return 'secondary';
                return value < 70 ? 'success' : (value <= 85 ? 'warning' : 'danger');
            }

            function renderGauge(cell, value) {
                if (!cell) return;

                if (value === null || value === undefined) {
                    cell.innerHTML = '<span class="text-muted small">—</span>';
                    return;
                }

                var level = gaugeLevel(value);
                var pct = Math.min(100, Math.max(0, value));
                cell.innerHTML = '<div class="d-flex align-items-center gap-1" style="min-width:80px;">'
                    + '<div class="progress flex-grow-1" style="height:6px;">'
                    + '<div class="progress-bar text-bg-' + level + '" style="width:' + pct + '%;"></div>'
                    + '</div>'
                    + '<span class="small text-' + level + ' fw-semibold" style="width:3.4em;text-align:right;">' + Math.round(value) + '%</span>'
                    + '</div>';
            }

            function refresh() {
                var url = new URL(refreshUrl, window.location.origin);
                url.search = window.location.search;

                fetch(url, { headers: { Accept: 'application/json' } })
                    .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
                    .then(function (data) {
                        document.getElementById('snmp-stat-total').textContent = data.stats.total;
                        document.getElementById('snmp-stat-up').textContent = data.stats.up;
                        document.getElementById('snmp-stat-down').textContent = data.stats.down;
                        document.getElementById('snmp-stat-unknown').textContent = data.stats.unknown;
                        document.getElementById('snmp-stat-failing').textContent = data.stats.failing;

                        data.rows.forEach(function (row) {
                            var tr = document.querySelector('tr[data-account-id="' + row.account_id + '"]');
                            if (!tr) return;

                            var statusBadge = tr.querySelector('.snmp-row-status');
                            if (statusBadge) applyBadge(statusBadge, row.status);

                            var failBadge = tr.querySelector('.snmp-row-fails');
                            if (failBadge) {
                                if (row.consecutive_failures > 0) {
                                    failBadge.textContent = row.consecutive_failures + ' fails';
                                    failBadge.classList.remove('d-none');
                                } else {
                                    failBadge.classList.add('d-none');
                                }
                            }

                            var lastPoll = tr.querySelector('.snmp-row-last-poll');
                            if (lastPoll) lastPoll.textContent = row.last_polled_human || '—';

                            renderGauge(tr.querySelector('.snmp-row-cpu'), row.cpu_pct);
                            renderGauge(tr.querySelector('.snmp-row-mem'), row.mem_pct);
                            renderGauge(tr.querySelector('.snmp-row-disk'), row.disk_pct);
                        });

                        updatedEl.textContent = 'Updated ' + new Date().toLocaleTimeString();
                    })
                    .catch(function () {});
            }

            function schedule() {
                clearInterval(timer);
                if (toggle.checked && document.visibilityState === 'visible') {
                    timer = setInterval(refresh, 30000);
                }
            }

            if (toggle) {
                toggle.addEventListener('change', schedule);
                document.addEventListener('visibilitychange', schedule);
                schedule();
            }
        })();
    </script>
@stop
