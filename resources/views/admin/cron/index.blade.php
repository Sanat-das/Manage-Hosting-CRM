@extends('adminlte::page')

@section('title', 'Cron Jobs')

@section('content_header')
    <div class="row align-items-center">
        <div class="col-sm-6"><h1 class="m-0" style="font-size: var(--text-2xl); font-weight: 700; letter-spacing: var(--tracking-tight);">Cron Jobs</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Cron Jobs</li>
            </ol>
        </div>
    </div>
@stop

@php
    // Plain-English map for every job declared in routes/console.php — single
    // source so the table can tell an operator what matters in their language,
    // not just the artisan signature. Keys must match ScheduleInspector keys.
    $cronDetails = [
        'billing:recurring' => [
            'what' => 'Creates renewal invoices for hosting, domains and other recurring services due today',
            'why' => 'Without it, customers are not billed on time and services never renew.',
            'when' => 'Runs daily at 01:00',
            'icon' => 'bi bi-receipt',
        ],
        'domains:expiry-check --days=30' => [
            'what' => 'Checks all domains and flags those expiring within 30 days',
            'why' => 'Lets you renew or notify customers before a domain is lost.',
            'when' => 'Runs daily at 02:00',
            'icon' => 'bi bi-globe',
        ],
        'domains:expiry-check' => [
            'what' => 'Checks domains expiring soon and creates alerts',
            'why' => 'Prevents accidental domain expiry.',
            'when' => 'Runs daily',
            'icon' => 'bi bi-globe',
        ],
        'hosting:usage-sync' => [
            'what' => 'Syncs disk and bandwidth usage from hosting servers',
            'why' => 'Keeps usage graphs, quotas and overage billing accurate.',
            'when' => 'Runs every 6 hours',
            'icon' => 'bi bi-hdd-stack',
        ],
        'app:cleanup --days=90' => [
            'what' => 'Removes temporary files, old logs and stale data older than 90 days',
            'why' => 'Keeps storage lean and compliant with retention policy.',
            'when' => 'Runs weekly',
            'icon' => 'bi bi-trash3',
        ],
        'ssl:check-expiry --days=30' => [
            'what' => 'Checks SSL certificates expiring within 30 days',
            'why' => 'Avoids browser “Not secure” warnings and downtime.',
            'when' => 'Runs daily at 03:00',
            'icon' => 'bi bi-shield-lock',
        ],
        'invoices:overdue-check' => [
            'what' => 'Marks overdue invoices and sends payment reminders',
            'why' => 'Reduces late payments and flags accounts for suspension.',
            'when' => 'Runs daily at 03:30',
            'icon' => 'bi bi-exclamation-triangle',
        ],
        'reports:send-scheduled --days=7' => [
            'what' => 'Sends scheduled weekly reports to admins who opted in',
            'why' => 'Keeps management informed without manual exports.',
            'when' => 'Runs weekly on Monday at 06:00',
            'icon' => 'bi bi-bar-chart',
        ],
        'domains:sync-pricing' => [
            'what' => 'Syncs domain pricing from configured registrars',
            'why' => 'Keeps storefront prices aligned with registry costs.',
            'when' => 'Runs daily at 04:30',
            'icon' => 'bi bi-currency-exchange',
        ],
        'tickets:fetch-mail' => [
            'what' => 'Checks support mailboxes for new customer replies and creates tickets',
            'why' => 'Without it, email replies never become tickets and SLAs slip.',
            'when' => 'Runs every 5 minutes',
            'icon' => 'bi bi-envelope-arrow-down',
        ],
        'snmp-poll-dispatch-due' => [
            'what' => 'Dispatches SNMP polling batches for all monitored hosts and interfaces',
            'why' => 'Feeds bandwidth and health graphs — no dispatch means no monitoring data.',
            'when' => 'Runs every minute',
            'icon' => 'bi bi-broadcast',
        ],
        'snmp-rollup-hourly' => [
            'what' => 'Rolls up per-minute SNMP samples into hourly aggregates',
            'why' => 'Powers long-range charts and keeps detail tables from growing forever.',
            'when' => 'Runs hourly at 5 minutes past the hour',
            'icon' => 'bi bi-graph-up',
        ],
        'snmp:maintain-partitions' => [
            'what' => 'Maintains time-partitioned SNMP tables (creates future, drops old)',
            'why' => 'Keeps the monitoring database fast and bounded in size.',
            'when' => 'Runs daily at 00:10',
            'icon' => 'bi bi-database-gear',
        ],
        'ssh:prune' => [
            'what' => 'Closes stale browser SSH sessions left open after a crash or close',
            'why' => 'Frees session records and prevents orphaned console resources.',
            'when' => 'Runs every 15 minutes',
            'icon' => 'bi bi-terminal-x',
        ],
        'queue-emails-cron' => [
            'what' => 'Sends queued emails — invoices, ticket replies, notifications',
            'why' => 'Without it, emails stay in the queue and customers get nothing.',
            'when' => 'Runs every minute',
            'icon' => 'bi bi-send',
        ],
        'emails-queue-heartbeat' => [
            'what' => 'Health check for the email queue — records at-risk backlog to health file',
            'why' => 'Lets the dashboard warn you when the queue is stuck.',
            'when' => 'Runs every 5 minutes',
            'icon' => 'bi bi-heart-pulse',
        ],
    ];
@endphp

@section('content')
    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show d-flex align-items-start gap-2" role="alert" style="border-radius: var(--radius-md); border-left: 3px solid var(--color-success);">
            <i class="bi bi-check-circle-fill flex-shrink-0" style="font-size: 1rem; margin-top: 0.1rem;" aria-hidden="true"></i>
            <div class="flex-fill" style="font-size: var(--text-sm); line-height: var(--leading-normal);">{{ session('success') }}</div>
            <button type="button" class="btn-close flex-shrink-0" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show d-flex align-items-start gap-2" role="alert" style="border-radius: var(--radius-md); border-left: 3px solid var(--color-danger);">
            <i class="bi bi-exclamation-triangle-fill flex-shrink-0" style="font-size: 1rem; margin-top: 0.1rem;" aria-hidden="true"></i>
            <div class="flex-fill">
                <ul class="mb-0 ps-3" style="font-size: var(--text-sm); line-height: var(--leading-normal);">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
            <button type="button" class="btn-close flex-shrink-0" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    {{-- Scheduler health. Nothing else on this page matters if the OS-level tick is not running. --}}
    @if (! $schedulerHealthy)
        <div class="alert alert-danger d-flex flex-column gap-2" role="alert" style="border-radius: var(--radius-md); border-left: 3px solid var(--color-danger); box-shadow: var(--shadow-sm);">
            <h5 class="alert-heading d-flex align-items-center gap-2 mb-0" style="font-size: var(--text-md); font-weight: 600;">
                <i class="bi bi-exclamation-octagon" aria-hidden="true"></i> The scheduler is not running
            </h5>
            @if ($lastTickAt === null)
                <p class="mb-0" style="font-size: var(--text-sm); line-height: var(--leading-normal);">
                    <code style="background: color-mix(in srgb, var(--color-danger) 10%, var(--bs-body-bg)); padding: 0.15rem 0.35rem; border-radius: var(--radius-sm);">schedule:run</code> has never completed on this installation, so
                    <strong>no scheduled task has ever run</strong>.
                </p>
            @else
                <p class="mb-0" style="font-size: var(--text-sm); line-height: var(--leading-normal);">
                    Last tick was <strong>{{ $lastTickAt->diffForHumans() }}</strong>
                    ({{ $lastTickAt->format('M j, Y H:i:s') }}). Expected every minute; treated as
                    stale after {{ (int) ($staleAfter / 60) }} minutes.
                </p>
            @endif
            <p class="mb-1" style="font-size: var(--text-sm);">Every minute, a cron entry (or Windows Task Scheduler task) must run:</p>
            <pre class="mb-0 p-2 rounded border" style="background: var(--color-bg-muted); color: var(--color-text); border-color: var(--color-border); font-size: var(--text-sm); overflow-wrap: anywhere;"><code>php artisan schedule:run</code></pre>
        </div>
    @else
        <div class="alert alert-success d-flex align-items-center gap-2" role="alert" style="border-radius: var(--radius-md); border-left: 3px solid var(--color-success); box-shadow: var(--shadow-sm);">
            <i class="bi bi-check-circle-fill flex-shrink-0" aria-hidden="true"></i>
            <div style="font-size: var(--text-sm);">Scheduler is ticking — last run {{ $lastTickAt->diffForHumans() }}.</div>
        </div>
    @endif

    @if ($paused)
        <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2" role="alert" style="border-radius: var(--radius-md); border-left: 3px solid var(--color-warning); box-shadow: var(--shadow-sm);">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-pause-circle" aria-hidden="true"></i>
                <span style="font-size: var(--text-sm);"><strong>The scheduler is paused.</strong> Due tasks are being skipped until it is resumed.</span>
            </div>
            @can('cron.manage')
                <form method="POST" action="{{ route('admin.cron.pause') }}" class="d-inline-flex">
                    @csrf
                    <input type="hidden" name="resume" value="1">
                    <button class="btn btn-sm btn-warning d-inline-flex align-items-center gap-1" aria-label="Resume scheduler"><i class="bi bi-play-fill" aria-hidden="true"></i> Resume</button>
                </form>
            @endcan
        </div>
    @endif

    @php
        $totalTasks = $tasks->count();
        $enabledCount = $tasks->where('enabled', true)->count();
        $disabledCount = $totalTasks - $enabledCount;
        $customCount = $tasks->where('is_custom', true)->count();
    @endphp
    {{-- Quick metrics — mirrors invoices/orders pattern with metric-cards --}}
    <x-adminlte.partials.metric-cards :items="[
        ['title' => $totalTasks, 'text' => 'Total tasks', 'icon' => 'bi bi-clock', 'theme' => 'info'],
        ['title' => $enabledCount, 'text' => 'Enabled', 'icon' => 'bi bi-check-circle', 'theme' => 'success'],
        ['title' => $disabledCount, 'text' => 'Disabled', 'icon' => 'bi bi-pause-circle', 'theme' => 'secondary'],
        ['title' => $customCount, 'text' => 'Custom schedule', 'icon' => 'bi bi-pencil-square', 'theme' => 'warning'],
    ]" />

    @php $activeTab = request()->hasAny(['task', 'status']) ? 'history' : 'tasks'; @endphp
    <div class="card">
        <div class="card-header p-0 border-bottom-0">
            <ul class="nav nav-tabs px-3 pt-2" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link {{ $activeTab === 'tasks' ? 'active' : '' }} d-inline-flex align-items-center gap-2"
                            id="tab-tasks-btn" data-bs-toggle="tab" data-bs-target="#tab-tasks"
                            type="button" role="tab" aria-controls="tab-tasks" aria-selected="{{ $activeTab === 'tasks' ? 'true' : 'false' }}">
                        <i class="bi bi-clock" aria-hidden="true"></i> Scheduled Tasks
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link {{ $activeTab === 'history' ? 'active' : '' }} d-inline-flex align-items-center gap-2"
                            id="tab-history-btn" data-bs-toggle="tab" data-bs-target="#tab-history"
                            type="button" role="tab" aria-controls="tab-history" aria-selected="{{ $activeTab === 'history' ? 'true' : 'false' }}">
                        <i class="bi bi-list-ul" aria-hidden="true"></i> Run History
                    </button>
                </li>
            </ul>
        </div>
        <div class="card-body p-0">
            <div class="tab-content">
                <div class="tab-pane fade {{ $activeTab === 'tasks' ? 'show active' : '' }}" id="tab-tasks" role="tabpanel" aria-labelledby="tab-tasks-btn">
        @can('cron.manage')
            @unless ($paused)
                <div class="d-flex flex-wrap align-items-center gap-2 p-3 border-bottom" style="background: var(--color-bg-subtle); gap: var(--space-2);">
                    <button type="button" class="btn btn-sm btn-outline-warning d-inline-flex align-items-center gap-1"
                            data-bs-toggle="modal" data-bs-target="#pause-scheduler-modal" aria-label="Pause scheduler">
                        <i class="bi bi-pause-fill" aria-hidden="true"></i> Pause scheduler
                    </button>
                    <span class="small" style="color: var(--color-text-muted); font-size: var(--text-xs);">Stops every task at once — use for maintenance windows.</span>
                    <span class="ms-auto small" style="color: var(--color-text-faint); font-size: var(--text-xs);">
                        <span id="cron-visible-count">{{ $totalTasks }}</span> of {{ $totalTasks }} shown
                    </span>
                </div>
            @endunless
        @endcan

        {{-- Live toolbar — client-side search + status pills, consistent with datatable filter bar --}}
        <div class="p-3 border-bottom" style="background: var(--color-bg-subtle);">
            <div class="row g-2 align-items-center">
                <div class="col-12 col-md-6 col-lg-5">
                    <label for="cron-task-search" class="visually-hidden">Filter tasks</label>
                    <div class="input-group">
                        <span class="input-group-text" style="background: var(--bs-body-bg);"><i class="bi bi-search" aria-hidden="true"></i></span>
                        <input type="search" id="cron-task-search" class="form-control" placeholder="Filter by task name, key or what it does…" aria-label="Filter by task name, key or what it does" autocomplete="off">
                        <button type="button" class="btn btn-outline-secondary" id="cron-search-clear" aria-label="Clear filter"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-7">
                    <div class="d-flex flex-wrap align-items-center gap-2" role="group" aria-label="Filter by status">
                        <span class="small fw-medium" style="color: var(--color-text-muted); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 0.04em;">Show:</span>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Status filter">
                            <button type="button" class="btn btn-primary active" data-cron-filter="all" aria-pressed="true">All</button>
                            <button type="button" class="btn btn-outline-secondary" data-cron-filter="enabled" aria-pressed="false">Enabled</button>
                            <button type="button" class="btn btn-outline-secondary" data-cron-filter="disabled" aria-pressed="false">Disabled</button>
                            <button type="button" class="btn btn-outline-secondary" data-cron-filter="custom" aria-pressed="false">Custom</button>
                            <button type="button" class="btn btn-outline-secondary" data-cron-filter="background" aria-pressed="false">Background</button>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" id="cron-filter-reset" aria-label="Reset filters">Reset</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-responsive has-sticky" id="cron-tasks-wrapper">
            <table class="table table-grid table-hover align-middle m-0" data-grid-resizable aria-label="Scheduled tasks">
                <thead>
                    <tr>
                        <th style="min-width: 240px;">Task</th>
                        <th style="min-width: 170px;">Schedule</th>
                        <th style="min-width: 120px;">Next Due</th>
                        <th style="min-width: 120px;">Last Run</th>
                        <th style="min-width: 110px;">Status</th>
                        <th class="text-end" style="min-width: 180px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="cron-tasks-body">
                    @forelse ($tasks as $task)
                        @php
                            $plain = $cronDetails[$task['key']] ?? $cronDetails[trim(explode(' ', $task['key'])[0] ?? '')] ?? null;
                            // Fallback: try command signature without args for map lookup (e.g. billing:recurring)
                            if (! $plain && isset($task['command'])) {
                                $base = strtok(trim((string) $task['command']), ' ');
                                $plain = $cronDetails[$base] ?? null;
                            }
                            $bgLabel = $task['background'] ? 'background' : '';
                            $noOverlapLabel = $task['without_overlapping'] ? 'no overlap' : '';
                        @endphp
                        <tr class="{{ $task['enabled'] ? '' : 'table-secondary' }}"
                            data-task-row
                            data-key="{{ Str::lower($task['key']) }}"
                            data-name="{{ Str::lower($task['name']) }}"
                            data-command="{{ Str::lower($task['command']) }}"
                            data-what="{{ Str::lower($plain['what'] ?? '') }}"
                            data-enabled="{{ $task['enabled'] ? '1' : '0' }}"
                            data-custom="{{ !empty($task['is_custom']) ? '1' : '0' }}"
                            data-background="{{ $task['background'] ? '1' : '0' }}"
                            @if(!$task['enabled']) aria-disabled="true" @endif>
                            <td>
                                <div class="d-flex flex-column gap-1">
                                    <div class="d-flex align-items-start gap-2">
                                        @if($plain)
                                            <span class="d-inline-flex align-items-center justify-content-center rounded-2 flex-shrink-0" style="width: 28px; height: 28px; background: color-mix(in srgb, var(--color-primary) 10%, transparent); color: var(--color-primary); font-size: 0.85rem; margin-top: 1px;" aria-hidden="true">
                                                <i class="{{ $plain['icon'] }}"></i>
                                            </span>
                                        @endif
                                        <div class="flex-fill min-w-0">
                                            <div class="fw-semibold text-break d-inline-flex align-items-center gap-1 flex-wrap" style="font-size: var(--text-sm);">
                                                <span>{{ $task['name'] }}</span>
                                                @if($plain)
                                                    <button type="button" class="btn btn-sm p-0 border-0 lh-1"
                                                            data-bs-toggle="tooltip" data-bs-placement="top"
                                                            data-bs-title="{{ $plain['what'] }} — {{ $plain['why'] }}"
                                                            aria-label="What this does: {{ $plain['what'] }}"
                                                            style="color: var(--color-text-faint); font-size: 0.82rem;">
                                                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                                                    </button>
                                                @endif
                                            </div>
                                            @if($plain)
                                                <div class="small" style="color: var(--color-text-muted); font-size: var(--text-sm); line-height: var(--leading-normal);">
                                                    {{ $plain['what'] }}
                                                </div>
                                                <div class="small d-inline-flex align-items-center gap-1" style="color: var(--color-text-faint); font-size: var(--text-xs);">
                                                    <i class="bi bi-clock-history" aria-hidden="true"></i> {{ $plain['when'] }}
                                                    <span class="d-none d-sm-inline" style="opacity: 0.6;">·</span>
                                                    <span class="d-none d-sm-inline">{{ $plain['why'] }}</span>
                                                </div>
                                            @endif
                                            @if ($task['command'] !== $task['name'])
                                                <code class="small d-block text-break mt-1" style="color: var(--color-text-faint); font-size: var(--text-xs); background: var(--color-bg-subtle); padding: 0.15rem 0.35rem; border-radius: var(--radius-sm); border: 1px solid var(--color-border); display: inline-block; max-width: 100%; overflow-wrap: anywhere;">{{ $task['command'] }}</code>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="d-flex flex-wrap align-items-center gap-1 ps-1" style="gap: var(--space-1); padding-left: {{ $plain ? '2.2rem' : '0' }};">
                                        @if ($task['background'])
                                            <span class="badge rounded-pill mh-badge mh-badge--subtle" style="font-size: var(--text-xs); font-weight: 500; letter-spacing: 0.02em; padding: 0.28em 0.62em; background: color-mix(in srgb, var(--color-info) 12%, var(--color-surface)); color: var(--color-info); border: 1px solid color-mix(in srgb, var(--color-info) 18%, transparent); line-height: 1.2;">background</span>
                                        @endif
                                        @if ($task['without_overlapping'])
                                            <span class="badge rounded-pill mh-badge mh-badge--subtle" style="font-size: var(--text-xs); font-weight: 500; letter-spacing: 0.02em; padding: 0.28em 0.62em; background: color-mix(in srgb, var(--color-warning) 14%, var(--color-surface)); color: var(--color-warning); border: 1px solid color-mix(in srgb, var(--color-warning) 18%, transparent); line-height: 1.2;">no overlap</span>
                                        @endif
                                        @if (! empty($task['is_custom']))
                                            <x-adminlte.partials.status-badge status="custom" label="Custom" :map="['custom' => 'warning']" variant="subtle" />
                                        @endif
                                    </div>
                                    @unless ($task['manageable'])
                                        <div class="small d-flex align-items-start gap-1 mt-1" style="color: var(--color-warning); font-size: var(--text-xs);">
                                            <i class="bi bi-exclamation-triangle flex-shrink-0" style="margin-top: 0.15rem;" aria-hidden="true"></i>
                                            <span>Unnamed closure — add <code>-&gt;name('...')</code> in <code>routes/console.php</code> to manage it here.</span>
                                        </div>
                                    @endunless
                                    @if (! empty($task['is_custom']))
                                        <div class="small d-inline-flex align-items-center gap-1 mt-1" style="color: var(--color-text-muted); font-size: var(--text-xs);">
                                            <i class="bi bi-pencil-square" aria-hidden="true"></i> Overridden in DB — leave fields empty to revert to code default.
                                        </div>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <div class="d-flex flex-column gap-1">
                                    <span style="font-size: var(--text-sm); color: var(--color-text);">{{ $task['human'] }}</span>
                                    <code class="small text-break" style="color: var(--color-text-faint); font-size: var(--text-xs); background: var(--color-bg-subtle); padding: 0.12rem 0.32rem; border-radius: var(--radius-sm); border: 1px solid var(--color-border); overflow-wrap: anywhere;">{{ $task['expression'] }}</code>
                                    <span class="small d-inline-flex align-items-center gap-1" style="color: var(--color-text-faint); font-size: var(--text-xs);">
                                        <i class="bi bi-globe2" aria-hidden="true"></i> {{ $task['timezone'] }}
                                    </span>
                                </div>
                            </td>
                            <td class="small" style="font-size: var(--text-sm);">
                                @if ($task['next_due'])
                                    <span class="fw-medium" style="color: var(--color-text);">{{ $task['next_due']->format('M j, H:i') }}</span>
                                    <div style="color: var(--color-text-muted); font-size: var(--text-xs);">{{ $task['next_due']->diffForHumans() }}</div>
                                @else
                                    <span style="color: var(--color-text-faint);">—</span>
                                @endif
                            </td>
                            <td class="small" style="font-size: var(--text-sm);">
                                @if ($task['last_run'])
                                    <span style="color: var(--color-text);">{{ $task['last_run']->started_at?->format('M j, H:i') }}</span>
                                    <div style="color: var(--color-text-muted); font-size: var(--text-xs);">
                                        @if ($task['last_run']->runtime_ms !== null)
                                            {{ number_format($task['last_run']->runtime_ms) }} ms
                                        @else
                                            —
                                        @endif
                                    </div>
                                @else
                                    <span style="color: var(--color-text-faint);">Never</span>
                                @endif
                            </td>
                            <td>
                                @if (! $task['enabled'])
                                    <x-adminlte.partials.status-badge status="disabled" label="Disabled" />
                                @elseif ($task['last_run'])
                                    @php $status = $task['last_run']->status; @endphp
                                    <x-adminlte.partials.status-badge :status="$status" />
                                @else
                                    <x-adminlte.partials.status-badge status="enabled" label="Enabled" variant="subtle" />
                                @endif
                            </td>
                            <td class="text-end">
                                @can('cron.manage')
                                    @if ($task['manageable'])
                                        <div class="d-flex flex-wrap gap-1 justify-content-end" style="gap: var(--space-1);">
                                            <button type="button" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1"
                                                    data-bs-toggle="modal" data-bs-target="#edit-task-{{ Str::slug($task['key']) }}"
                                                    aria-label="Edit schedule for {{ $task['key'] }}">
                                                <i class="bi bi-pencil" aria-hidden="true"></i> Edit
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1"
                                                    data-bs-toggle="modal" data-bs-target="#run-task-{{ Str::slug($task['key']) }}"
                                                    aria-label="Run {{ $task['key'] }} now">
                                                <i class="bi bi-play-fill" aria-hidden="true"></i> Run now
                                            </button>
                                            <form method="POST" action="{{ route('admin.cron.toggle') }}" class="d-inline">
                                                @csrf
                                                <input type="hidden" name="key" value="{{ $task['key'] }}">
                                                <input type="hidden" name="enabled" value="{{ $task['enabled'] ? 0 : 1 }}">
                                                <button class="btn btn-sm d-inline-flex align-items-center gap-1 btn-outline-{{ $task['enabled'] ? 'secondary' : 'success' }}"
                                                        aria-label="{{ $task['enabled'] ? 'Disable' : 'Enable' }} {{ $task['key'] }}">
                                                    <i class="bi bi-{{ $task['enabled'] ? 'pause' : 'play' }}-circle" aria-hidden="true"></i>
                                                    {{ $task['enabled'] ? 'Disable' : 'Enable' }}
                                                </button>
                                            </form>
                                        </div>
                                    @else
                                        <span class="small" style="color: var(--color-text-faint);">—</span>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-0 border-0">
                                <x-adminlte.partials.empty-state icon="bi bi-clock" title="No scheduled tasks" message="No tasks are defined in routes/console.php — add a Schedule entry to see it here." />
                            </td>
                        </tr>
                    @endforelse
                    <tr id="cron-no-results" class="d-none">
                        <td colspan="6" class="p-0 border-0">
                            <x-adminlte.partials.empty-state icon="bi bi-search" title="No matching tasks" message="Try a different keyword or clear the status filter." />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="px-3 py-2 border-top d-flex align-items-center justify-content-between flex-wrap gap-2" style="background: var(--color-bg-subtle); font-size: var(--text-xs); color: var(--color-text-muted);">
            <span><i class="bi bi-info-circle me-1" aria-hidden="true"></i> Tip: hover the <i class="bi bi-info-circle"></i> next to a task to see why it matters. Custom schedules are highlighted.</span>
            <span class="d-none d-sm-inline">Disabled rows are dimmed and skipped by the scheduler.</span>
        </div>
                </div>{{-- /#tab-tasks --}}

                <div class="tab-pane fade {{ $activeTab === 'history' ? 'show active' : '' }}" id="tab-history" role="tabpanel" aria-labelledby="tab-history-btn">
        <form method="GET" class="p-3 border-bottom" style="background: var(--color-bg-subtle);">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-4 col-lg-3">
                    <label class="form-label small mb-1" for="history-task-filter" style="font-size: var(--text-xs); font-weight: 500; letter-spacing: 0.02em; text-transform: uppercase; color: var(--color-text-muted);">Task</label>
                    <select name="task" id="history-task-filter" class="form-select form-select-sm">
                        <option value="">All tasks</option>
                        @foreach ($tasks->pluck('key')->filter()->unique()->sort() as $key)
                            <option value="{{ $key }}" @selected(request('task') === $key)>{{ $key }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4 col-lg-3">
                    <label class="form-label small mb-1" for="history-status-filter" style="font-size: var(--text-xs); font-weight: 500; letter-spacing: 0.02em; text-transform: uppercase; color: var(--color-text-muted);">Status</label>
                    <select name="status" id="history-status-filter" class="form-select form-select-sm">
                        <option value="">All statuses</option>
                        @foreach (['success', 'failed', 'skipped', 'running'] as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4 col-lg-6 d-flex gap-2 align-items-center" style="gap: var(--space-2);">
                    <button class="btn btn-sm btn-primary d-inline-flex align-items-center gap-1"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
                    <a href="{{ route('admin.cron.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                    <span class="small ms-2 d-none d-lg-inline" style="color: var(--color-text-faint); font-size: var(--text-xs);">Showing {{ $runs->total() }} runs</span>
                </div>
            </div>
        </form>

        <div class="table-responsive has-sticky">
            <table class="table table-grid table-sm table-hover align-middle m-0" aria-label="Run history">
                <thead>
                    <tr>
                        <th style="white-space: nowrap;">Started</th>
                        <th>Task</th>
                        <th>Status</th>
                        <th>Trigger</th>
                        <th>Duration</th>
                        <th>Exit</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($runs as $run)
                        <tr>
                            <td class="small" style="white-space: nowrap; color: var(--color-text-muted); font-size: var(--text-sm);">
                                {{ $run->started_at?->format('M j, H:i:s') }}
                            </td>
                            <td><code class="small" style="font-size: var(--text-xs); color: var(--color-text); background: var(--color-bg-subtle); padding: 0.12rem 0.32rem; border-radius: var(--radius-sm); border: 1px solid var(--color-border); overflow-wrap: anywhere;">{{ $run->task_key }}</code></td>
                            <td>
                                <x-adminlte.partials.status-badge :status="$run->status" />
                            </td>
                            <td class="small">
                                @if ($run->trigger === 'manual')
                                    <x-adminlte.partials.status-badge status="manual" label="Manual" :map="['manual' => 'primary']" />
                                @else
                                    <span class="badge rounded-pill mh-badge mh-badge--subtle" style="font-size: var(--text-xs); font-weight: 500; letter-spacing: 0.02em; padding: 0.28em 0.62em; background: color-mix(in srgb, var(--color-neutral-500) 12%, var(--color-surface)); color: var(--color-text-muted); border: 1px solid color-mix(in srgb, var(--color-neutral-500) 16%, transparent);">Schedule</span>
                                @endif
                            </td>
                            <td class="small" style="font-variant-numeric: tabular-nums; font-size: var(--text-sm);">
                                {{ $run->runtime_ms !== null ? number_format($run->runtime_ms).' ms' : '—' }}
                            </td>
                            <td class="small" style="font-variant-numeric: tabular-nums; font-size: var(--text-sm);">{{ $run->exit_code ?? '—' }}</td>
                            <td class="small" style="color: var(--color-text-muted); font-size: var(--text-sm); max-width: 28ch; overflow-wrap: anywhere;">
                                {{ $run->message ? Str::limit($run->message, 120) : '—' }}
                            </td>
                        </tr>
                    @empty
                        <x-ui.empty-table-row :col-span="7" icon="bi bi-clock-history" title="No runs recorded yet" message="Runs appear here after the scheduler ticks or you use Run now." />
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($runs->hasPages())
            <div class="px-3 py-2 border-top" style="background: var(--color-bg-subtle);">
                <div class="grid-pagination d-flex align-items-center justify-content-between flex-wrap gap-2">
                    {{ $runs->links() }}
                </div>
            </div>
        @endif
                </div>{{-- /#tab-history --}}
            </div>{{-- /.tab-content --}}
        </div>{{-- /.card-body --}}
    </div>{{-- /.card --}}

    @can('cron.manage')
        @unless ($paused)
            <x-adminlte.partials.confirm-modal
                id="pause-scheduler-modal"
                title="Pause scheduler"
                message="Pause ALL scheduled tasks until resumed?"
                method="POST"
                :action="route('admin.cron.pause')"
                confirm-label="Pause scheduler"
                confirm-theme="warning"
            />
        @endunless

        @foreach ($tasks as $task)
            @php $plainModal = $cronDetails[$task['key'] ?? ''] ?? null; @endphp
            <x-adminlte.partials.confirm-modal
                :id="'run-task-' . Str::slug($task['key'] ?? 'unknown')"
                title="Run task now"
                :message="'Run ' . ($task['key'] ?? 'task') . ' now?'"
                method="POST"
                :action="route('admin.cron.run')"
                confirm-label="Run now"
                confirm-theme="primary"
            >
                It runs immediately and this page will wait for it to finish.
                <x-slot name="fields">
                    <input type="hidden" name="key" value="{{ $task['key'] }}">
                </x-slot>
            </x-adminlte.partials.confirm-modal>

            @if ($task['manageable'])
                <x-adminlte-modal :id="'edit-task-' . Str::slug($task['key'])" :title="'Edit ' . $task['key']" size="lg">
                    @if($plainModal)
                        <div class="d-flex align-items-start gap-2 p-2 rounded-2 mb-3" style="background: color-mix(in srgb, var(--color-info) 8%, var(--color-surface)); border: 1px solid color-mix(in srgb, var(--color-info) 16%, transparent); gap: var(--space-2);">
                            <span class="d-inline-flex align-items-center justify-content-center rounded-2 flex-shrink-0" style="width: 32px; height: 32px; background: color-mix(in srgb, var(--color-info) 14%, transparent); color: var(--color-info);"><i class="{{ $plainModal['icon'] }}" aria-hidden="true"></i></span>
                            <div class="flex-fill">
                                <div class="fw-medium" style="font-size: var(--text-sm); color: var(--color-text);">{{ $plainModal['what'] }}</div>
                                <div class="small" style="font-size: var(--text-xs); color: var(--color-text-muted);">{{ $plainModal['why'] }} — {{ $plainModal['when'] }} by default.</div>
                            </div>
                        </div>
                    @endif
                    <form method="POST" action="{{ route('admin.cron.update') }}" id="edit-task-{{ Str::slug($task['key']) }}-form">
                        @csrf
                        <input type="hidden" name="key" value="{{ $task['key'] }}">

                        <div class="mb-3">
                            <label class="form-label d-inline-flex align-items-center gap-1">
                                Cron expression <span class="text-muted small" style="font-weight: 400;">(leave empty for code default)</span>
                                <button type="button" class="btn btn-sm p-0 border-0 lh-1" data-bs-toggle="popover" data-bs-trigger="focus" data-bs-placement="top" data-bs-html="true"
                                        data-bs-content="<strong>5 fields:</strong> minute hour day month weekday<br><code>0 1 * * *</code> = daily 01:00<br><code>*/5 * * * *</code> = every 5 min<br><code>0 */6 * * *</code> = every 6 hours<br>Leave empty to use the code default: <code>{{ $task['expression'] }}</code>"
                                        aria-label="Explain cron expression">
                                    <i class="bi bi-question-circle" style="color: var(--color-text-faint);" aria-hidden="true"></i>
                                </button>
                            </label>
                            <input type="text" name="expression" class="form-control form-control-sm"
                                   placeholder="{{ $task['expression'] }}"
                                   value="{{ $task['stored']?->expression ?? '' }}"
                                   pattern="^(\S+\s+){4}\S+$"
                                   aria-describedby="cron-help-{{ Str::slug($task['key']) }}">
                            <div class="form-text" id="cron-help-{{ Str::slug($task['key']) }}">
                                5-field cron: <code>minute hour day month weekday</code> — e.g. <code>0 1 * * *</code> = daily 01:00. Current effective: <code>{{ $task['expression'] }}</code> ({{ $task['human'] }})
                                <span class="d-block mt-1" style="color: var(--color-text-faint);">
                                    <i class="bi bi-lightbulb me-1" aria-hidden="true"></i> Tip: <code>*/5 * * * *</code> = every 5 minutes, <code>0 */6 * * *</code> = every 6 hours, <code>0 0 * * 1</code> = Mondays at midnight.
                                </span>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label d-inline-flex align-items-center gap-1">
                                Timezone <span class="text-muted small" style="font-weight: 400;">(leave empty for default)</span>
                                <button type="button" class="btn btn-sm p-0 border-0 lh-1" data-bs-toggle="popover" data-bs-trigger="focus" data-bs-placement="top"
                                        data-bs-content="PHP timezone for this task — e.g. UTC or Asia/Kolkata. Must be a valid PHP identifier. Leave empty to use app default."
                                        aria-label="Explain timezone">
                                    <i class="bi bi-question-circle" style="color: var(--color-text-faint);" aria-hidden="true"></i>
                                </button>
                            </label>
                            <input type="text" name="timezone" class="form-control form-control-sm"
                                   placeholder="{{ $task['timezone'] }}"
                                   value="{{ $task['stored']?->timezone ?? '' }}"
                                   list="tz-list-{{ Str::slug($task['key']) }}">
                            <datalist id="tz-list-{{ Str::slug($task['key']) }}">
                                <option value="UTC"></option>
                                <option value="Asia/Kolkata"></option>
                                <option value="Asia/Dubai"></option>
                                <option value="Europe/London"></option>
                                <option value="America/New_York"></option>
                            </datalist>
                            <div class="form-text">PHP timezone, e.g. <code>UTC</code> or <code>Asia/Kolkata</code>. Current: {{ $task['timezone'] }}</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description <span class="text-muted small" style="font-weight: 400;">(optional label)</span></label>
                            <input type="text" name="description" class="form-control form-control-sm"
                                   placeholder="{{ $task['name'] }}"
                                   value="{{ $task['stored']?->description ?? '' }}">
                            <div class="form-text">Shown as the task name. Leave empty to use the code description / command signature. Non-tech: this is the friendly name you see in the list above.</div>
                        </div>

                        <div class="form-check mb-0">
                            <input type="hidden" name="enabled" value="0">
                            <input class="form-check-input" type="checkbox" name="enabled" value="1" id="edit-enabled-{{ Str::slug($task['key']) }}" @checked($task['enabled'])>
                            <label class="form-check-label" for="edit-enabled-{{ Str::slug($task['key']) }}">Enabled — when off, the scheduler skips this task until re-enabled</label>
                        </div>
                    </form>

                    <x-slot name="footer">
                        <div class="d-flex gap-2 justify-content-end w-100" style="gap: var(--space-2);">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" form="edit-task-{{ Str::slug($task['key']) }}-form" class="btn btn-primary">Save</button>
                        </div>
                    </x-slot>
                </x-adminlte-modal>
            @endif
        @endforeach
    @endcan

    @push('js')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Bootstrap tooltips & popovers (info icons + cron help)
            try {
                document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
                    if (window.bootstrap && bootstrap.Tooltip) new bootstrap.Tooltip(el);
                });
                document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
                    if (window.bootstrap && bootstrap.Popover) new bootstrap.Popover(el);
                });
            } catch (e) {}

            // Live filter for Scheduled Tasks
            var search = document.getElementById('cron-task-search');
            var clearBtn = document.getElementById('cron-search-clear');
            var rows = Array.from(document.querySelectorAll('[data-task-row]'));
            var noResults = document.getElementById('cron-no-results');
            var countEl = document.getElementById('cron-visible-count');
            var filterBtns = Array.from(document.querySelectorAll('[data-cron-filter]'));
            var resetBtn = document.getElementById('cron-filter-reset');
            var activeFilter = 'all';

            function applyFilter() {
                var q = (search && search.value || '').toLowerCase().trim();
                var visible = 0;
                rows.forEach(function (row) {
                    var hay = (row.getAttribute('data-key') || '') + ' ' + (row.getAttribute('data-name') || '') + ' ' + (row.getAttribute('data-command') || '') + ' ' + (row.getAttribute('data-what') || '');
                    var matchesSearch = !q || hay.indexOf(q) !== -1;
                    var matchesPill = true;
                    if (activeFilter === 'enabled') matchesPill = row.getAttribute('data-enabled') === '1';
                    else if (activeFilter === 'disabled') matchesPill = row.getAttribute('data-enabled') === '0';
                    else if (activeFilter === 'custom') matchesPill = row.getAttribute('data-custom') === '1';
                    else if (activeFilter === 'background') matchesPill = row.getAttribute('data-background') === '1';
                    var show = matchesSearch && matchesPill;
                    row.classList.toggle('d-none', !show);
                    if (show) visible++;
                });
                if (noResults) noResults.classList.toggle('d-none', visible !== 0);
                if (countEl) countEl.textContent = visible;
            }

            if (search) {
                search.addEventListener('input', applyFilter);
                search.addEventListener('keydown', function(e){ if(e.key==='Escape'){ search.value=''; applyFilter(); }});
            }
            if (clearBtn) clearBtn.addEventListener('click', function(){ if(search){ search.value=''; search.focus(); applyFilter(); }});
            filterBtns.forEach(function(btn){
                btn.addEventListener('click', function(){
                    activeFilter = btn.getAttribute('data-cron-filter') || 'all';
                    filterBtns.forEach(function(b){
                        var isActive = b.getAttribute('data-cron-filter') === activeFilter;
                        b.classList.toggle('btn-primary', isActive);
                        b.classList.toggle('active', isActive);
                        b.classList.toggle('btn-outline-secondary', !isActive);
                        b.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                    });
                    applyFilter();
                });
            });
            if (resetBtn) resetBtn.addEventListener('click', function(){
                activeFilter = 'all';
                if(search) search.value='';
                filterBtns.forEach(function(b){
                    var isAll = b.getAttribute('data-cron-filter') === 'all';
                    b.classList.toggle('btn-primary', isAll);
                    b.classList.toggle('active', isAll);
                    b.classList.toggle('btn-outline-secondary', !isAll);
                    b.setAttribute('aria-pressed', isAll ? 'true' : 'false');
                });
                applyFilter();
            });

            // Initial
            applyFilter();
        });
    </script>
    @endpush
@stop
