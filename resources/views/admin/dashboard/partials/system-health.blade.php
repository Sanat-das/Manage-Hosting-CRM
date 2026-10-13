@php
    /*
     * "All systems nominal" only when every one of the three headline facts is
     * clean — scheduler ticking, nothing failed, nothing queued. Anything else
     * gets the full row breakdown instead of five rows of zeros.
     */
    $nominal = $health->schedulerHealthy && $health->failedJobsCount === 0 && $health->queueDepth === 0;

    // Whole minutes since the last tick, or null when it has never ticked.
    $lastTickMinutes = $health->lastTickAt?->diffInMinutes(\Illuminate\Support\Carbon::now());
@endphp

<x-adminlte-card icon="bi bi-heart-pulse" title="System health">
    <x-slot:tools>
        <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" data-widget-toggle title="Hide widget" aria-pressed="false" style="border-radius: var(--radius-md); font-size: var(--text-xs);"><i class="bi bi-eye"></i><span class="visually-hidden">Hide widget</span></button>
        @can('cron.view')
            <a href="{{ route('admin.cron.index') }}" class="btn btn-sm btn-outline-secondary py-1 px-2" style="border-radius: var(--radius-md); font-size: var(--text-xs); font-weight: 500;">View all</a>
        @endcan
    </x-slot:tools>

    @if ($nominal)
        <div class="d-flex flex-wrap align-items-center gap-2" style="font-size: var(--text-sm);">
            <i class="bi bi-check-circle-fill flex-shrink-0" aria-hidden="true" style="color: var(--color-success);"></i>
            <span class="fw-medium" style="color: var(--color-text);">All systems nominal</span>
            <span class="text-muted" style="font-size: var(--text-xs);">Scheduler ticking, no failed jobs, queue empty.</span>
        </div>
    @else
        <div class="d-flex flex-column" style="gap: var(--space-4);">
            {{-- Scheduler heartbeat — the OS-level cron entry, not the tasks themselves --}}
            <div class="d-flex align-items-start justify-content-between gap-2">
                <div class="d-flex flex-column" style="gap: var(--space-1);">
                    <span class="fw-medium" style="font-size: var(--text-sm); color: var(--color-text);">Scheduler</span>
                    @if ($health->lastTickAt !== null)
                        <span class="text-muted" style="font-size: var(--text-xs);">
                            @if ($health->schedulerHealthy)
                                Last tick {{ $health->lastTickAt->diffForHumans() }}
                            @else
                                Last tick {{ (int) $lastTickMinutes }} min ago — expected every minute
                            @endif
                        </span>
                    @else
                        <span style="color: var(--color-text-faint); font-size: var(--text-xs);">—</span>
                        <span class="text-muted" style="font-size: var(--text-xs);"><code style="background: var(--color-bg-subtle); padding: 0.1rem 0.3rem; border-radius: var(--radius-sm); border: 1px solid var(--color-border);">schedule:run</code> has never completed on this installation.</span>
                    @endif
                </div>
                @if ($health->schedulerHealthy)
                    <x-adminlte.partials.status-badge status="healthy" label="Healthy" :map="['healthy' => 'success']" variant="subtle" />
                @else
                    <x-adminlte.partials.status-badge status="stale" label="Stale" :map="['stale' => 'danger']" variant="subtle" />
                @endif
            </div>

            {{-- Failed jobs — crashed queue work, invisible until a customer complains --}}
            <div class="d-flex align-items-start justify-content-between gap-2">
                <div class="d-flex flex-column" style="gap: var(--space-1);">
                    <span class="fw-medium" style="font-size: var(--text-sm); color: var(--color-text);">Failed jobs</span>
                    @if ($health->failedJobsCount > 0)
                        <span class="text-muted" style="font-size: var(--text-xs);">
                            @if ($health->lastFailedAt !== null)
                                Most recent {{ $health->lastFailedAt->diffForHumans() }}
                            @else
                                No failure timestamp recorded
                            @endif
                        </span>
                    @else
                        <span class="text-muted" style="font-size: var(--text-xs);">No failures recorded</span>
                    @endif
                </div>
                @if ($health->failedJobsCount > 0)
                    <x-adminlte.partials.status-badge status="failed" :label="$health->failedJobsCount" :map="['failed' => 'danger']" variant="subtle" />
                @else
                    <x-adminlte.partials.status-badge status="none" label="0" :map="['none' => 'secondary']" variant="subtle" />
                @endif
            </div>

            {{-- Queue depth — pending work in the default connection and in the emails queue --}}
            <div class="d-flex align-items-start justify-content-between gap-2">
                <div class="d-flex flex-column" style="gap: var(--space-1);">
                    <span class="fw-medium" style="font-size: var(--text-sm); color: var(--color-text);">Queue depth</span>
                    <span class="text-muted" style="font-size: var(--text-xs);">
                        @if ($health->emailsQueueDepth > 0)
                            <span style="color: var(--color-warning);">{{ $health->emailsQueueDepth }} in the emails queue</span>
                        @else
                            Emails queue empty
                        @endif
                    </span>
                </div>
                @if ($health->queueDepth > 0)
                    <x-adminlte.partials.status-badge status="pending" :label="$health->queueDepth . ' pending'" :map="['pending' => 'warning']" variant="subtle" />
                @else
                    <x-adminlte.partials.status-badge status="empty" label="0" :map="['empty' => 'secondary']" variant="subtle" />
                @endif
            </div>

            {{-- Last scheduled run — the most recent row in cron_task_runs --}}
            <div class="d-flex align-items-start justify-content-between gap-2">
                <div class="d-flex flex-column" style="gap: var(--space-1); min-width: 0;">
                    <span class="fw-medium" style="font-size: var(--text-sm); color: var(--color-text);">Last scheduled run</span>
                    @if ($health->lastRun !== null)
                        <code class="text-break" style="font-size: var(--text-xs); color: var(--color-text); background: var(--color-bg-subtle); padding: 0.12rem 0.32rem; border-radius: var(--radius-sm); border: 1px solid var(--color-border); overflow-wrap: anywhere;">{{ $health->lastRun->task_key }}</code>
                        <span class="text-muted" style="font-size: var(--text-xs);">
                            @if ($health->lastRun->status === App\Models\CronTaskRun::STATUS_RUNNING)
                                Started {{ $health->lastRun->started_at?->diffForHumans() }}
                            @else
                                Finished {{ $health->lastRun->finished_at?->diffForHumans() ?? $health->lastRun->started_at?->diffForHumans() }}
                            @endif
                        </span>
                        @if ($health->lastFailedRunKey !== null && $health->lastFailedRunKey !== $health->lastRun->task_key)
                            <span class="text-muted" style="font-size: var(--text-xs);">Most recent failure: <code style="font-size: var(--text-xs);">{{ $health->lastFailedRunKey }}</code></span>
                        @endif
                    @else
                        <span style="color: var(--color-text-faint); font-size: var(--text-xs);">—</span>
                        <span class="text-muted" style="font-size: var(--text-xs);">No run recorded yet</span>
                    @endif
                </div>
                @if ($health->lastRun !== null)
                    <x-adminlte.partials.status-badge :status="$health->lastRun->status" variant="subtle" />
                @else
                    <x-adminlte.partials.status-badge status="never" label="Never" :map="['never' => 'secondary']" variant="subtle" />
                @endif
            </div>
        </div>
    @endif
</x-adminlte-card>
