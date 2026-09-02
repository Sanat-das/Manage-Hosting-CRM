@extends('adminlte::page')

@section('title', 'Cron Jobs')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Cron Jobs</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active">Cron Jobs</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- Scheduler health. Nothing else on this page matters if the OS-level
         tick is not running: every task simply stops without an error. --}}
    @if (! $schedulerHealthy)
        <div class="alert alert-danger">
            <h5 class="alert-heading"><i class="bi bi-exclamation-octagon me-1"></i> The scheduler is not running</h5>
            @if ($lastTickAt === null)
                <p class="mb-2">
                    <code>schedule:run</code> has never completed on this installation, so
                    <strong>no scheduled task has ever run</strong>.
                </p>
            @else
                <p class="mb-2">
                    Last tick was <strong>{{ $lastTickAt->diffForHumans() }}</strong>
                    ({{ $lastTickAt->format('M j, Y H:i:s') }}). Expected every minute; treated as
                    stale after {{ (int) ($staleAfter / 60) }} minutes.
                </p>
            @endif
            <p class="mb-1">Every minute, a cron entry (or Windows Task Scheduler task) must run:</p>
            <pre class="mb-0 bg-dark text-light p-2 rounded"><code>php artisan schedule:run</code></pre>
        </div>
    @else
        <div class="alert alert-success d-flex align-items-center">
            <i class="bi bi-check-circle me-2"></i>
            <div>Scheduler is ticking — last run {{ $lastTickAt->diffForHumans() }}.</div>
        </div>
    @endif

    @if ($paused)
        <div class="alert alert-warning d-flex justify-content-between align-items-center">
            <div>
                <i class="bi bi-pause-circle me-1"></i>
                <strong>The scheduler is paused.</strong> Due tasks are being skipped until it is resumed.
            </div>
            @can('cron.manage')
                <form method="POST" action="{{ route('admin.cron.pause') }}">
                    @csrf
                    <input type="hidden" name="resume" value="1">
                    <button class="btn btn-sm btn-warning"><i class="bi bi-play-fill me-1"></i> Resume</button>
                </form>
            @endcan
        </div>
    @endif

    <x-adminlte-card icon="bi bi-clock" title="Scheduled Tasks">
        @can('cron.manage')
            @unless ($paused)
                <div class="mb-3">
                    <button type="button" class="btn btn-sm btn-outline-warning"
                            data-bs-toggle="modal" data-bs-target="#pause-scheduler-modal">
                        <i class="bi bi-pause-fill me-1"></i> Pause scheduler
                    </button>
                    <span class="text-muted small ms-2">Stops every task at once — use for maintenance windows.</span>
                </div>
            @endunless
        @endcan

        {{-- Deliberately NOT wrapped in .table-responsive. Every cell here is
             free to wrap and the colgroup below sizes the columns in
             percentages, so the table always fits its card and the horizontal
             scrollbar that wrapper used to produce has nothing left to scroll. --}}
        <table class="table table-hover align-middle mb-0">
            <colgroup>
                <col style="width: 24%">
                <col style="width: 18%">
                <col style="width: 13%">
                <col style="width: 12%">
                <col style="width: 9%">
                <col style="width: 24%">
            </colgroup>
            <thead>
                <tr>
                    <th>Task</th>
                    <th>Schedule</th>
                    <th>Next Due</th>
                    <th>Last Run</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tasks as $task)
                    <tr class="{{ $task['enabled'] ? '' : 'table-secondary' }}">
                        <td>
                            <div class="fw-semibold text-break">{{ $task['name'] }}</div>
                            {{-- Only when it adds something: for a command
                                 task the name IS the signature. --}}
                            @if ($task['command'] !== $task['name'])
                                <code class="small text-muted text-break">{{ $task['command'] }}</code>
                            @endif
                            @if ($task['background'])
                                <span class="badge text-bg-secondary ms-1">background</span>
                            @endif
                            @if ($task['without_overlapping'])
                                <span class="badge text-bg-secondary ms-1">no overlap</span>
                            @endif
                            @unless ($task['manageable'])
                                <div class="small text-warning">
                                    Unnamed closure — add <code>-&gt;name('...')</code> in
                                    <code>routes/console.php</code> to manage it here.
                                </div>
                            @endunless
                            @if (! empty($task['is_custom']))
                                <div class="small text-muted mt-1"><i class="bi bi-pencil-square me-1"></i>Overridden in DB — leave fields empty to revert to code default.</div>
                            @endif
                        </td>
                        <td>
                            <div>{{ $task['human'] }}</div>
                            <code class="small text-muted">{{ $task['expression'] }}</code>
                            <div class="small text-muted">{{ $task['timezone'] }}</div>
                            @if (! empty($task['is_custom']))
                                <div><span class="badge text-bg-warning mt-1">Custom</span></div>
                            @endif
                        </td>
                        <td class="small">
                            @if ($task['next_due'])
                                {{ $task['next_due']->format('M j, H:i') }}
                                <div class="text-muted">{{ $task['next_due']->diffForHumans() }}</div>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="small">
                            @if ($task['last_run'])
                                {{ $task['last_run']->started_at?->format('M j, H:i') }}
                                <div class="text-muted">
                                    @if ($task['last_run']->runtime_ms !== null)
                                        {{ number_format($task['last_run']->runtime_ms) }} ms
                                    @endif
                                </div>
                            @else
                                <span class="text-muted">Never</span>
                            @endif
                        </td>
                        <td>
                            @if (! $task['enabled'])
                                <span class="badge text-bg-secondary">Disabled</span>
                            @elseif ($task['last_run'])
                                @php($status = $task['last_run']->status)
                                <x-adminlte.partials.status-badge :status="$status" />
                            @else
                                <span class="badge text-bg-light">Enabled</span>
                            @endif
                        </td>
                        <td class="text-end">
                            @can('cron.manage')
                                @if ($task['manageable'])
                                    {{-- A wrapping flex row, not a nowrap cell: when the
                                         column is tight the two buttons stack instead of
                                         forcing the table wider than the card. --}}
                                    <div class="d-flex flex-wrap gap-1 justify-content-end">
                                        <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap"
                                                data-bs-toggle="modal" data-bs-target="#edit-task-{{ Str::slug($task['key']) }}">
                                            <i class="bi bi-pencil"></i> Edit
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-primary text-nowrap"
                                                data-bs-toggle="modal" data-bs-target="#run-task-{{ Str::slug($task['key']) }}">
                                            <i class="bi bi-play-fill"></i> Run now
                                        </button>
                                        <form method="POST" action="{{ route('admin.cron.toggle') }}">
                                            @csrf
                                            <input type="hidden" name="key" value="{{ $task['key'] }}">
                                            <input type="hidden" name="enabled" value="{{ $task['enabled'] ? 0 : 1 }}">
                                            <button class="btn btn-sm text-nowrap btn-outline-{{ $task['enabled'] ? 'secondary' : 'success' }}">
                                                <i class="bi bi-{{ $task['enabled'] ? 'pause' : 'play' }}-circle"></i>
                                                {{ $task['enabled'] ? 'Disable' : 'Enable' }}
                                            </button>
                                        </form>
                                    </div>
                                @else
                                    <span class="text-muted small">—</span>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            No scheduled tasks are defined in <code>routes/console.php</code>.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-adminlte-card>

    <x-adminlte-card icon="bi bi-list-ul" title="Run History">
        <form method="GET" class="row g-2 align-items-end mb-3">
            <div class="col-auto">
                <label class="form-label small mb-1">Task</label>
                <select name="task" class="form-select form-select-sm">
                    <option value="">All tasks</option>
                    @foreach ($tasks->pluck('key')->filter()->unique() as $key)
                        <option value="{{ $key }}" @selected(request('task') === $key)>{{ $key }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All statuses</option>
                    @foreach (['success', 'failed', 'skipped', 'running'] as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i> Filter</button>
                <a href="{{ route('admin.cron.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Started</th>
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
                            <td class="small text-muted" style="white-space: nowrap;">
                                {{ $run->started_at?->format('M j, H:i:s') }}
                            </td>
                            <td><code class="small">{{ $run->task_key }}</code></td>
                            <td>
                                <x-adminlte.partials.status-badge :status="$run->status" />
                            </td>
                            <td class="small">
                                @if ($run->trigger === 'manual')
                                    <span class="badge text-bg-primary">Manual</span>
                                @else
                                    <span class="text-muted">Schedule</span>
                                @endif
                            </td>
                            <td class="small">
                                {{ $run->runtime_ms !== null ? number_format($run->runtime_ms).' ms' : '—' }}
                            </td>
                            <td class="small">{{ $run->exit_code ?? '—' }}</td>
                            <td class="small text-muted">
                                {{ $run->message ? Str::limit($run->message, 120) : '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No runs recorded yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $runs->links() }}
    </x-adminlte-card>

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
            <x-adminlte.partials.confirm-modal
                :id="'run-task-' . Str::slug($task['key'])"
                title="Run task now"
                :message="'Run ' . $task['key'] . ' now?'"
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
                <x-adminlte-modal :id="'edit-task-' . Str::slug($task['key'])" :title="'Edit ' . $task['key']">
                    <form method="POST" action="{{ route('admin.cron.update') }}" id="edit-task-{{ Str::slug($task['key']) }}-form">
                        @csrf
                        <input type="hidden" name="key" value="{{ $task['key'] }}">

                        <div class="mb-3">
                            <label class="form-label">Cron expression <span class="text-muted small">(leave empty for code default)</span></label>
                            <input type="text" name="expression" class="form-control form-control-sm"
                                   placeholder="{{ $task['expression'] }}"
                                   value="{{ $task['stored']?->expression ?? '' }}">
                            <div class="form-text">5-field cron, e.g. <code>0 1 * * *</code> for daily 01:00. Current effective: <code>{{ $task['expression'] }}</code></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Timezone <span class="text-muted small">(leave empty for default)</span></label>
                            <input type="text" name="timezone" class="form-control form-control-sm"
                                   placeholder="{{ $task['timezone'] }}"
                                   value="{{ $task['stored']?->timezone ?? '' }}">
                            <div class="form-text">PHP timezone, e.g. <code>UTC</code> or <code>Asia/Kolkata</code>. Current: {{ $task['timezone'] }}</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description <span class="text-muted small">(optional label)</span></label>
                            <input type="text" name="description" class="form-control form-control-sm"
                                   placeholder="{{ $task['name'] }}"
                                   value="{{ $task['stored']?->description ?? '' }}">
                            <div class="form-text">Shown as the task name. Leave empty to use the code description / command signature.</div>
                        </div>

                        <div class="form-check mb-0">
                            <input type="hidden" name="enabled" value="0">
                            <input class="form-check-input" type="checkbox" name="enabled" value="1" id="edit-enabled-{{ Str::slug($task['key']) }}" @checked($task['enabled'])>
                            <label class="form-check-label" for="edit-enabled-{{ Str::slug($task['key']) }}">Enabled</label>
                        </div>
                    </form>

                    <x-slot name="footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" form="edit-task-{{ Str::slug($task['key']) }}-form" class="btn btn-primary">Save</button>
                    </x-slot>
                </x-adminlte-modal>
            @endif
        @endforeach
    @endcan
@stop
