@extends('adminlte::page')

@section('title', 'Server — '.$server->name)

@section('content_header')
    <x-ui.page-header title="{{ $server->name }}" subtitle="View server details" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Servers', 'url' => route('admin.servers.index')],
        ['label' => $server->name, 'active' => true],
    ]" />
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif
    @if (session('error'))
        <x-adminlte-alert theme="danger" dismissible>{{ session('error') }}</x-adminlte-alert>
    @endif

    @php
        $displayType = $vm->displayType;
        $connStatus = $vm->connStatus;
        $connBadgeTheme = $vm->connBadgeTheme;
        $connDot = $vm->connDot;
        $isConnected = $vm->isConnected;
        $isPanel = $vm->isPanel;
        $isHyperv = $vm->isHyperv;
    @endphp

    {{-- Server header --}}
    <x-adminlte-card>
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center rounded-circle bg-primary text-white"
                 style="width: 56px; height: 56px; font-size: 1.25rem; flex-shrink: 0;">
                <i class="bi bi-server"></i>
            </div>
            <div class="flex-grow-1">
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <h4 class="mb-0">{{ $server->name }}</h4>
                    <x-adminlte.partials.status-badge :status="$server->status" />
                    <span class="badge text-bg-info rounded-pill" style="font-size:var(--text-xs); font-weight:500;">{{ $displayType }}</span>
                    <span class="badge rounded-pill text-bg-{{ $connBadgeTheme }}" id="connectionStatusBadge" style="font-size:var(--text-xs); font-weight:500;">
                        <span class="d-inline-block rounded-circle me-1 align-middle {{ $connDot }}" style="width:8px;height:8px;"></span>
                        {{ ucfirst($connStatus) }}
                    </span>
                    @if ($server->last_checked_at)
                        <span class="text-muted small" id="lastCheckedAt">Last checked {{ $server->last_checked_at->diffForHumans() }}</span>
                    @else
                        <span class="text-muted small" id="lastCheckedAt">Never checked</span>
                    @endif
                </div>
                <div class="text-muted mt-1 small d-flex flex-wrap gap-2 align-items-center">
                    <span><i class="bi bi-hdd-network me-1"></i>{{ $server->ip_address }}</span>
                    {{-- API URL / username live in Details below — kept out of header to avoid duplication --}}
                </div>
                @if ($connStatus === 'failed' && $server->connection_error)
                    <div class="alert alert-danger py-2 px-3 mt-2 mb-0 small" id="connectionErrorAlert">
                        <i class="bi bi-exclamation-triangle me-1"></i> {{ $server->connection_error }}
                    </div>
                @endif
            </div>
            <div class="d-flex gap-2 flex-wrap">
                @can('hosting.manage')
                    <a href="{{ route('admin.servers.edit', $server) }}" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-pencil me-1"></i> Edit
                    </a>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="retestBtn">
                        <span class="btn-label"><i class="bi bi-arrow-clockwise me-1"></i> Re-test Connection</span>
                        <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                    </button>
                @endcan
            </div>
        </div>
        <div id="retestAlert" class="d-none mt-3">
            <div class="alert mb-0" id="retestAlertBox"></div>
        </div>
    </x-adminlte-card>

    {{-- Essential Information --}}
    <x-adminlte-card icon="bi bi-activity" title="Essential Information" class="mb-3">
        @if (! $isConnected)
            <div class="text-center py-4">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-body-secondary mb-3" style="width:48px;height:48px;">
                    <i class="bi bi-wifi-off text-muted" style="font-size:1.25rem;"></i>
                </div>
                <p class="text-muted small mb-2">
                    @if ($connStatus === 'failed')
                        Connection failed — fix the credentials and re-test to load live data.
                    @elseif ($connStatus === 'untested')
                        No live data yet — test the connection to load version, capacity and VM counts.
                    @else
                        Not connected.
                    @endif
                </p>
                {{-- connection_error already shown in header alert — not repeated here --}}
                <p class="small text-muted mb-0">Use <strong>Re-test Connection</strong> above to refresh. Saving is allowed even when the test fails.</p>
            </div>
        @else
            @include('admin.servers.partials._essential-panels', ['server' => $server, 'vm' => ($vm ?? null), 'freshError' => ($freshError ?? null)])

            @if ($isHyperv)
                @include('admin.servers.partials._essential-hyperv', ['server' => $server, 'vm' => ($vm ?? null)])
            @endif
        @endif
    </x-adminlte-card>

    {{-- WinRM setup guide moved to the bottom of the page (after Details / Server Groups)
         so runtime data keeps the prime slot. --}}

    {{-- Removed metric-cards row: all four numbers duplicate sections below
         (Hosting/Active counts → Hosting Accounts table + Accounts/VMs card,
          Max → Details, Groups → Server Groups card). --}}

    @php
        $isVirtualization = in_array($server->server_type ?? $server->panel_type, ['hyperv','proxmox','virtualizor'], true);
    @endphp

    @if($isVirtualization)
    {{-- Virtualization: one merged provisioned + live inventory table (see _vm-list partial). --}}
    @php
        $vmInvHeader = (isset($vmInventory) && is_array($vmInventory)) ? $vmInventory : null;
        $vmInvHeaderRows = (is_array($vmInvHeader['rows'] ?? null)) ? $vmInvHeader['rows'] : null;
        $vmBuiltCount = $vmInvHeaderRows !== null
            ? count($vmInvHeaderRows)
            : ($panelAccountsTotal ?? ($panelAccounts ?? collect())->count());
        $vmLiveTotal = ! (bool)($vmInvHeader['liveUnavailable'] ?? true) ? ($vmInvHeader['liveTotal'] ?? null) : null;
        $vmMissingUnexpected = (int)($vmInvHeader['missingUnexpectedCount'] ?? 0);
    @endphp
    <x-adminlte-card icon="bi bi-cpu" title="Virtual Machines — built on {{ $server->name }}">
        <div class="d-flex align-items-center flex-wrap gap-2 mb-3">
            @if($vmLiveTotal !== null)
                <span class="badge text-bg-secondary">{{ $vmLiveTotal }} on host</span>
            @endif
            <span class="badge {{ $vmBuiltCount > 0 ? 'text-bg-secondary' : 'text-bg-light border text-muted' }}">{{ $vmBuiltCount }} built</span>
            @if($vmMissingUnexpected > 0)
                <span class="badge text-bg-warning"><i class="bi bi-exclamation-triangle me-1"></i>{{ $vmMissingUnexpected }} missing on host</span>
            @endif
            <span class="text-muted small">Each VM is provisioned from an order and runs on this host.</span>
        </div>
        @include('admin.servers.partials._vm-list', ['server' => $server, 'vm' => ($vm ?? null), 'vmInventory' => ($vmInventory ?? null), 'panelAccounts' => ($panelAccounts ?? collect())])
        {{-- Removed WinRM footer hint: host:port + username already in Transport strip above. --}}
    </x-adminlte-card>
    @endif

    <div class="row">
        {{-- Accounts on this server — hidden on virtualization servers with zero hosting rows (VMs card covers them) --}}
        @if(!$isVirtualization || ($hostingAccounts ?? $server->hostingAccounts)->count() > 0)
        <div class="col-md-8">
            <x-adminlte-card icon="bi bi-hdd-stack" title="Hosting Accounts">
                @php
                    $hostingSource = $hostingAccounts ?? $server->hostingAccounts;
                    $hostingList = $hostingSource instanceof \Illuminate\Contracts\Pagination\Paginator ? collect($hostingSource->items()) : $hostingSource;
                @endphp
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Username</th><th>Customer</th><th>Package</th><th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($hostingList as $account)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.hosting.show', $account) }}"><strong>{{ $account->username ?: $account->host_name }}</strong></a>
                                        @if ($account->domain)
                                            <div class="text-muted small">{{ $account->domain }}</div>
                                        @endif
                                    </td>
                                    <td class="text-muted">{{ $account->customer?->full_name ?? '—' }}</td>
                                    <td class="text-muted">{{ $account->product?->name ?? '—' }}</td>
                                    <td><x-adminlte.partials.status-badge :status="$account->status" /></td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-3">No accounts on this server.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if(isset($hostingAccounts) && $hostingAccounts instanceof \Illuminate\Contracts\Pagination\Paginator)
                    <div class="mt-3">{{ $hostingAccounts->appends(request()->query())->links() }}</div>
                @endif
            </x-adminlte-card>
        </div>
        @endif

        {{-- Server details + group membership --}}
        <div class="col-md-4">
            <x-adminlte-card icon="bi bi-info-circle" title="Details">
                {{-- Single source for connection config. Name / IP / Type / Status / Connection / Last-checked
                     live in the header above; Port / SSL / Verify TLS live in the Transport strip (Hyper-V). --}}
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <tr><th class="text-muted w-25">API URL</th><td class="text-break">{{ $server->api_url ?? '—' }}</td></tr>
                        <tr><th class="text-muted">API username</th><td>{{ $server->api_username ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Max accounts</th><td>{{ $server->max_accounts > 0 ? $server->max_accounts : 'Unlimited' }}</td></tr>
                    </tbody>
                </table>
            </x-adminlte-card>

            <x-adminlte-card icon="bi bi-collection" title="Server Groups">
                @forelse ($groups as $member)
                    <span class="badge text-bg-info me-1">{{ $member->group?->name ?? 'Deleted group' }}</span>
                @empty
                    <p class="text-muted mb-0">Not a member of any group.</p>
                @endforelse
            </x-adminlte-card>
        </div>
    </div>

    @if(($server->server_type ?? $server->panel_type) === 'hyperv')
        @include('admin.servers.partials._winrm-guide', ['serverType' => 'hyperv', 'server' => $server, 'typeSlug' => 'hyperv'])
    @endif

@push('js')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('retestBtn');
    const badge = document.getElementById('connectionStatusBadge');
    const lastChecked = document.getElementById('lastCheckedAt');
    const alertWrap = document.getElementById('retestAlert');
    const alertBox = document.getElementById('retestAlertBox');
    const errorAlert = document.getElementById('connectionErrorAlert');
    if (!btn) return;
    const url = @json(route('admin.servers.test-connection', $server));
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || @json(csrf_token());

    btn.addEventListener('click', async function () {
        const spinner = btn.querySelector('.spinner-border');
        const label = btn.querySelector('.btn-label');
        btn.disabled = true;
        if (spinner) spinner.classList.remove('d-none');
        if (label) label.classList.add('opacity-50');
        if (alertWrap) alertWrap.classList.add('d-none');
        const skeleton = document.querySelector('.retest-skeleton');
        if (skeleton) skeleton.classList.remove('d-none');

        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({}),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                if (alertWrap && alertBox) {
                    alertWrap.classList.remove('d-none');
                    alertBox.className = 'alert alert-danger mb-0';
                    alertBox.textContent = data.message || data.error || 'Re-test failed (' + res.status + ').';
                }
                return;
            }
            const ok = !!data.ok;
            const message = data.message || data.error || (ok ? 'Connected.' : 'Failed.');
            const latency = data.latencyMs ?? data.latency_ms ?? data.latency;
            if (alertWrap && alertBox) {
                alertWrap.classList.remove('d-none');
                alertBox.className = 'alert mb-0 ' + (ok ? 'alert-success' : 'alert-danger');
                // message carries host-derived text — render as text, never HTML.
                alertBox.textContent = '';
                const icon = document.createElement('i');
                icon.className = 'bi ' + (ok ? 'bi-check-circle' : 'bi-exclamation-triangle') + ' me-1';
                alertBox.appendChild(icon);
                alertBox.appendChild(document.createTextNode(message + (latency ? ' · ' + latency + ' ms' : '')));
            }
            if (badge) {
                const theme = ok ? 'success' : 'danger';
                const dot = ok ? 'bg-success' : 'bg-danger';
                badge.className = 'badge rounded-pill text-bg-' + theme;
                badge.innerHTML = '<span class="d-inline-block rounded-circle me-1 align-middle ' + dot + '" style="width:8px;height:8px;"></span>' + (ok ? 'Connected' : 'Failed');
            }
            if (lastChecked) {
                lastChecked.textContent = 'Last checked just now' + (latency ? ' · ' + latency + ' ms' : '');
            }
            if (ok && errorAlert) { errorAlert.remove(); }
            if (!ok && data.error && !errorAlert) {
                // append error under header
            }
            if (window.toastr) {
                if (ok) toastr.success(message);
                else toastr.error(message);
            }
            // Reload after short delay to refresh Essential Information metrics without manual reload
            setTimeout(() => window.location.reload(), 1200);
        } catch (e) {
            if (alertWrap && alertBox) {
                alertWrap.classList.remove('d-none');
                alertBox.className = 'alert alert-danger mb-0';
                alertBox.textContent = (e && e.message) ? e.message : 'Network error during re-test.';
            }
        } finally {
            btn.disabled = false;
            if (spinner) spinner.classList.add('d-none');
            if (label) label.classList.remove('opacity-50');
            const sk = document.querySelector('.retest-skeleton');
            if (sk) sk.classList.add('d-none');
        }
    });
});
</script>
<script>
// VMId copy buttons in the merged VM table — same .copy-btn[data-copy] pattern
// as admin/system/index, but a single delegated listener (rows render via partial).
document.addEventListener('click', function (event) {
    var btn = event.target && event.target.closest ? event.target.closest('.copy-btn[data-copy]') : null;
    if (!btn) return;
    var text = btn.getAttribute('data-copy') || '';
    if (!text) return;
    var original = btn.innerHTML;
    var done = function () {
        btn.innerHTML = '<i class="bi bi-check-lg"></i>';
        setTimeout(function () { btn.innerHTML = original; }, 1200);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, done);
    } else {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.cssText = 'position:fixed;opacity:0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (err) {}
        document.body.removeChild(ta);
        done();
    }
});
</script>
@endpush
@stop
