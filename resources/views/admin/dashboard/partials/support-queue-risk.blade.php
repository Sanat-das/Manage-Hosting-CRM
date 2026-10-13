<x-adminlte-card icon="bi bi-life-preserver" title="Support queue risk">
    <x-slot:tools>
        <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" data-widget-toggle title="Hide widget" aria-pressed="false" style="border-radius: var(--radius-md); font-size: var(--text-xs);"><i class="bi bi-eye"></i><span class="visually-hidden">Hide widget</span></button>
        @can('tickets.view')
            <a href="{{ route('admin.tickets.index') }}" class="btn btn-sm btn-outline-secondary py-1 px-2" style="border-radius: var(--radius-md); font-size: var(--text-xs);">View all</a>
        @endcan
    </x-slot:tools>

    @if($support->unassignedCount === 0 && $support->awaitingReplyCount === 0)
        <x-adminlte.partials.empty-state
            icon="bi bi-shield-check"
            title="Support queue is healthy"
            message="Every open ticket has an owner and no customer is waiting for a reply. Unassigned tickets or customer replies awaiting an answer will surface here."
            size="sm" />
    @else
        <div class="row g-3 mb-3">
            <div class="col-6 col-lg-4">
                <div class="text-muted" style="font-size: var(--text-xs); letter-spacing: 0.04em; text-transform: uppercase; font-weight: 600;">Unassigned</div>
                <div class="fw-bold {{ $support->unassignedUrgent > 0 ? 'text-danger' : '' }}" style="font-size: var(--text-xl); letter-spacing: var(--tracking-tight); line-height: 1.1; font-variant-numeric: tabular-nums;">{{ number_format($support->unassignedCount) }}</div>
                <div class="small {{ $support->unassignedUrgent > 0 ? 'text-danger' : 'text-muted' }}" style="font-size: var(--text-xs); line-height: var(--leading-normal);">
                    {{ $support->unassignedUrgent > 0 ? $support->unassignedUrgent.' urgent / high priority' : 'No urgent or high priority' }}
                </div>
            </div>
            <div class="col-6 col-lg-4">
                <div class="text-muted" style="font-size: var(--text-xs); letter-spacing: 0.04em; text-transform: uppercase; font-weight: 600;">Awaiting reply</div>
                <div class="fw-bold {{ $support->awaitingReplyUrgent > 0 ? 'text-danger' : '' }}" style="font-size: var(--text-xl); letter-spacing: var(--tracking-tight); line-height: 1.1; font-variant-numeric: tabular-nums;" title="Customers waiting for a staff reply">{{ number_format($support->awaitingReplyCount) }}</div>
                <div class="small {{ $support->awaitingReplyUrgent > 0 ? 'text-danger' : 'text-muted' }}" style="font-size: var(--text-xs); line-height: var(--leading-normal);">
                    {{ $support->awaitingReplyUrgent > 0 ? $support->awaitingReplyUrgent.' customers waiting on urgent / high priority' : 'Customers waiting for a staff reply' }}
                </div>
            </div>
            <div class="col-6 col-lg-4">
                <div class="text-muted" style="font-size: var(--text-xs); letter-spacing: 0.04em; text-transform: uppercase; font-weight: 600;">Oldest waiting</div>
                <div class="fw-bold" style="font-size: var(--text-xl); letter-spacing: var(--tracking-tight); line-height: 1.1; font-variant-numeric: tabular-nums;">
                    {{ $support->oldestUnassignedDays }}<span class="text-muted fw-medium" style="font-size: var(--text-sm);"> days</span>
                </div>
                <div class="small text-muted" style="font-size: var(--text-xs); line-height: var(--leading-normal);">Oldest unassigned open ticket</div>
            </div>
        </div>

        <div class="pt-3 border-top" style="border-color: var(--bs-border-color) !important;">
            <div class="text-muted mb-2" style="font-size: var(--text-xs); letter-spacing: 0.04em; text-transform: uppercase; font-weight: 600;">Unassigned by priority</div>
            @php
                $supportRiskTotal = array_sum($support->byPriority);
            @endphp
            @foreach($support->byPriority as $supportRiskPriority => $supportRiskCount)
                @php
                    $supportRiskPct = $supportRiskTotal > 0 ? (int) round($supportRiskCount / $supportRiskTotal * 100) : 0;
                    $supportRiskBar = match ($supportRiskPriority) {
                        'urgent' => 'bg-danger',
                        'high' => 'bg-warning',
                        'medium' => 'bg-info',
                        default => 'bg-secondary',
                    };
                @endphp
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="text-muted text-uppercase" style="font-size: var(--text-xs); letter-spacing: 0.04em; width: 4.5rem;">{{ $supportRiskPriority }}</span>
                    <div class="progress flex-fill" style="height: 6px; background: var(--color-bg-subtle);">
                        <div class="progress-bar {{ $supportRiskBar }}" role="progressbar" style="width: {{ $supportRiskPct }}%" aria-valuenow="{{ $supportRiskPct }}" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                    <span class="fw-semibold text-end" style="font-size: var(--text-xs); width: 2.5rem; font-variant-numeric: tabular-nums;">{{ $supportRiskCount }}</span>
                </div>
            @endforeach
        </div>
    @endif
</x-adminlte-card>
