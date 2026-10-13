@php
    /** @var \App\ViewModels\Admin\UpcomingRenewalsViewModel $renewals */
@endphp
<x-adminlte-card icon="bi bi-arrow-repeat" title="Upcoming renewals (next 30 days)">
    <x-slot:tools>
        <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" data-widget-toggle title="Hide widget" aria-pressed="false" style="border-radius: var(--radius-md); font-size: var(--text-xs);"><i class="bi bi-eye"></i><span class="visually-hidden">Hide widget</span></button>
        @can('reports.view')
            <a href="{{ route('admin.reports.domains') }}" class="btn btn-sm btn-outline-secondary py-1 px-2" style="border-radius: var(--radius-md); font-size: var(--text-xs);">View all</a>
        @endcan
    </x-slot:tools>

    <div class="text-muted mb-3" style="font-size: var(--text-sm); line-height: var(--leading-normal);">
        {{ $renewals->hostingCount }} hosting renewal{{ $renewals->hostingCount === 1 ? '' : 's' }} ·
        {{ $renewals->domainCount }} domain renewal{{ $renewals->domainCount === 1 ? '' : 's' }} ·
        @if($renewals->domainCount > 0)
            projected domain value <x-adminlte.partials.currency :value="$renewals->projectedDomainValue" />
        @else
            projected domain value unavailable
        @endif
    </div>

    @if($renewals->hostingRenewals->isEmpty() && $renewals->domainRenewals->isEmpty())
        <x-adminlte.partials.empty-state icon="bi bi-arrow-repeat" title="No upcoming renewals" message="No hosting accounts or domains are scheduled to renew in the next 30 days." size="sm" />
    @else
        @if($renewals->hostingRenewals->isNotEmpty())
            <div class="text-uppercase fw-semibold text-muted mb-2" style="font-size: var(--text-xs); letter-spacing: 0.04em;">Hosting renewals</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" style="font-size: var(--text-sm);">
                    <thead>
                        <tr class="text-muted" style="font-size: var(--text-xs); letter-spacing: 0.04em; text-transform: uppercase;">
                            <th class="border-0 fw-semibold">Hosting account</th>
                            <th class="border-0 fw-semibold">Customer</th>
                            <th class="border-0 fw-semibold text-end">Due</th>
                            <th class="border-0 fw-semibold text-end">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($renewals->hostingRenewals as $account)
                            <tr>
                                <td class="py-2 fw-semibold">{{ $account->host_name ?: ($account->domain ?: \App\Models\HostingAccount::fallbackHostName($account->id)) }}</td>
                                <td class="py-2" style="max-width: 10rem; overflow-wrap: anywhere;">{{ $account->customer?->full_name ?? '—' }}</td>
                                <td class="py-2 text-end" style="font-variant-numeric: tabular-nums;">
                                    <span class="{{ $account->next_due_date && $account->next_due_date->diffInDays(now()) <= 7 ? 'text-danger fw-semibold' : '' }}">{{ $account->next_due_date?->format('M j, Y') ?? '—' }}</span>
                                    @if($account->next_due_date)
                                        <div class="text-muted small" style="font-size: var(--text-xs);">{{ $account->next_due_date->diffForHumans(null, true) }} left</div>
                                    @endif
                                </td>
                                <td class="py-2 text-end"><x-adminlte.partials.status-badge :status="$account->status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-2 text-center text-muted small">No hosting renewals in the next 30 days.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif

        @if($renewals->domainRenewals->isNotEmpty())
            <div class="text-uppercase fw-semibold text-muted mb-2 {{ $renewals->hostingRenewals->isNotEmpty() ? 'mt-4' : '' }}" style="font-size: var(--text-xs); letter-spacing: 0.04em;">Domain renewals</div>
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
                        @forelse($renewals->domainRenewals as $domain)
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
                                    <span class="{{ $due && $due->diffInDays(now()) <= 7 ? 'text-danger fw-semibold' : '' }}">{{ $due?->format('M j, Y') ?? '—' }}</span>
                                    @if($due)
                                        <div class="text-muted small" style="font-size: var(--text-xs);">{{ $due->diffForHumans(null, true) }} left</div>
                                    @endif
                                </td>
                                <td class="py-2 text-end"><x-adminlte.partials.currency :value="$domain->recurring_amount ?? 0" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-2 text-center text-muted small">No domain renewals in the next 30 days.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</x-adminlte-card>
