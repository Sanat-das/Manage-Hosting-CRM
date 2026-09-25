@extends('adminlte::page')

@section('title', $account->host_name)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">{{ $account->host_name }}</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.hosting.index') }}">Products/Services</a></li>
                <li class="breadcrumb-item active">{{ $account->host_name }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <div class="row">
        <div class="col-lg-8">
            <x-adminlte-card icon="bi bi-hdd-stack" title="Account Details">
                <table class="table table-sm table-borderless">
                    <tr><th class="w-25 text-muted">Host name</th><td>{{ $account->host_name }}</td></tr>
                    <tr><th class="text-muted">Product</th><td>{{ $account->product?->name ?? '—' }}</td></tr>
                    <tr><th class="text-muted">Domain</th><td>{{ $account->domain ?? '—' }}</td></tr>
                    <tr><th class="text-muted">Server</th><td>{{ $account->server?->name ?? '—' }}</td></tr>
                    <tr>
                        <th class="text-muted">Status</th>
                        <td>
                            <x-adminlte.partials.status-badge :status="$account->status" />
                        </td>
                    </tr>
                </table>
            </x-adminlte-card>

            {{-- Manual VM Provisioning (Hyper-V curated templates) --}}
            @php
                $provisionCard = $provisionCard ?? ['isHyperv' => false, 'templates' => [], 'default' => null];
                $isHyperv = (bool) ($provisionCard['isHyperv'] ?? false);
                $clientTemplates = $provisionCard['templates'] ?? [];
                $clientDefault = $provisionCard['default'] ?? null;
                $clientCuratedCount = $provisionCard['curatedCount'] ?? 0;
                $clientNoEffective = $provisionCard['noEffective'] ?? false;
            @endphp
            @php
                // Frozen vm-status contract shared with the admin page (passed
                // by the controller; defensive defaults keep the card rendering
                // when the presenter degrades).
                $clientVmStatus = $vmStatus ?? null;
                $clientAct = is_array($clientVmStatus['action'] ?? null) ? $clientVmStatus['action'] : null;
                $clientVm = is_array($clientVmStatus['vm'] ?? null) ? $clientVmStatus['vm'] : null;
                $clientIsRunning = (bool) ($clientAct['running'] ?? false);
                $clientProgress = max(0, min(100, (int) ($clientAct['progress'] ?? 0)));
                $clientStageLabel = trim((string) ($clientAct['stage_label'] ?? ($clientAct['stage'] ?? 'Working…')));
                if ($clientStageLabel === '') { $clientStageLabel = 'Working…'; }
                $clientElapsed = (int) ($clientAct['elapsed'] ?? 0);
                $clientElapsedFmt = sprintf('%02d:%02d', intdiv($clientElapsed, 60), $clientElapsed % 60);
                $clientVmStateRaw = $clientVm['state'] ?? null;
                if (($clientVm['exists'] ?? null) === false) {
                    $clientVmStateLabel = 'Not created';
                    $clientVmStateTheme = 'secondary';
                } elseif (is_string($clientVmStateRaw) && $clientVmStateRaw !== '') {
                    $clientVmLow = strtolower(trim($clientVmStateRaw));
                    if ($clientVmLow === 'running') { $clientVmStateLabel = 'Running'; $clientVmStateTheme = 'success'; }
                    elseif ($clientVmLow === 'off') { $clientVmStateLabel = 'Off'; $clientVmStateTheme = 'secondary'; }
                    elseif ($clientVmLow === 'saved') { $clientVmStateLabel = 'Saved'; $clientVmStateTheme = 'warning'; }
                    else { $clientVmStateLabel = $clientVmStateRaw; $clientVmStateTheme = 'secondary'; }
                } else {
                    $clientVmStateLabel = 'Unknown';
                    $clientVmStateTheme = 'secondary';
                }
            @endphp
            {{-- An active account with no VM on the host is waiting for
                 self-provisioning (hyperv-manual orders activate before the
                 VM exists) — the card follows the VM, not the billing status. --}}
            @if ($isHyperv && in_array($account->status, ['pending', 'active'], true) && ($clientVm['exists'] ?? null) === false)
                <x-adminlte-card icon="bi bi-hdd" title="Provisioning">
                    {{-- Progress panel — visible only while a build runs --}}
                    <div id="client-vm-progress" class="{{ $clientIsRunning ? '' : 'd-none' }}"
                         style="border: 1px solid var(--bs-border-color); border-radius: var(--radius-md); padding: var(--space-2) var(--space-3); margin-bottom: var(--space-2); background: var(--bs-body-bg);">
                        <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-1">
                            <span id="client-vm-progress-label" aria-live="polite" style="font-size: var(--text-sm); font-weight: 600;">{{ $clientStageLabel }}</span>
                            <span id="client-vm-progress-elapsed" class="text-muted" style="font-size: var(--text-xs); font-variant-numeric: tabular-nums;">{{ $clientElapsedFmt }}</span>
                        </div>
                        <div class="progress" style="height: 8px; border-radius: var(--radius-md); background: var(--bs-tertiary-bg);">
                            <div id="client-vm-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated"
                                 role="progressbar"
                                 aria-valuenow="{{ $clientProgress }}" aria-valuemin="0" aria-valuemax="100"
                                 style="width: {{ $clientProgress }}%; background-color: var(--color-primary); transition: width var(--duration-base) var(--ease-default);"></div>
                        </div>
                    </div>
                    {{-- Failure alerts live OUTSIDE the progress panel: the panel
                         is hidden when idle, and a failed build must never be invisible. --}}
                    @if (($clientAct['status'] ?? null) === 'failed' && ! empty($clientAct['error']))
                        <div class="alert alert-danger py-2 px-3 mb-2" style="font-size: var(--text-xs);" role="alert">
                            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Last build failed: {{ $clientAct['error'] }}
                        </div>
                    @endif
                    <div class="mb-2">
                        <span id="client-vm-state" class="badge text-bg-{{ $clientVmStateTheme }}" style="font-size: var(--text-xs); font-weight: 500;" title="Live VM state on the host">{{ $clientVmStateLabel }}</span>
                    </div>
                    @if (empty($clientTemplates) && $clientNoEffective)
                        <p class="text-muted mb-0">This service has no template available — contact support.</p>
                    @elseif (empty($clientTemplates))
                        <p class="text-muted mb-0">This service requires manual setup. Please contact support to provision your VM.</p>
                    @else
                        <form id="client-provision-form" method="POST" action="{{ route('client.hosting.provision', $account) }}">
                            @csrf
                            <div style="margin-bottom: 8px;">
                                <label for="client-template-vm" class="form-label small mb-1">Template VM</label>
                                <select id="client-template-vm" name="template_vm" class="form-select form-select-sm" required aria-label="Template VM">
                                    @foreach ($clientTemplates as $opt)
                                        @php
                                            $optName = is_array($opt) ? ($opt['name'] ?? '') : $opt;
                                            $optLabel = is_array($opt) ? ($opt['label'] ?? $optName) : $opt;
                                        @endphp
                                        <option value="{{ $optName }}" @selected($optName === $clientDefault)>{{ $optLabel }}</option>
                                    @endforeach
                                </select>
                                <div class="form-text small">Template must be shut down (Off)</div>
                                @error('template_vm')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>
                            <div style="margin-top: 8px;">
                                <button id="client-provision-submit" type="submit" class="btn btn-sm btn-primary" @if($clientIsRunning) disabled title="A VM build is already running — please wait." @endif>Provision VM</button>
                            </div>
                        </form>
                    @endif
                </x-adminlte-card>
            @endif

            {{-- Module capability panels (cache-only, client-safe) --}}
            @if (! empty($modulePanels))
                @foreach ($modulePanels as $panel)
                    <div class="mt-3">
                        @include($panel['view'], $panel['data'])
                    </div>
                @endforeach
            @endif

            {{-- The features this service has, resolved by the controller from
                 the order-time snapshot (or the product's option links for
                 services predating it). Only options with an actual value
                 appear — never the full list of values a group offers. --}}
            @if ($configOptions !== [])
                <x-adminlte-card icon="bi bi-sliders" title="Product Configuration">
                    @include('partials._selected_options', [
                        'entries' => $configOptions,
                        'modifiersByLink' => [],
                        'cycle' => $configCycle,
                        'includeUnselected' => false,
                    ])
                </x-adminlte-card>
            @endif
        </div>

        <div class="col-lg-4">
            @php
                $clientCan = is_array(($vmStatus ?? null)['can'] ?? null) ? $vmStatus['can'] : [];
                $clientReasons = is_array(($vmStatus ?? null)['reasons'] ?? null) ? $vmStatus['reasons'] : [];
                $clientVm = is_array(($vmStatus ?? null)['vm'] ?? null) ? $vmStatus['vm'] : [];
                $clientCanReset = (bool) ($clientCan['reset_password'] ?? false);
                $clientResetReason = trim((string) ($clientReasons['reset_password'] ?? ''));
                // Power actions are billing-status aware on the client side:
                // the endpoint refuses anything but an ACTIVE service (a
                // customer must never wake a suspended/terminated VM), so the
                // buttons must mirror that or they would look enabled and then
                // fail with "This service is not active."
                $clientPowerAllowed = $account->status === 'active';
                $clientCanStart = (bool) ($clientCan['start'] ?? false) && $clientPowerAllowed;
                $clientCanStop = (bool) ($clientCan['stop'] ?? false) && $clientPowerAllowed;
                $clientCanRestart = (bool) ($clientCan['restart'] ?? false) && $clientPowerAllowed;
                $clientStartReason = trim((string) ($clientReasons['start'] ?? ''));
                $clientStopReason = trim((string) ($clientReasons['stop'] ?? ''));
                $clientRestartReason = trim((string) ($clientReasons['restart'] ?? ''));
                if (! $clientPowerAllowed) {
                    $clientStartReason = $clientStopReason = $clientRestartReason = 'This service is not active.';
                }
                $clientPowerAction = is_array(($vmStatus ?? null)['action'] ?? null) ? $vmStatus['action'] : null;
                $clientPowerRunning = (bool) ($clientPowerAction['running'] ?? false);
            @endphp
            <x-adminlte-card icon="bi bi-tools" title="Quick Actions">
                @if (($clientVm['probe_error'] ?? '') !== '')
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2" style="font-size: var(--text-xs);">
                        <span class="text-muted">Host unreachable — {{ $clientVm['probe_error'] }}</span>
                        <button type="button" id="client-retry-status" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-clockwise me-1"></i> Retry</button>
                    </div>
                @endif
                @if ($account->status === 'active')
                    <a href="#" class="btn btn-outline-warning w-100 mb-2 disabled" title="Coming soon"><i class="bi bi-pause-circle me-1"></i> Suspend</a>
                @endif
                @if ($isHyperv)
                    <button type="button" class="btn btn-outline-success w-100 mb-2" data-client-hv-action="start"
                            @if(! $clientCanStart || $clientPowerRunning) disabled title="{{ $clientStartReason !== '' ? $clientStartReason : 'Start is not available right now.' }}" @endif
                    ><i class="bi bi-play-circle me-1"></i> Start VM</button>
                    <button type="button" class="btn btn-outline-warning w-100 mb-2" data-client-hv-action="stop"
                            data-bs-toggle="modal" data-bs-target="#client-vm-stop-modal"
                            @if(! $clientCanStop || $clientPowerRunning) disabled title="{{ $clientStopReason !== '' ? $clientStopReason : 'Stop is not available right now.' }}" @endif
                    ><i class="bi bi-stop-circle me-1"></i> Stop VM</button>
                    <button type="button" class="btn btn-outline-warning w-100 mb-2" data-client-hv-action="restart"
                            data-bs-toggle="modal" data-bs-target="#client-vm-restart-modal"
                            @if(! $clientCanRestart || $clientPowerRunning) disabled title="{{ $clientRestartReason !== '' ? $clientRestartReason : 'Restart is not available right now.' }}" @endif
                    ><i class="bi bi-arrow-clockwise me-1"></i> Restart VM</button>
                    {{-- Start posts directly (safe): no confirm needed, the form
                         below only carries the action + CSRF for postForm(). --}}
                    <form id="client-vm-start-form" method="POST" action="{{ route('client.hosting.vm-power', $account) }}" class="d-none" aria-hidden="true">
                        @csrf
                        <input type="hidden" name="action" value="start">
                    </form>
                    <button type="button" class="btn btn-outline-info w-100 mb-2" data-client-hv-action="reset_password"
                            data-bs-toggle="modal" data-bs-target="#client-reset-password-modal"
                            @if(! $clientCanReset) disabled title="{{ $clientResetReason !== '' ? $clientResetReason : 'Password reset is not available right now.' }}" @endif
                    ><i class="bi bi-key me-1"></i> Reset Administrator password</button>
                @else
                    <a href="#" class="btn btn-outline-info w-100 mb-2 disabled" title="Coming soon"><i class="bi bi-key me-1"></i> Change Password</a>
                @endif
                <a href="#" class="btn btn-outline-info w-100 mb-2 disabled" title="Coming soon"><i class="bi bi-envelope me-1"></i> Manage Emails</a>
            </x-adminlte-card>

            @if ($isHyperv)
                <x-adminlte.partials.confirm-modal id="client-reset-password-modal" title="Reset Administrator password"
                    :message="'Reset the Administrator password inside the running guest. The VM must be running.'"
                    :action="route('client.hosting.reset-vm-password', $account)" method="POST"
                    confirm-label="Reset password" confirm-theme="primary">
                    <x-slot name="fields">
                        <div class="text-start" style="margin-bottom: var(--space-2);">
                            <label for="client-reset-password" class="form-label small mb-1">New password</label>
                            <input id="client-reset-password" name="password" type="password" class="form-control form-control-sm"
                                   autocomplete="new-password" required minlength="8" aria-label="New password">
                        </div>
                        <div class="text-start" style="margin-bottom: var(--space-2);">
                            <label for="client-reset-password-confirm" class="form-label small mb-1">Confirm new password</label>
                            <input id="client-reset-password-confirm" name="password_confirmation" type="password" class="form-control form-control-sm"
                                   autocomplete="new-password" required minlength="8" aria-label="Confirm new password">
                        </div>
                    </x-slot>
                </x-adminlte.partials.confirm-modal>
            @endif

            @if ($isHyperv)
                <x-adminlte.partials.confirm-modal id="client-vm-stop-modal" title="Stop VM"
                    :message="'Gracefully shut down this VM? The guest OS is asked to shut down — no force is sent.'"
                    :action="route('client.hosting.vm-power', $account)" method="POST"
                    confirm-label="Stop VM" confirm-theme="warning">
                    <x-slot name="fields">
                        <input type="hidden" name="action" value="stop">
                    </x-slot>
                </x-adminlte.partials.confirm-modal>
            @endif

            @if ($isHyperv)
                <x-adminlte.partials.confirm-modal id="client-vm-restart-modal" title="Restart VM"
                    :message="'Reboot this VM? Only a RUNNING VM is rebooted — a stopped VM is refused, never surprise-started. Type ' . $account->host_name . ' to confirm.'"
                    :action="route('client.hosting.vm-power', $account)" method="POST"
                    confirm-label="Restart" confirm-theme="warning">
                    <x-slot name="fields">
                        <input type="hidden" name="action" value="restart">
                        <div class="mt-2 text-start">
                            <label class="form-label small mb-1" for="client-restart-confirm">Type <code>{{ $account->host_name }}</code> to confirm</label>
                            <input id="client-restart-confirm" name="confirm" aria-label="Type {{ $account->host_name }} to confirm restart" class="form-control form-control-sm" autocomplete="off" required placeholder="{{ $account->host_name }}">
                        </div>
                    </x-slot>
                </x-adminlte.partials.confirm-modal>
            @endif

            @if ($billing !== null)
                @php
                    $cycleLabels = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'semi_annual' => 'Semi-Annual',
                        'annual' => 'Annual', 'biennial' => 'Biennial', 'one_time' => 'One-Time'];
                @endphp
                <x-adminlte-card icon="bi bi-receipt" title="Billing">
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <th class="text-muted">Amount</th>
                            <td class="text-end"><strong>{{ number_format((float) ($billing['amount'] ?? 0), 2) }}</strong></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Cycle</th>
                            <td class="text-end">{{ $cycleLabels[$billing['cycle'] ?? ''] ?? ($billing['cycle'] ?? '—') }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">Next Due</th>
                            <td class="text-end">{{ $billing['next_billing_date']?->format('M j, Y') ?? '—' }}</td>
                        </tr>
                        @if ($account->order)
                            <tr>
                                <th class="text-muted">Order</th>
                                <td class="text-end">{{ $account->order->order_no }}</td>
                            </tr>
                        @endif
                    </table>
                </x-adminlte-card>
            @endif

            @if ($account->ipAddresses->isNotEmpty())
                <x-adminlte-card icon="bi bi-globe2" title="IP Addresses">
                    <ul class="list-unstyled mb-0">
                        @foreach ($account->ipAddresses as $ip)
                            <li class="d-flex justify-content-between align-items-center py-1">
                                <code>{{ $ip->ip_address }}</code>
                                <span class="badge text-bg-{{ $ip->type === 'public' ? 'primary' : 'secondary' }}">
                                    {{ ucfirst($ip->type) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </x-adminlte-card>
            @endif
        </div>
    </div>
@stop

@push('js')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var statusUrl = @json($isHyperv ? route('client.hosting.vm-status', $account) : null);
    var resetUrl = @json($isHyperv ? route('client.hosting.reset-vm-password', $account) : null);
    var powerUrl = @json($isHyperv ? route('client.hosting.vm-power', $account) : null);
    var initialRunning = @json($isHyperv ? $clientIsRunning : false);
    var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || @json(csrf_token());

    var progressPanel = document.getElementById('client-vm-progress');
    var progressBar = document.getElementById('client-vm-progress-bar');
    var progressLabel = document.getElementById('client-vm-progress-label');
    var progressElapsed = document.getElementById('client-vm-progress-elapsed');
    var vmStatePill = document.getElementById('client-vm-state');
    var provisionForm = document.getElementById('client-provision-form');
    var provisionSubmit = document.getElementById('client-provision-submit');
    var resetModal = document.getElementById('client-reset-password-modal');

    var pollTimer = null;

    function fmtElapsed(sec) {
        sec = Math.max(0, parseInt(sec, 10) || 0);
        var m = Math.floor(sec / 60);
        var s = sec % 60;
        return (m < 10 ? '0' + m : '' + m) + ':' + (s < 10 ? '0' + s : '' + s);
    }

    function toastSuccess(msg) {
        if (window.toastr && typeof window.toastr.success === 'function') {
            try { window.toastr.success(msg); } catch (e) {}
        }
    }
    function toastError(msg) {
        if (window.toastr && typeof window.toastr.error === 'function') {
            try { window.toastr.error(msg); } catch (e) {}
        }
    }
    function toastWarning(msg) {
        if (window.toastr && typeof window.toastr.warning === 'function') {
            try { window.toastr.warning(msg); } catch (e) {}
        } else {
            toastSuccess(msg);
        }
    }

    function vmStateLabel(vm) {
        if (!vm) return { label: 'Unknown', theme: 'secondary' };
        if (vm.exists === false) return { label: 'Not created', theme: 'secondary' };
        var raw = (vm.state || '').toString();
        var low = raw.toLowerCase().trim();
        if (low === 'running') return { label: 'Running', theme: 'success' };
        if (low === 'off') return { label: 'Off', theme: 'secondary' };
        if (low === 'saved') return { label: 'Saved', theme: 'warning' };
        if (raw) return { label: raw, theme: 'secondary' };
        return { label: 'Unknown', theme: 'secondary' };
    }

    function applyStatus(status) {
        if (!status || typeof status !== 'object') return;
        var action = status.action || null;
        var vm = status.vm || null;
        var running = !!(action && action.running);
        if (progressPanel) {
            if (running) progressPanel.classList.remove('d-none');
            else progressPanel.classList.add('d-none');
        }
        if (progressLabel) {
            var label = (action && (action.stage_label || action.stage)) ? (action.stage_label || action.stage) : (running ? 'Working…' : '');
            if (running || label) progressLabel.textContent = label || 'Working…';
        }
        if (progressElapsed && action) progressElapsed.textContent = fmtElapsed(action.elapsed || 0);
        if (progressBar && action) {
            var pct = Math.max(0, Math.min(100, parseInt(action.progress, 10) || 0));
            progressBar.style.width = pct + '%';
            progressBar.setAttribute('aria-valuenow', String(pct));
        }
        if (vmStatePill) {
            var st = vmStateLabel(vm);
            vmStatePill.textContent = st.label;
            vmStatePill.className = 'badge text-bg-' + st.theme;
        }
        if (provisionSubmit) {
            provisionSubmit.disabled = running;
            if (running) provisionSubmit.setAttribute('title', 'A VM build is already running — please wait.');
            else provisionSubmit.removeAttribute('title');
        }
    }

    function pollOnce() {
        if (!statusUrl) return Promise.resolve();
        return fetch(statusUrl, {
            method: 'GET',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function (res) {
            return res.json().catch(function () { return null; }).then(function (data) {
                if (!data || data.ok === false) throw new Error((data && data.message) || 'Status check failed');
                applyStatus(data);
                var act = data.action || null;
                var running = !!(act && act.running);
                if (!running) {
                    stopPolling();
                    if (act) {
                        if (act.status === 'failed') {
                            toastError(act.error || act.message || 'Build failed.');
                        } else if (act.status === 'completed') {
                            var msg = (act.message || 'Done.').toString();
                            if (msg.toLowerCase().indexOf('failed to start') !== -1) toastWarning(msg);
                            else toastSuccess(msg);
                        }
                    }
                    setTimeout(function () { window.location.reload(); }, 1000);
                }
            });
        }).catch(function () { /* transient poll error — keep polling */ });
    }

    function startPolling() {
        stopPolling();
        if (!statusUrl) return;
        pollTimer = setInterval(function () { pollOnce(); }, 3000);
        setTimeout(function () { pollOnce(); }, 800);
    }
    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }
    window.addEventListener('beforeunload', function () { stopPolling(); });

    // If rendered while a build runs, show the panel + disable + poll now.
    if (initialRunning) startPolling();

    function postForm(url, form, submitBtn) {
        var fd = new FormData(form);
        var origLabel = submitBtn ? submitBtn.innerHTML : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Working…';
        }
        return fetch(url, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: fd
        }).then(function (res) {
            return res.text().then(function (text) {
                var data = null;
                try { data = JSON.parse(text); } catch (e) { data = null; }
                if (!res.ok) {
                    var msg = (data && (data.message || data.error)) ? (data.message || data.error) : ('Request failed (' + res.status + ').');
                    if (data && data.errors && typeof data.errors === 'object') {
                        var first = Object.values(data.errors)[0];
                        if (Array.isArray(first) && first[0]) msg = first[0];
                    }
                    throw new Error(msg);
                }
                if (!data) throw new Error('Empty response from server.');
                return data;
            });
        }).finally(function () {
            if (submitBtn && submitBtn !== provisionSubmit) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = origLabel;
            }
        });
    }

    function showResetAlert(message) {
        if (!resetModal) return;
        var body = resetModal.querySelector('.modal-body');
        if (!body) return;
        var existing = body.querySelector('[data-client-inline-alert]');
        if (existing) existing.remove();
        var el = document.createElement('div');
        el.setAttribute('data-client-inline-alert', '1');
        el.className = 'alert alert-danger py-2 px-3 mt-2 mb-0';
        el.setAttribute('role', 'alert');
        el.setAttribute('tabindex', '-1');
        el.textContent = message;
        body.appendChild(el);
        try { el.focus(); } catch (e) {}
    }
    function clearResetAlert() {
        if (!resetModal) return;
        var a = resetModal.querySelector('[data-client-inline-alert]');
        if (a) a.remove();
    }

    // Provisioning form: async start + poll (degrades to plain POST without JS fetch).
    if (provisionForm && provisionSubmit) {
        provisionForm.addEventListener('submit', function (ev) {
            if (!provisionForm.checkValidity()) return; // let the browser show native validation
            ev.preventDefault();
            if (provisionSubmit) {
                provisionSubmit.disabled = true;
                provisionSubmit.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Starting…';
            }
            if (progressPanel) {
                progressPanel.classList.remove('d-none');
                if (progressLabel) progressLabel.textContent = 'Starting…';
                if (progressBar) { progressBar.style.width = '4%'; progressBar.setAttribute('aria-valuenow', '4'); }
            }
            postForm(provisionForm.getAttribute('action'), provisionForm, null).then(function (data) {
                var ok = data.ok !== undefined ? !!data.ok : true;
                if (!ok) {
                    toastError(data.message || data.error || 'Provisioning failed.');
                    return pollOnce().catch(function () {});
                }
                toastSuccess(data.message || 'Your VM build has started.');
                startPolling();
            }).catch(function (err) {
                toastError((err && err.message) ? err.message : 'Network error.');
                pollOnce().catch(function () {});
            });
        });
    }

    // Reset-password modal form: toast + close, no reload; refresh status once.
    if (resetModal) {
        var resetForm = resetModal.querySelector('form');
        if (resetForm) {
            resetForm.addEventListener('submit', function (ev) {
                if (!resetForm.checkValidity()) return;
                ev.preventDefault();
                var pw = resetForm.querySelector('[name="password"]');
                var pw2 = resetForm.querySelector('[name="password_confirmation"]');
                if (pw && pw2 && pw.value !== pw2.value) {
                    showResetAlert('Passwords do not match.');
                    toastError('Passwords do not match.');
                    return;
                }
                clearResetAlert();
                var submitBtn = resetModal.querySelector('[type="submit"]');
                var url = resetForm.getAttribute('action') || resetUrl;
                postForm(url, resetForm, submitBtn).then(function (data) {
                    var ok = data.ok !== undefined ? !!data.ok : true;
                    if (!ok) {
                        showResetAlert(data.message || data.error || 'Password reset failed.');
                        toastError(data.message || data.error || 'Password reset failed.');
                        return;
                    }
                    // Never render the password back — the customer typed it.
                    resetForm.reset();
                    toastSuccess(data.message || 'Administrator password reset.');
                    try {
                        var inst = window.bootstrap && window.bootstrap.Modal ? window.bootstrap.Modal.getInstance(resetModal) : null;
                        if (inst) inst.hide();
                        else if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(resetModal).hide();
                    } catch (e) {}
                    if (statusUrl) {
                        fetch(statusUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                            .then(function (r) { return r.json().catch(function () { return null; }); })
                            .then(function (s) { if (s && (s.vm || s.can)) applyStatus(s); });
                    }
                }).catch(function (err) {
                    var msg = (err && err.message) ? err.message : 'Network error.';
                    showResetAlert(msg);
                    toastError(msg);
                });
            });
        }
    }
    // Customer power actions: Start posts directly (safe); Stop posts from
    // its confirm modal. Both toast + reload on success, toast-only (page
    // stays usable) on failure.
    var startBtn = document.querySelector('[data-client-hv-action="start"]');
    var startForm = document.getElementById('client-vm-start-form');
    var stopModal = document.getElementById('client-vm-stop-modal');

    function reloadSoon() {
        setTimeout(function () { window.location.reload(); }, 800);
    }

    if (startBtn && startForm && powerUrl) {
        startBtn.addEventListener('click', function () {
            postForm(startForm.getAttribute('action') || powerUrl, startForm, startBtn).then(function (data) {
                var ok = data.ok !== undefined ? !!data.ok : true;
                if (!ok) {
                    toastError(data.message || data.error || 'Start failed.');
                    return;
                }
                toastSuccess(data.message || 'VM started.');
                reloadSoon();
            }).catch(function (err) {
                toastError((err && err.message) ? err.message : 'Network error.');
            });
        });
    }

    if (stopModal) {
        var stopForm = stopModal.querySelector('form');
        if (stopForm) {
            stopForm.addEventListener('submit', function (ev) {
                ev.preventDefault();
                var submitBtn = stopModal.querySelector('[type="submit"]');
                var url = stopForm.getAttribute('action') || powerUrl;
                postForm(url, stopForm, submitBtn).then(function (data) {
                    var ok = data.ok !== undefined ? !!data.ok : true;
                    if (!ok) {
                        toastError(data.message || data.error || 'Stop failed.');
                        return;
                    }
                    toastSuccess(data.message || 'VM stopped.');
                    try {
                        var inst = window.bootstrap && window.bootstrap.Modal ? window.bootstrap.Modal.getInstance(stopModal) : null;
                        if (inst) inst.hide();
                        else if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(stopModal).hide();
                    } catch (e) {}
                    reloadSoon();
                }).catch(function (err) {
                    toastError((err && err.message) ? err.message : 'Network error.');
                });
            });
        }
    }

    // Restart VM: typed confirmation modal, reuse postForm.
    var restartModal = document.getElementById('client-vm-restart-modal');
    function showRestartAlert(message) {
        if (!restartModal) return;
        var body = restartModal.querySelector('.modal-body');
        if (!body) return;
        var existing = body.querySelector('[data-client-restart-alert]');
        if (existing) existing.remove();
        var el = document.createElement('div');
        el.setAttribute('data-client-restart-alert', '1');
        el.className = 'alert alert-danger py-2 px-3 mt-2 mb-0';
        el.setAttribute('role', 'alert');
        el.setAttribute('tabindex', '-1');
        el.textContent = message;
        body.appendChild(el);
        try { el.focus(); } catch (e) {}
    }
    function clearRestartAlert() {
        if (!restartModal) return;
        var a = restartModal.querySelector('[data-client-restart-alert]');
        if (a) a.remove();
    }
    if (restartModal) {
        var restartForm = restartModal.querySelector('form');
        if (restartForm) {
            restartForm.addEventListener('submit', function (ev) {
                ev.preventDefault();
                if (!restartForm.checkValidity()) { restartForm.reportValidity(); return; }
                clearRestartAlert();
                var submitBtn = restartModal.querySelector('[type="submit"]');
                var url = restartForm.getAttribute('action') || powerUrl;
                postForm(url, restartForm, submitBtn).then(function (data) {
                    var ok = data.ok !== undefined ? !!data.ok : true;
                    if (!ok) {
                        var msg = data.message || data.error || 'Restart failed.';
                        showRestartAlert(msg);
                        toastError(msg);
                        return;
                    }
                    toastSuccess(data.message || 'VM restarted.');
                    try {
                        var inst = window.bootstrap && window.bootstrap.Modal ? window.bootstrap.Modal.getInstance(restartModal) : null;
                        if (inst) inst.hide();
                        else if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(restartModal).hide();
                    } catch (e) {}
                    reloadSoon();
                }).catch(function (err) {
                    var msg = (err && err.message) ? err.message : 'Network error.';
                    showRestartAlert(msg);
                    toastError(msg);
                });
            });
        }
    }

    // Retry status: re-probe the host with ?refresh=1, applyStatus without polling.
    var retryBtn = document.getElementById('client-retry-status');
    if (retryBtn && statusUrl) {
        retryBtn.addEventListener('click', function () {
            var orig = retryBtn.innerHTML;
            retryBtn.disabled = true;
            retryBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Retrying…';
            fetch(statusUrl + '?refresh=1', {
                method: 'GET',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            }).then(function (res) {
                return res.json().catch(function () { return null; }).then(function (payload) {
                    if (!payload) throw new Error('Status check failed');
                    var data = payload.data ? payload.data : payload;
                    applyStatus(data);
                    var probeError = data.vm ? data.vm.probe_error : (data.probe_error || null);
                    if (probeError && String(probeError) !== '') {
                        toastError('Host still unreachable: ' + probeError);
                    } else if (data.ok === false) {
                        toastError(data.message || 'Status check failed');
                    } else {
                        toastSuccess('Status refreshed.');
                    }
                });
            }).catch(function (err) {
                toastError((err && err.message) ? err.message : 'Retry failed.');
            }).finally(function () {
                retryBtn.disabled = false;
                retryBtn.innerHTML = orig;
            });
        });
    }
});
</script>
@endpush
