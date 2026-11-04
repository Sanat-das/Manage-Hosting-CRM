@php
    /** @var \App\ViewModels\Admin\OverdueRenewalsViewModel $widget */
@endphp
<x-adminlte-card icon="bi bi-exclamation-triangle-fill" title="Overdue renewals">
    <x-slot:tools>
        <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" data-widget-toggle title="Hide widget" aria-pressed="false" style="border-radius: var(--radius-md); font-size: var(--text-xs);"><i class="bi bi-eye"></i><span class="visually-hidden">Hide widget</span></button>
        @can('reports.view')
            <a href="{{ route('admin.hosting.index', ['direction' => 'asc', 'sort' => 'due_date']) }}" class="btn btn-sm btn-outline-secondary py-1 px-2" style="border-radius: var(--radius-md); font-size: var(--text-xs);">View all</a>
        @endcan
    </x-slot:tools>

    <div class="text-muted mb-3" style="font-size: var(--text-sm); line-height: var(--leading-normal);">
        {{ $widget->hostingCount }} hosting renewal{{ $widget->hostingCount === 1 ? '' : 's' }} / {{ $widget->domainCount }} domain renewal{{ $widget->domainCount === 1 ? '' : 's' }} past due
    </div>

    @if($widget->hostingOverdue->isEmpty() && $widget->domainOverdue->isEmpty())
        <x-adminlte.partials.empty-state icon="bi bi-check-circle" title="No overdue renewals" message="Every service is inside its renewal window." size="sm" />
    @else
        @if($widget->hostingOverdue->isNotEmpty())
            <div class="text-uppercase fw-semibold text-muted mb-2" style="font-size: var(--text-xs); letter-spacing: 0.04em;">Overdue hosting accounts</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" style="font-size: var(--text-sm);">
                    <thead>
                        <tr class="text-muted" style="font-size: var(--text-xs); letter-spacing: 0.04em; text-transform: uppercase;">
                            <th class="border-0 fw-semibold">Account</th>
                            <th class="border-0 fw-semibold">Customer</th>
                            <th class="border-0 fw-semibold text-end">Due</th>
                            <th class="border-0 fw-semibold text-end">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($widget->hostingOverdue as $account)
                            @php $due = $account->next_due_date ?? $account->order?->next_billing_date; @endphp
                            <tr>
                                <td class="py-2 fw-semibold">{{ $account->host_name ?: ($account->domain ?: \App\Models\HostingAccount::fallbackHostName($account->id)) }}</td>
                                <td class="py-2" style="max-width: 10rem; overflow-wrap: anywhere;">{{ $account->customer?->full_name ?? '—' }}</td>
                                <td class="py-2 text-end" style="font-variant-numeric: tabular-nums;">
                                    <span class="text-overdue">{{ $due?->format('M j, Y') ?? '—' }}</span>
                                    @if($due)
                                        <div class="text-muted small" style="font-size: var(--text-xs);">{{ $due->diffForHumans(null, true) }} overdue</div>
                                    @endif
                                </td>
                                <td class="py-2 text-end"><x-adminlte.partials.status-badge :status="$account->status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-2 text-center text-muted small">No overdue hosting accounts.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif

        @if($widget->domainOverdue->isNotEmpty())
            <div class="text-uppercase fw-semibold text-muted mb-2 {{ $widget->hostingOverdue->isNotEmpty() ? 'mt-4' : '' }}" style="font-size: var(--text-xs); letter-spacing: 0.04em;">Overdue domains</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" style="font-size: var(--text-sm);">
                    <thead>
                        <tr class="text-muted" style="font-size: var(--text-xs); letter-spacing: 0.04em; text-transform: uppercase;">
                            <th class="border-0 fw-semibold">Domain</th>
                            <th class="border-0 fw-semibold">Customer</th>
                            <th class="border-0 fw-semibold text-end">Due</th>
                            <th class="border-0 fw-semibold text-end" style="font-variant-numeric: tabular-nums;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($widget->domainOverdue as $domain)
                            @php $due = $domain->renewal_due_date ? \Carbon\Carbon::parse($domain->renewal_due_date) : null; @endphp
                            <tr>
                                <td class="py-2 fw-semibold" style="font-variant-numeric: tabular-nums;">
                                    {{ $domain->name ?? '—' }}
                                    @unless($domain->auto_renew)
                                        <div class="text-muted small" style="font-size: var(--text-xs);">Manual renewal</div>
                                    @endunless
                                </td>
                                <td class="py-2" style="max-width: 10rem; overflow-wrap: anywhere;">{{ $domain->customer?->full_name ?? '—' }}</td>
                                <td class="py-2 text-end" style="font-variant-numeric: tabular-nums;">
                                    <span class="text-overdue">{{ $due?->format('M j, Y') ?? '—' }}</span>
                                    @if($due)
                                        <div class="text-muted small" style="font-size: var(--text-xs);">{{ $due->diffForHumans(null, true) }} overdue</div>
                                    @endif
                                </td>
                                <td class="py-2 text-end"><x-adminlte.partials.currency :value="$domain->recurring_amount ?? 0" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-2 text-center text-muted small">No overdue domains.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</x-adminlte-card>
