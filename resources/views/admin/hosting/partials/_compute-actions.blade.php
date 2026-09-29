@php
    /**
     * Shared admin card for compute modules that are not Hyper-V (currently
     * Proxmox VE; Virtualizor when it gains template discovery).
     *
     * Contract, set by Admin\HostingController::show():
     *   slug, name, mode, templateKey, templateLabel, options[], default,
     *   curatedCount, noEffective, canRestart, startAfterCreateDefault
     *
     * Uses the same frozen endpoints as the Hyper-V card: POST module-action
     * for verbs, GET vm-status for live state/progress, POST reset-vm-password
     * for password resets, GET vm-credentials for the on-demand reveal.
     */
    $computeSlug = (string) ($slug ?? '');
    $computeOptions = is_array($options ?? null) ? $options : [];
    $computeOptionIds = array_map(static fn (array $o): string => (string) $o['id'], $computeOptions);
    $computeDefault = (string) ($default ?? '');
    if ($computeDefault === '' && $computeOptionIds !== []) {
        $computeDefault = $computeOptionIds[0];
    }
    $computeCuratedCount = (int) ($curatedCount ?? 0);
    $computeNoEffective = (bool) ($noEffective ?? false);
    $computeCanRestart = (bool) ($canRestart ?? false);
    $computeStartAfterCreate = (bool) ($startAfterCreateDefault ?? true);
    $computeTemplateKey = (string) ($templateKey ?? 'template');
    $computeTemplateLabel = (string) ($templateLabel ?? 'Template');
    $computeName = (string) ($name ?? $computeSlug);
    $computeMode = (string) ($mode ?? 'auto');

    $computeVmStatus = $vmStatus ?? null;
    $computeAct = is_array($computeVmStatus['action'] ?? null) ? $computeVmStatus['action'] : null;
    $computeVm = is_array($computeVmStatus['vm'] ?? null) ? $computeVmStatus['vm'] : null;
    $computeCan = is_array($computeVmStatus['can'] ?? null) ? $computeVmStatus['can'] : [];
    $computeIsRunning = (bool) ($computeAct['running'] ?? false);
    $computeProgress = max(0, min(100, (int) ($computeAct['progress'] ?? 0)));
    $computeStageLabel = trim((string) ($computeAct['stage_label'] ?? ($computeAct['stage'] ?? 'Working…')));
    if ($computeStageLabel === '') { $computeStageLabel = 'Working…'; }
    $computeElapsed = (int) ($computeAct['elapsed'] ?? 0);
    $computeElapsedFmt = sprintf('%02d:%02d', intdiv($computeElapsed, 60), $computeElapsed % 60);

    $computeStateRaw = $computeVm['state'] ?? null;
    if (($computeVm['exists'] ?? null) === false) {
        $computeStateLabel = 'Not created';
    } elseif (is_string($computeStateRaw) && $computeStateRaw !== '') {
        $computeLow = strtolower(trim($computeStateRaw));
        if ($computeLow === 'running') { $computeStateLabel = 'Running'; }
        elseif ($computeLow === 'off' || $computeLow === 'stopped') { $computeStateLabel = 'Off'; }
        elseif ($computeLow === 'saved') { $computeStateLabel = 'Saved'; }
        else { $computeStateLabel = $computeStateRaw; }
    } else {
        $computeStateLabel = 'Unknown';
    }

    // Mirrors applyStatus() below: the gate is the presenter's can.create.
    // (A template lookup failure must not strand the operator — with no
    // curated options the section below says so and still submits.)
    $computeCanCreate = (bool) ($computeCan['create'] ?? false);
    $computeCanStart = (bool) ($computeCan['start'] ?? false);
    $computeCanStop = (bool) ($computeCan['stop'] ?? false);
    $computeCanDelete = (bool) ($computeCan['delete'] ?? false);
    $computeProbeError = trim((string) ($computeVm['probe_error'] ?? ''));

    // Password-reset + credentials-reveal gates, mirroring the Hyper-V card.
    // The stored password is never server-rendered (bullets + on-demand
    // fetch); only the stored flag and the username reach this HTML.
    $computeReasons = is_array($computeVmStatus['reasons'] ?? null) ? $computeVmStatus['reasons'] : [];
    $computeCredentials = is_array($computeVmStatus['credentials'] ?? null) ? $computeVmStatus['credentials'] : [];
    $computeCredUsername = trim((string) ($computeCredentials['username'] ?? 'root'));
    if ($computeCredUsername === '') { $computeCredUsername = 'root'; }
    $computeCredsStored = (bool) ($computeCredentials['stored'] ?? false);
    $computeCanReset = (bool) ($computeCan['reset_password'] ?? false);
    $computeResetReason = trim((string) ($computeReasons['reset_password'] ?? ''));
    $computeResetTitle = $computeIsRunning ? 'An action is already running — please wait.' : $computeResetReason;
    $computeCredsTitle = $computeIsRunning
        ? 'An action is already running — please wait.'
        : (! $computeCredsStored ? 'No credentials are stored for this VM.' : '');

    // ── VM Console gate (presentation only, Proxmox only — mirrors the
    // Hyper-V card). The PVE console needs a VM id (the presenter's
    // live-probe vmId), a configured Proxmox server on the account, and a
    // configured console gateway. The token endpoint re-resolves all of
    // these server-side and fails closed regardless — this only decides
    // whether the link is offered, and the visible reason when it is not.
    $computeConsoleIsProxmox = $computeSlug === 'proxmox';
    $computeConsoleRouteExists = \Illuminate\Support\Facades\Route::has('admin.rdp-console.pveConsole');
    $computeConsoleGatewayConfigured = strlen(trim((string) config('rdp-console.secret'))) >= 16;
    $computeConsoleServer = $hostingAccount->server ?? null;
    $computeConsoleServerConfigured = $computeConsoleServer !== null
        && \App\Modules\Proxmox\Services\ProxmoxClient::isConfigured($computeConsoleServer);
    $computeConsoleVmId = trim((string) ($computeVmStatus['vm']['vmId'] ?? ''));
    $computeConsoleReason = null;
    if (! $computeConsoleRouteExists) {
        $computeConsoleReason = 'The VM console is unavailable — the rdp-console module is not active.';
    } elseif (! $computeConsoleGatewayConfigured) {
        $computeConsoleReason = 'The console gateway is not configured — set GUACAMOLE_SECRET.';
    } elseif (! $computeConsoleServerConfigured) {
        $computeConsoleReason = 'No Proxmox server credentials are configured for this service.';
    } elseif ($computeConsoleVmId === '') {
        $computeConsoleReason = 'No VM ID is recorded for this VM yet.';
    } elseif ($computeIsRunning) {
        $computeConsoleReason = 'An action is already running — please wait.';
    }
    $computeConsoleAvailable = $computeConsoleIsProxmox && $computeConsoleReason === null;

    // ── Status strip: the single refusal surface. Reads the presenter's
    // additive `notice` key defensively — the card is also rendered
    // standalone with a hand-crafted $vmStatus that has no `notice`
    // (ProxmoxComputeCardUiTest), so a missing key must never crash and the
    // fallback below derives only from can/reasons/vm/account status.
    $computeNoticeRaw = is_array($computeVmStatus['notice'] ?? null) ? $computeVmStatus['notice'] : null;
    $computeNoticeText = $computeNoticeRaw !== null ? trim((string) ($computeNoticeRaw['text'] ?? '')) : '';
    $computeNoticeSeverity = $computeNoticeRaw !== null ? strtolower(trim((string) ($computeNoticeRaw['severity'] ?? ''))) : '';
    if (! in_array($computeNoticeSeverity, ['danger', 'warning', 'info'], true)) {
        $computeNoticeSeverity = '';
    }

    // Terminal failure verdict, computed early. A failed latest action stays
    // visible across a reload (the old alert-danger guarantee), but only
    // while no VM is observed on the host: once a VM exists, live state
    // supersedes the verdict and the event history owns the past.
    // When the presenter offers a notice of its own, the strip carries the
    // state message first and the verdict on its own muted line below it —
    // so neither B.2 (notice text) nor B6 (failure text) is dropped, and the
    // state message leads. One strip, one severity.
    $computeFailureText = (! $computeIsRunning && ($computeAct['status'] ?? null) === 'failed')
        ? trim((string) ($computeAct['error'] ?? $computeAct['message'] ?? ''))
        : '';
    $computeFailureRelevant = $computeFailureText !== '' && ($computeVm['exists'] ?? null) !== true;
    $computeFailureSuffix = '';
    if ($computeFailureRelevant && $computeNoticeText !== '' && $computeFailureText !== $computeNoticeText) {
        $computeFailureSuffix = $computeFailureText;
        $computeNoticeSeverity = 'danger';
    } elseif ($computeFailureRelevant && $computeNoticeText === '') {
        $computeNoticeText = $computeFailureText;
        $computeNoticeSeverity = 'danger';
    }

    // Fallback precedence mirrors the presenter's permissions() order:
    // probe failure > terminated > VM absent > state unknown. Steady states
    // (running, stopped) and a running action render no strip — the state
    // chip and the open progress panel already answer those.
    if ($computeNoticeText === '' && ! $computeIsRunning) {
        if ($computeProbeError !== '') {
            $computeNoticeText = trim((string) ($computeReasons['create'] ?? $computeReasons['start'] ?? ''));
            if ($computeNoticeText === '') { $computeNoticeText = 'Host unreachable — actions may fail.'; }
            $computeNoticeSeverity = 'warning';
        } elseif (strtolower(trim((string) ($hostingAccount->status ?? ''))) === 'terminated') {
            $computeNoticeText = trim((string) ($computeReasons['create'] ?? ''));
            if ($computeNoticeText === '') { $computeNoticeText = 'Service is terminated — create, start, stop and restart are refused. Delete cleans up; restoring the account re-enables provisioning.'; }
            $computeNoticeSeverity = 'danger';
        } elseif (($computeVm['exists'] ?? null) === false) {
            $computeNoticeText = 'No VM exists on the host yet — create one first.';
            $computeNoticeSeverity = 'info';
        } elseif (($computeVm['exists'] ?? null) === null) {
            $computeNoticeText = trim((string) ($computeReasons['start'] ?? ''));
            if ($computeNoticeText === '') { $computeNoticeText = 'VM state is unknown — refresh the page or re-test the connection.'; }
            $computeNoticeSeverity = 'warning';
        }
    }
    if ($computeNoticeText !== '' && $computeNoticeSeverity === '') { $computeNoticeSeverity = 'info'; }
    $computeNoticeId = 'compute-notice-' . $computeSlug;
    $computeNoticeAlert = 'alert-' . ($computeNoticeSeverity !== '' ? $computeNoticeSeverity : 'info');
    $computeNoticeIcon = $computeNoticeSeverity === 'danger' ? 'bi-exclamation-triangle-fill' : ($computeNoticeSeverity === 'warning' ? 'bi-exclamation-triangle-fill' : 'bi-info-circle');

    // Server-rendered titles mirror what applyStatus() sets on poll, so a
    // disabled button explains itself before the first poll lands.
    $computeRunningTitle = 'An action is already running — please wait.';
    $computeTitles = [];
    foreach (['create', 'start', 'stop', 'delete', 'reset_password'] as $computeTitleAct) {
        $computeTitles[$computeTitleAct] = $computeIsRunning
            ? $computeRunningTitle
            : trim((string) ($computeReasons[$computeTitleAct] ?? ''));
    }
    // Restart has no can key of its own; its gate is exists === true.
    $computeTitles['restart'] = $computeIsRunning
        ? $computeRunningTitle
        : trim((string) ($computeReasons['restart'] ?? ''));
    if ($computeTitles['restart'] === '' && ($computeVm['exists'] ?? null) !== true) {
        $computeTitles['restart'] = 'VM is not created on the host yet.';
    }

    // Disabled buttons point at the strip when one is rendered.
    $computeDescribedBy = $computeNoticeText !== '' ? $computeNoticeId : '';

    // Honest disabled hierarchy (presentation only): a disabled action
    // renders its outline variant so it recedes, an enabled action keeps
    // its solid variant. Each mirrors its `disabled` condition below
    // exactly — gating itself is untouched.
    $computeEnabledCreate = $computeCanCreate && ! $computeIsRunning;
    $computeEnabledStart = $computeCanStart && ! $computeIsRunning;
    $computeEnabledStop = $computeCanStop && ! $computeIsRunning;
    $computeEnabledDelete = $computeCanDelete && ! $computeIsRunning;
    $computeEnabledReset = $computeCanReset && ! $computeIsRunning;
    $computeEnabledCreds = $computeCredsStored && ! $computeIsRunning;
    $computeEnabledRestart = $computeCanRestart && ! $computeIsRunning && ($computeVm['exists'] ?? null) === true;
@endphp
<div class="ma-entry" id="compute-panel-{{ $computeSlug }}">
    <div class="d-flex flex-wrap align-items-center gap-2 py-2" data-compute-identity>
        <strong>{{ $computeName }}</strong>
        <span class="text-muted small">{{ $computeSlug }}</span>
        <span class="badge {{ $computeMode === 'manual' ? 'text-bg-warning' : 'text-bg-success' }}">{{ ucfirst($computeMode) }}</span>
        <x-adminlte.partials.status-badge :status="strtolower($computeStateLabel)" :label="$computeStateLabel" :map="['running' => 'success']" id="compute-state-{{ $computeSlug }}" />
    </div>

    @if ($computeNoticeText !== '')
        <div id="{{ $computeNoticeId }}" data-compute-notice class="alert {{ $computeNoticeAlert }} mh-module-notice py-2 px-3 my-2" role="status" aria-live="polite">
            <i class="bi {{ $computeNoticeIcon }} flex-shrink-0" aria-hidden="true" data-compute-notice-icon></i>
            <span class="mh-module-notice__body">
                <span data-compute-hint>{{ $computeNoticeText }}</span>
                @if ($computeFailureSuffix !== '')
                    <span class="mh-module-notice__failure">Last action failed: {{ $computeFailureSuffix }}</span>
                @endif
            </span>
        </div>
    @endif

    <div class="alert py-2 px-3 my-2 d-none" role="alert" data-compute-feedback></div>

    @can('hosting.edit')
        <div class="mh-module-actions py-1">
            <div class="mh-module-group">
                <div class="mh-module-group__title">Lifecycle</div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-sm {{ $computeEnabledCreate ? 'btn-success' : 'btn-outline-success' }}" data-compute-action="create"
                            @if(! $computeCanCreate || $computeIsRunning) disabled @endif
                            @if($computeTitles['create'] !== '') title="{{ $computeTitles['create'] }}" @endif
                            @if($computeDescribedBy !== '') aria-describedby="{{ $computeDescribedBy }}" @endif><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Create VM</button>
                    <button type="button" class="btn btn-sm {{ $computeEnabledStart ? 'btn-primary' : 'btn-outline-primary' }}" data-compute-action="start"
                            @if(! $computeCanStart || $computeIsRunning) disabled @endif
                            @if($computeTitles['start'] !== '') title="{{ $computeTitles['start'] }}" @endif
                            @if($computeDescribedBy !== '') aria-describedby="{{ $computeDescribedBy }}" @endif><i class="bi bi-play-fill me-1" aria-hidden="true"></i>Start</button>
                    <button type="button" class="btn btn-sm {{ $computeEnabledStop ? 'btn-warning' : 'btn-outline-warning' }}" data-compute-action="stop"
                            @if(! $computeCanStop || $computeIsRunning) disabled @endif
                            @if($computeTitles['stop'] !== '') title="{{ $computeTitles['stop'] }}" @endif
                            @if($computeDescribedBy !== '') aria-describedby="{{ $computeDescribedBy }}" @endif><i class="bi bi-stop-fill me-1" aria-hidden="true"></i>Stop</button>
                    @if ($computeCanRestart)
                        <button type="button" class="btn btn-sm {{ $computeEnabledRestart ? 'btn-warning' : 'btn-outline-warning' }}" data-compute-action="restart"
                                @if($computeIsRunning || ($computeVm['exists'] ?? null) !== true) disabled @endif
                                @if($computeTitles['restart'] !== '') title="{{ $computeTitles['restart'] }}" @endif
                                @if($computeDescribedBy !== '') aria-describedby="{{ $computeDescribedBy }}" @endif><i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Restart</button>
                    @endif
                </div>
            </div>
            <div class="mh-module-group">
                <div class="mh-module-group__title">Access</div>
                <div class="d-flex flex-wrap gap-2">
                    {{-- Credentials reveal: fetched on demand, never server-rendered --}}
                    <button type="button" class="btn btn-sm {{ $computeEnabledCreds ? 'btn-secondary' : 'btn-outline-secondary' }}" data-compute-action="credentials"
                            @if(! $computeCredsStored || $computeIsRunning) disabled @endif
                            @if($computeCredsTitle !== '') title="{{ $computeCredsTitle }}" @endif
                            @if($computeDescribedBy !== '') aria-describedby="{{ $computeDescribedBy }}" @endif><i class="bi bi-person-badge me-1" aria-hidden="true"></i>Credentials</button>
                    <button type="button" class="btn btn-sm {{ $computeEnabledReset ? 'btn-secondary' : 'btn-outline-secondary' }}" data-compute-action="reset_password"
                            @if(! $computeCanReset || $computeIsRunning) disabled @endif
                            @if($computeResetTitle !== '') title="{{ $computeResetTitle }}" @endif
                            @if($computeDescribedBy !== '') aria-describedby="{{ $computeDescribedBy }}" @endif><i class="bi bi-key-fill me-1" aria-hidden="true"></i>Reset password</button>
                    {{-- VM Console: opens the Proxmox VNC console owned by the
                         rdp-console module (route only exists while that module is
                         active). Manage-gated because it is interactive control —
                         the same capability class as the Hyper-V VMConnect console.
                         A disabled button is not focusable, so the reason is
                         rendered as a muted line below the buttons (inside this
                         group, next to VM Console) — never inline, where it
                         would widen the group and push Danger off the row. --}}
                    @if ($computeConsoleIsProxmox)
                        @can('hosting.manage')
                            @if ($computeConsoleAvailable)
                                <a href="{{ route('admin.rdp-console.pveConsole', $hostingAccount) }}" class="btn btn-sm btn-secondary"><i class="bi bi-display me-1" aria-hidden="true"></i>VM Console</a>
                            @else
                                <button type="button" class="btn btn-sm btn-outline-secondary" disabled
                                        title="{{ $computeConsoleReason }}"
                                        @if($computeDescribedBy !== '') aria-describedby="{{ $computeDescribedBy }}" @endif><i class="bi bi-display me-1" aria-hidden="true"></i>VM Console</button>
                            @endif
                        @endcan
                    @endif
                </div>
                @if ($computeConsoleIsProxmox)
                    @can('hosting.manage')
                        @if (! $computeConsoleAvailable)
                            <div class="mh-module-group__note">{{ $computeConsoleReason }}</div>
                        @endif
                    @endcan
                @endif
            </div>
            <div class="mh-module-group mh-module-group--danger">
                <div class="mh-module-group__title">Danger</div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-sm {{ $computeEnabledDelete ? 'btn-danger' : 'btn-outline-danger' }}" data-compute-action="delete"
                            @if(! $computeCanDelete || $computeIsRunning) disabled @endif
                            @if($computeTitles['delete'] !== '') title="{{ $computeTitles['delete'] }}" @endif
                            @if($computeDescribedBy !== '') aria-describedby="{{ $computeDescribedBy }}" @endif><i class="bi bi-trash me-1" aria-hidden="true"></i>Delete</button>
                </div>
            </div>
        </div>
    @endcan
    @if ($computeProbeError !== '')
        <button type="button" class="btn btn-sm btn-outline-secondary" data-compute-retry>Retry</button>
    @endif

    @can('hosting.edit')
        @if ($computeCanCreate)
            <section class="mh-module-create my-2" data-compute-create-section aria-labelledby="compute-create-title-{{ $computeSlug }}">
                <h6 class="fw-semibold mb-1" id="compute-create-title-{{ $computeSlug }}"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Create the VM</h6>
                <p class="text-muted small mb-2">Select a template to build the VM on the host.</p>
                @if ($computeNoEffective)
                    <p class="text-danger small mb-2">No templates are available for this product on this server — check the product's template restriction.</p>
                @elseif ($computeOptions === [])
                    <p class="text-muted small mb-2">No templates are curated on this server; the module will build an empty VM.</p>
                @endif
                <form method="POST" action="{{ route('admin.hosting.module-action', $hostingAccount) }}" data-compute-form data-compute-form-action="create">
                    @csrf
                    <input type="hidden" name="module_slug" value="{{ $computeSlug }}">
                    <input type="hidden" name="action" value="create">
                    @if ($computeOptions !== [])
                        <div class="mb-2">
                            <label for="compute-template-{{ $computeSlug }}" class="form-label small mb-1">{{ $computeTemplateLabel }}</label>
                            <select id="compute-template-{{ $computeSlug }}" name="template" class="form-select form-select-sm" required>
                                @foreach ($computeOptions as $option)
                                    <option value="{{ $option['id'] }}" @selected((string) $option['id'] === $computeDefault)>{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="start_after_create" value="1"
                               id="compute-start-{{ $computeSlug }}" @checked($computeStartAfterCreate)>
                        <label class="form-check-label small" for="compute-start-{{ $computeSlug }}">Start the VM after creation</label>
                    </div>
                    <button type="submit" class="btn btn-sm btn-success" data-compute-submit><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Create VM</button>
                </form>
            </section>
        @endif
    @endcan

    <div class="compute-progress {{ $computeIsRunning ? 'is-open' : '' }}" data-compute-progress aria-hidden="{{ $computeIsRunning ? 'false' : 'true' }}">
        <div class="px-2 pb-2">
            <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-1">
                <span class="small fw-semibold" data-compute-progress-label aria-live="polite">{{ $computeStageLabel }}</span>
                <span class="text-muted small" data-compute-progress-elapsed>{{ $computeElapsedFmt }}</span>
            </div>
            <div class="progress" style="height: 8px;">
                <div class="progress-bar progress-bar-striped progress-bar-animated" data-compute-progress-bar
                     role="progressbar" aria-valuenow="{{ $computeProgress }}" aria-valuemin="0" aria-valuemax="100"
                     style="width: {{ $computeProgress }}%;"></div>
            </div>
            <div class="text-muted small mt-1" data-compute-progress-message></div>
        </div>
    </div>

    @can('hosting.edit')
        <div class="compute-disclosure border rounded-2 p-3 mb-2" data-compute-disclosure hidden aria-hidden="true">
            {{-- Restart (typed confirmation) --}}
            <div data-compute-view="restart" data-compute-confirm-expected="{{ $hostingAccount->host_name }}" hidden>
                <h6 class="fw-semibold mb-2">Restart VM</h6>
                <p class="small text-muted mb-2">Reboot this VM? Only a running VM is rebooted — a stopped VM is refused, never surprise-started.</p>
                <form method="POST" action="{{ route('admin.hosting.module-action', $hostingAccount) }}" data-compute-form data-compute-form-action="restart">
                    @csrf
                    <input type="hidden" name="module_slug" value="{{ $computeSlug }}">
                    <input type="hidden" name="action" value="restart">
                    <div class="mb-2">
                        <label class="form-label small mb-1" for="compute-restart-confirm-{{ $computeSlug }}">Type <code>{{ $hostingAccount->host_name }}</code> to confirm</label>
                        <input id="compute-restart-confirm-{{ $computeSlug }}" name="confirm" data-compute-confirm-input class="form-control form-control-sm" autocomplete="off" required>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-compute-cancel>Cancel</button>
                        <button type="submit" class="btn btn-sm btn-warning" data-compute-submit disabled>Confirm restart</button>
                    </div>
                </form>
            </div>

            {{-- Delete (typed confirmation) --}}
            <div data-compute-view="delete" data-compute-confirm-expected="{{ $hostingAccount->host_name }}" hidden>
                <h6 class="fw-semibold mb-2">Delete VM</h6>
                <p class="small text-muted mb-2">Permanently destroy the VM on the host and terminate this service. This cannot be undone.</p>
                <form method="POST" action="{{ route('admin.hosting.module-action', $hostingAccount) }}" data-compute-form data-compute-form-action="delete">
                    @csrf
                    <input type="hidden" name="module_slug" value="{{ $computeSlug }}">
                    <input type="hidden" name="action" value="delete">
                    <div class="mb-2">
                        <label class="form-label small mb-1" for="compute-delete-confirm-{{ $computeSlug }}">Type <code>{{ $hostingAccount->host_name }}</code> to confirm</label>
                        <input id="compute-delete-confirm-{{ $computeSlug }}" name="confirm" data-compute-confirm-input class="form-control form-control-sm" autocomplete="off" required>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-compute-cancel>Cancel</button>
                        <button type="submit" class="btn btn-sm btn-danger" data-compute-submit disabled>Confirm delete</button>
                    </div>
                </form>
            </div>

            {{-- Reset password. No current-password field: the frozen endpoint
                 takes {username, password, password_confirmation} only. --}}
            <div data-compute-view="reset_password" hidden>
                <h6 class="fw-semibold mb-2">Reset password</h6>
                <p class="small text-muted mb-2">Reset the password inside the running guest. The VM must be running.</p>
                <form method="POST" action="{{ route('admin.hosting.reset-vm-password', $hostingAccount) }}" data-compute-form data-compute-form-action="reset_password">
                    @csrf
                    <div class="mb-2">
                        <label for="compute-reset-username-{{ $computeSlug }}" class="form-label small mb-1">Username</label>
                        <input id="compute-reset-username-{{ $computeSlug }}" name="username" type="text" class="form-control form-control-sm"
                               value="{{ $computeCredUsername }}" maxlength="64" autocomplete="username" aria-label="Username">
                    </div>
                    <div class="mb-2">
                        {{-- WHY this field has its own id instead of reusing any
                             container id: the Generate button targets it by id
                             and must land on the INPUT. --}}
                        <label for="compute-reset-new-password-{{ $computeSlug }}" class="form-label small mb-1">New password</label>
                        <div class="input-group input-group-sm">
                            <input id="compute-reset-new-password-{{ $computeSlug }}" name="password" type="password" class="form-control"
                                   autocomplete="new-password" required minlength="8" aria-label="New password">
                            <button type="button" class="btn btn-outline-secondary" data-compute-generate-target="compute-reset-new-password-{{ $computeSlug }}">Generate</button>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label for="compute-reset-password-confirm-{{ $computeSlug }}" class="form-label small mb-1">Confirm new password</label>
                        <input id="compute-reset-password-confirm-{{ $computeSlug }}" name="password_confirmation" type="password" class="form-control form-control-sm"
                               autocomplete="new-password" required minlength="8" aria-label="Confirm new password">
                    </div>
                    <div id="compute-reset-result-{{ $computeSlug }}" class="border rounded-2 p-2 mt-2 d-none" role="status" aria-live="polite">
                        <div class="d-flex align-items-center justify-content-between gap-2">
                            <code id="compute-reset-result-password-{{ $computeSlug }}"></code>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="compute-reset-copy-{{ $computeSlug }}">Copy</button>
                        </div>
                        <div class="text-muted small mt-1">Copy this password now — it will not be shown again.</div>
                    </div>
                    <div class="d-flex gap-2 mt-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-compute-cancel>Cancel</button>
                        <button type="submit" class="btn btn-sm btn-primary" data-compute-submit>Reset password</button>
                    </div>
                </form>
            </div>

            {{-- Credentials reveal: a plain view (nothing posts). The password
                 is fetched on demand via the vm-credentials endpoint and is
                 never server-rendered into this HTML. --}}
            <div data-compute-view="credentials" hidden>
                <h6 class="fw-semibold mb-2">Credentials</h6>
                <p class="small text-muted mb-2">The password is fetched only when you click Show or Copy, and is never written into the page.</p>
                @if ($computeCredsStored)
                    <p class="small text-muted mb-2">Stored credentials are available for this VM.</p>
                @endif
                <div class="mb-2">
                    <span class="form-label small mb-1 d-block">Username</span>
                    <code id="compute-credentials-username-{{ $computeSlug }}">{{ $computeCredUsername }}</code>
                </div>
                <div class="mb-2">
                    <span class="form-label small mb-1 d-block">Password</span>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <code id="compute-credentials-password-{{ $computeSlug }}" style="letter-spacing: 0.15em;">••••••••</code>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="compute-credentials-show-{{ $computeSlug }}" title="Show password" aria-label="Show password">Show</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="compute-credentials-copy-{{ $computeSlug }}" title="Copy password" aria-label="Copy password">Copy</button>
                        <span id="compute-credentials-feedback-{{ $computeSlug }}" class="text-success small d-none" role="status">Copied!</span>
                    </div>
                </div>
                <div id="compute-credentials-error-{{ $computeSlug }}" class="alert alert-danger py-2 px-3 mt-2 mb-0 d-none" role="alert"></div>
                <div class="d-flex gap-2 mt-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-compute-cancel>Close</button>
                </div>
            </div>
        </div>
    @endcan
</div>

@once
    <style>
        .compute-progress { display: none; }
        .compute-progress.is-open { display: block; }
        .compute-disclosure { background: var(--bs-tertiary-bg); }
    </style>
@endonce

@can('hosting.edit')
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var slug = @json($computeSlug);
        var panel = document.getElementById('compute-panel-' + slug);
        if (!panel) return;

        var moduleActionUrl = @json(route('admin.hosting.module-action', $hostingAccount));
        var statusUrl = @json(route('admin.hosting.vm-status', $hostingAccount));
        var credentialsUrl = @json(route('admin.hosting.vm-credentials', $hostingAccount));
        var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || @json(csrf_token());

        var POLL_MS = 3000;
        var FIRST_POLL_MS = 800;
        var RELOAD_MS = 1200;
        var RUNNING_REASON = 'An action is already running — please wait.';
        var NO_CREDENTIALS_REASON = 'No credentials are stored for this VM.';

        var statePill = document.getElementById('compute-state-' + slug);
        var progress = panel.querySelector('[data-compute-progress]');
        var progressBar = panel.querySelector('[data-compute-progress-bar]');
        var progressLabel = panel.querySelector('[data-compute-progress-label]');
        var progressElapsed = panel.querySelector('[data-compute-progress-elapsed]');
        var progressMessage = panel.querySelector('[data-compute-progress-message]');
        var feedback = panel.querySelector('[data-compute-feedback]');
        var disclosure = panel.querySelector('[data-compute-disclosure]');
        var buttons = Array.prototype.slice.call(panel.querySelectorAll('[data-compute-action]'));

        var state = { last: null, busy: false, open: null, trigger: null, poll: null, alert: null, credsStored: @json($computeCredsStored) };

        // Replaced by the credentials module below; declared here so the
        // disclosure code can call them without ordering games.
        var resetCredentialsView = function () {};
        var invalidateCredentialsView = function () {};

        function fmtElapsed(sec) {
            sec = Math.max(0, parseInt(sec, 10) || 0);
            var m = Math.floor(sec / 60), s = sec % 60;
            return (m < 10 ? '0' + m : m) + ':' + (s < 10 ? '0' + s : s);
        }

        function stateMeta(vm) {
            if (!vm) return { label: 'Unknown', theme: 'secondary' };
            if (vm.exists === false) return { label: 'Not created', theme: 'secondary' };
            var raw = (vm.state || '').toString();
            var low = raw.toLowerCase().trim();
            if (low === 'running') return { label: 'Running', theme: 'success' };
            if (low === 'off' || low === 'stopped') return { label: 'Off', theme: 'secondary' };
            if (low === 'saved') return { label: 'Saved', theme: 'warning' };
            if (raw) return { label: raw, theme: 'secondary' };
            return { label: 'Unknown', theme: 'secondary' };
        }

        function showFeedback(kind, message) {
            if (!feedback) return;
            if (state.alert) { clearTimeout(state.alert); state.alert = null; }
            feedback.className = 'alert py-2 px-3 my-2 alert-' + (kind === 'success' ? 'success' : (kind === 'warning' ? 'warning' : 'danger'));
            feedback.textContent = String(message || '');
            feedback.classList.remove('d-none');
            if (kind === 'success') state.alert = setTimeout(function () { feedback.classList.add('d-none'); }, 4000);
        }

        function hideFeedback() {
            if (feedback) feedback.classList.add('d-none');
        }

        var NOTICE_SEVERITIES = { danger: 'danger', warning: 'warning', info: 'info' };
        var NOTICE_ICONS = { danger: 'bi-exclamation-triangle-fill', warning: 'bi-exclamation-triangle-fill', info: 'bi-info-circle' };

        // The strip's live text. Mirrors the server strip in this file:
        // presenter notice first, then the freshest failure verdict (only
        // while no VM is observed — live state supersedes it), then the
        // state-derived fallback. A running action and steady states return
        // null — the open progress panel and the state chip own those.
        function noticeFromStatus(status) {
            var n = status && status.notice && typeof status.notice === 'object' ? status.notice : null;
            var text = (n && n.text) ? String(n.text) : '';
            var sev = (n && n.severity) ? (NOTICE_SEVERITIES[String(n.severity).toLowerCase()] || 'info') : 'info';
            var action = (status && status.action) || null;
            var vm = (status && status.vm) || null;
            var running = !!(action && action.running);
            var verdict = (!running && action && action.status === 'failed') ? (action.error || action.message || '') : '';
            var relevant = !!verdict && (!vm || vm.exists !== true);
            if (relevant && text && String(verdict) !== text) {
                return { text: text + ' Last action failed: ' + verdict, severity: 'danger' };
            }
            if (relevant && !text) return { text: String(verdict), severity: 'danger' };
            if (text) return { text: text, severity: sev };
            if (running) return null;
            if (vm && vm.probe_error) return { text: 'Host unreachable — actions may fail.', severity: 'warning' };
            if (vm && vm.exists === false) return { text: 'No VM exists on the host yet — create one first.', severity: 'info' };
            if (vm && (vm.exists === null || vm.exists === undefined)) return { text: 'VM state is unknown — refresh the page or re-test the connection.', severity: 'warning' };
            return null;
        }

        function setNotice(found) {
            var box = panel.querySelector('[data-compute-notice]');
            if (!found) {
                if (box) box.hidden = true;
                return;
            }
            if (!box) {
                box = document.createElement('div');
                box.id = 'compute-notice-' + slug;
                box.setAttribute('data-compute-notice', '');
                box.setAttribute('role', 'status');
                box.setAttribute('aria-live', 'polite');
                var icon = document.createElement('i');
                icon.setAttribute('aria-hidden', 'true');
                icon.setAttribute('data-compute-notice-icon', '');
                var text = document.createElement('span');
                text.setAttribute('data-compute-hint', '');
                box.appendChild(icon);
                box.appendChild(text);
                var anchor = panel.querySelector('[data-compute-identity]');
                if (anchor && anchor.parentNode) anchor.parentNode.insertBefore(box, anchor.nextSibling);
                else if (panel.firstChild) panel.insertBefore(box, panel.firstChild);
                else panel.appendChild(box);
                buttons.forEach(function (btn) {
                    if (btn.disabled && !btn.getAttribute('aria-describedby')) btn.setAttribute('aria-describedby', box.id);
                });
            }
            box.hidden = false;
            box.className = 'alert py-2 px-3 my-2 alert-' + found.severity + ' mh-module-notice';
            var iconEl = box.querySelector('[data-compute-notice-icon]');
            if (iconEl) iconEl.className = 'bi flex-shrink-0 ' + (NOTICE_ICONS[found.severity] || NOTICE_ICONS.info);
            var textEl = box.querySelector('[data-compute-hint]');
            if (textEl) textEl.textContent = found.text;
        }

        function setProgress(open, pct, label, elapsed, message) {
            if (!progress) return;
            progress.classList.toggle('is-open', !!open);
            progress.setAttribute('aria-hidden', open ? 'false' : 'true');
            if (progressBar && pct !== undefined) {
                var value = Math.max(0, Math.min(100, parseInt(pct, 10) || 0));
                progressBar.style.width = value + '%';
                progressBar.setAttribute('aria-valuenow', String(value));
            }
            if (progressLabel && label) progressLabel.textContent = label;
            if (progressElapsed && elapsed !== undefined) progressElapsed.textContent = fmtElapsed(elapsed);
            if (progressMessage) progressMessage.textContent = message || '';
        }

        function applyStatus(status) {
            if (!status || typeof status !== 'object') return;
            state.last = status;
            // The presenter always sends credentials; only trust it when present.
            if (status.credentials && typeof status.credentials === 'object') {
                state.credsStored = !!status.credentials.stored;
            }
            var action = status.action || null;
            var vm = status.vm || null;
            var can = status.can || {};
            var reasons = status.reasons || {};
            var running = !!(action && action.running);

            if (statePill) {
                var meta = stateMeta(vm);
                statePill.textContent = meta.label;
                // Keep the status-badge component classes the server render
                // uses; only the theme suffix changes with live state.
                statePill.className = 'badge rounded-pill text-bg-' + meta.theme + ' mh-badge mh-badge--solid';
            }

            setNotice(noticeFromStatus(status));

            buttons.forEach(function (btn) {
                var act = btn.getAttribute('data-compute-action');
                if (act === 'restart') { btn.disabled = running || !(vm && vm.exists === true); return; }
                if (act === 'credentials') {
                    // No can key exists for credentials — its gate is credentials.stored.
                    btn.disabled = running || !state.credsStored;
                    if (running) btn.setAttribute('title', RUNNING_REASON);
                    else if (!state.credsStored) btn.setAttribute('title', NO_CREDENTIALS_REASON);
                    else btn.removeAttribute('title');
                    return;
                }
                btn.disabled = running || !can[act];
                if (running) btn.setAttribute('title', RUNNING_REASON);
                else if (reasons[act]) btn.setAttribute('title', reasons[act]);
                else btn.removeAttribute('title');
            });

            if (running && action) {
                setProgress(true, action.progress || 0, action.stage_label || action.stage || 'Working…', action.elapsed || 0, action.message || '');
            } else if (!state.busy) {
                setProgress(false);
            }
        }

        function parseJson(res) {
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
        }

        function fetchStatus(refresh) {
            return fetch(statusUrl + (refresh ? '?refresh=1' : ''), {
                method: 'GET',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            }).then(function (res) { return res.json().catch(function () { return null; }); })
              .then(function (payload) {
                  if (payload && payload.data && (payload.data.vm || payload.data.can)) payload = payload.data;
                  if (payload && (payload.vm || payload.can)) applyStatus(payload);
                  return payload;
              });
        }

        function startPolling() {
            stopPolling();
            state.poll = setInterval(pollOnce, POLL_MS);
            setTimeout(pollOnce, FIRST_POLL_MS);
        }

        function stopPolling() {
            if (state.poll) { clearInterval(state.poll); state.poll = null; }
        }

        function pollOnce() {
            return fetchStatus(false).then(function (payload) {
                var action = payload && payload.action ? payload.action : null;
                if (!action || action.running) return;
                stopPolling();
                if (action.status === 'failed') {
                    state.busy = false;
                    setProgress(false);
                    showFeedback('error', action.error || action.message || 'Action failed.');
                    return;
                }
                // Still `running` on the row but the presenter no longer calls
                // it running (stale: the worker died before it closed the
                // event). Surface the verdict instead of swallowing it.
                if (action.status === 'running') {
                    state.busy = false;
                    setProgress(false);
                    showFeedback('error', action.message || action.error || 'The action was interrupted — retry it.');
                    return;
                }
                if (action.status === 'completed') {
                    showFeedback('success', action.message || 'Done.');
                    setTimeout(function () { window.location.reload(); }, 1500);
                }
            }).catch(function () { /* transient poll errors keep the loop alive */ });
        }

        // The create form is an always-visible section (the empty state), so
        // its button scrolls to it instead of opening the disclosure.
        function scrollToCreate() {
            var section = panel.querySelector('[data-compute-create-section]');
            if (!section) return;
            try { section.scrollIntoView({ block: 'nearest' }); } catch (e) {}
            var first = section.querySelector('select, input:not([type="hidden"])');
            if (first) { try { first.focus({ preventScroll: true }); } catch (e) { try { first.focus(); } catch (ignored) {} } }
        }

        function openView(action, trigger) {
            if (!disclosure) return;
            if (state.open === action) { closeView(true); return; }
            disclosure.querySelectorAll('[data-compute-view]').forEach(function (view) {
                var isTarget = view.getAttribute('data-compute-view') === action;
                view.hidden = !isTarget;
                if (isTarget && (action === 'restart' || action === 'delete')) {
                    var input = view.querySelector('[data-compute-confirm-input]');
                    var submit = view.querySelector('[data-compute-submit]');
                    if (input) input.value = '';
                    if (submit) submit.disabled = true;
                }
            });
            state.open = action;
            state.trigger = trigger || null;
            disclosure.hidden = false;
            disclosure.setAttribute('aria-hidden', 'false');
            if (action === 'credentials') resetCredentialsView();
            var first = disclosure.querySelector('[data-compute-view]:not([hidden]) input:not([type=hidden]), [data-compute-view]:not([hidden]) select');
            if (first) { try { first.focus(); } catch (e) {} }
        }

        function closeView(focusTrigger) {
            if (!disclosure || !state.open) return;
            var trigger = state.trigger;
            state.open = null;
            disclosure.hidden = true;
            disclosure.setAttribute('aria-hidden', 'true');
            if (focusTrigger && trigger && document.contains(trigger) && !trigger.disabled) {
                try { trigger.focus(); } catch (e) {}
            }
        }

        function submitForm(form, action) {
            if (state.busy) return;
            state.busy = true;
            buttons.forEach(function (b) { b.disabled = true; });
            setProgress(true, 4, 'Working…', 0, '');

            fetch(form.getAttribute('action') || moduleActionUrl, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: new FormData(form)
            }).then(parseJson).then(function (data) {
                state.busy = false;
                var ok = data.ok !== undefined ? !!data.ok : true;
                if (!ok) {
                    showFeedback('error', data.message || data.error || 'Action failed.');
                    setProgress(false);
                    fetchStatus(false);
                    return;
                }
                // Reset shows the new password once, in place, and never reloads.
                if (action === 'reset_password') {
                    // Queued reset (202): no password exists yet — poll to completion.
                    if (data.started) {
                        closeView(false);
                        setProgress(true, 4, 'Working…', 0, '');
                        showFeedback('success', data.message || 'Password reset started.');
                        startPolling();
                        return;
                    }
                    var newPw = data.password || data.new_password || (data.data && data.data.password) || '';
                    var resultBlock = panel.querySelector('#compute-reset-result-' + slug);
                    var resultPw = panel.querySelector('#compute-reset-result-password-' + slug);
                    if (resultBlock && resultPw) {
                        resultPw.textContent = newPw || '(no password returned)';
                        resultBlock.classList.remove('d-none');
                    }
                    invalidateCredentialsView();
                    state.busy = false;
                    setProgress(false);
                    showFeedback('success', data.message || 'Password reset.');
                    fetchStatus(false);
                    return;
                }
                if (data.started) {
                    closeView(false);
                    setProgress(true, 4, 'Creating the VM on the host', 0, '');
                    showFeedback('success', data.message || 'VM creation started.');
                    startPolling();
                    return;
                }
                closeView(false);
                showFeedback('success', data.message || 'Done.');
                setTimeout(function () { window.location.reload(); }, RELOAD_MS);
            }).catch(function (err) {
                state.busy = false;
                showFeedback('error', (err && err.message) ? err.message : 'Network error.');
                setProgress(false);
                fetchStatus(false);
            });
        }

        function runDirect(action) {
            if (state.busy) return;
            state.busy = true;
            buttons.forEach(function (b) { b.disabled = true; });
            setProgress(true, 4, action === 'start' ? 'Starting the VM…' : 'Stopping the VM…', 0, '');

            var body = new FormData();
            body.append('module_slug', slug);
            body.append('action', action);

            fetch(moduleActionUrl, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: body
            }).then(parseJson).then(function (data) {
                state.busy = false;
                var ok = data.ok !== undefined ? !!data.ok : true;
                if (!ok) {
                    showFeedback('error', data.message || data.error || 'Action failed.');
                    setProgress(false);
                    fetchStatus(false);
                    return;
                }
                if (data.started) {
                    closeView(false);
                    setProgress(true, 4, 'Working…', 0, '');
                    showFeedback('success', data.message || 'Action started.');
                    startPolling();
                    return;
                }
                showFeedback('success', data.message || 'Done.');
                setTimeout(function () { window.location.reload(); }, RELOAD_MS);
            }).catch(function (err) {
                state.busy = false;
                showFeedback('error', (err && err.message) ? err.message : 'Network error.');
                setProgress(false);
                fetchStatus(false);
            });
        }

        // ── Password generator (no deps; also mirrors the confirmation field) ─
        function generatePassword(len) {
            len = len || 16;
            var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%*?';
            var out = '';
            try {
                var arr = new Uint32Array(len);
                window.crypto.getRandomValues(arr);
                for (var i = 0; i < len; i++) out += chars.charAt(arr[i] % chars.length);
            } catch (ignored) {
                // No WebCrypto: Math.random is weaker but still a fresh password.
                for (var j = 0; j < len; j++) out += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            return out;
        }

        // Any button carrying data-compute-generate-target fills that input
        // with a strong random password.
        function fillGeneratedPassword(gen) {
            var input = document.getElementById(gen.getAttribute('data-compute-generate-target') || '');
            // Guard against id collisions: a non-input with the same id would
            // swallow the value silently.
            if (!input || (input.tagName !== 'INPUT' && input.tagName !== 'TEXTAREA')) return;
            var generated = generatePassword(16);
            input.value = generated;
            var form = input.form || (input.closest ? input.closest('form') : null);
            var confirmation = form ? form.querySelector('[name="password_confirmation"]') : null;
            if (confirmation) confirmation.value = generated;
            input.setAttribute('type', 'text');
            try { input.focus(); input.select(); } catch (e) {}
            setTimeout(function () { input.setAttribute('type', 'password'); }, 5000);
        }

        function copyText(text, done) {
            var finish = done || function () {};
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(finish, function () {
                    legacyCopy(text);
                    finish();
                });
                return;
            }
            legacyCopy(text);
            finish();
        }

        function legacyCopy(text) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.cssText = 'position:fixed;opacity:0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); } catch (ignored) { /* no clipboard API — nothing else to try */ }
            document.body.removeChild(ta);
        }

        // ── Reset result copy (panel-scoped through the slug-suffixed ids,
        //    so co-rendered compute panels never share them) ────────────────
        function copyResetPassword(btn) {
            var pwEl = panel.querySelector('#compute-reset-result-password-' + slug);
            var text = pwEl ? (pwEl.textContent || '') : '';
            if (!text) return;
            var original = btn.textContent;
            copyText(text, function () {
                btn.textContent = 'Copied';
                setTimeout(function () { btn.textContent = original; }, 1200);
            });
        }

        // ── Credentials reveal (fetched on demand, cached page-lifetime) ─────
        (function initCredentials() {
            var view = disclosure ? disclosure.querySelector('[data-compute-view="credentials"]') : null;
            if (!view) return;
            var userEl = panel.querySelector('#compute-credentials-username-' + slug);
            var passEl = panel.querySelector('#compute-credentials-password-' + slug);
            var showBtn = panel.querySelector('#compute-credentials-show-' + slug);
            var copyBtn = panel.querySelector('#compute-credentials-copy-' + slug);
            var copiedEl = panel.querySelector('#compute-credentials-feedback-' + slug);
            var errorBox = panel.querySelector('#compute-credentials-error-' + slug);
            if (!passEl || (!showBtn && !copyBtn)) return;

            var masked = '••••••••';
            var cached = null;
            var visible = false;
            var copiedTimer = null;

            function showCopied() {
                if (!copiedEl) return;
                copiedEl.classList.remove('d-none');
                if (copiedTimer) clearTimeout(copiedTimer);
                copiedTimer = setTimeout(function () { copiedEl.classList.add('d-none'); }, 1800);
            }

            function showError(msg) {
                if (!errorBox) return;
                errorBox.textContent = msg;
                errorBox.classList.remove('d-none');
            }

            function clearError() {
                if (!errorBox) return;
                errorBox.textContent = '';
                errorBox.classList.add('d-none');
            }

            function fetchCredentials() {
                if (cached !== null) return Promise.resolve(cached);
                return fetch(credentialsUrl, {
                    method: 'GET',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                }).then(function (res) {
                    return res.json().catch(function () { return null; }).then(function (data) {
                        // A reveal is audited server-side; unknown states answer 422.
                        if (!res.ok || !data || data.ok !== true) {
                            throw new Error((data && data.message) ? data.message : NO_CREDENTIALS_REASON);
                        }
                        cached = { username: data.username || 'root', password: data.password || '' };
                        return cached;
                    });
                });
            }

            function setVisible(on, password) {
                visible = !!on;
                passEl.textContent = on ? (password || '') : masked;
                passEl.style.letterSpacing = on ? 'normal' : '0.15em';
                if (showBtn) {
                    showBtn.setAttribute('title', on ? 'Hide password' : 'Show password');
                    showBtn.setAttribute('aria-label', on ? 'Hide password' : 'Show password');
                    var label = showBtn.querySelector('span');
                    if (label) label.textContent = on ? 'Hide' : 'Show';
                    else showBtn.textContent = on ? 'Hide' : 'Show';
                }
            }

            resetCredentialsView = function () {
                setVisible(false);
                clearError();
            };
            // Called after a successful reset: the cached reveal is now stale.
            invalidateCredentialsView = function () {
                cached = null;
                setVisible(false);
                clearError();
            };

            if (showBtn) {
                showBtn.addEventListener('click', function () {
                    if (visible) { setVisible(false); return; }
                    clearError();
                    fetchCredentials().then(function (cred) {
                        if (!cred.password) { showError(NO_CREDENTIALS_REASON); return; }
                        if (userEl) userEl.textContent = cred.username;
                        setVisible(true, cred.password);
                    }).catch(function (err) {
                        showError((err && err.message) ? err.message : 'Failed to load credentials.');
                    });
                });
            }

            if (copyBtn) {
                copyBtn.addEventListener('click', function () {
                    clearError();
                    fetchCredentials().then(function (cred) {
                        if (!cred.password) { showError(NO_CREDENTIALS_REASON); return; }
                        copyText(cred.password, showCopied);
                    }).catch(function (err) {
                        showError((err && err.message) ? err.message : 'Failed to load credentials.');
                    });
                });
            }
        })();

        panel.addEventListener('click', function (ev) {
            var target = ev.target;
            if (!target || !target.closest) return;

            if (target.closest('[data-compute-cancel]')) { closeView(true); return; }

            var generate = target.closest('[data-compute-generate-target]');
            if (generate) { fillGeneratedPassword(generate); return; }

            var copyReset = target.closest('#compute-reset-copy-' + slug);
            if (copyReset) { copyResetPassword(copyReset); return; }

            var retry = target.closest('[data-compute-retry]');
            if (retry) {
                retry.disabled = true;
                fetchStatus(true).then(function () { retry.disabled = false; hideFeedback(); });
                return;
            }

            var btn = target.closest('[data-compute-action]');
            if (!btn || btn.disabled) return;
            var action = btn.getAttribute('data-compute-action');

            if (action === 'start' || action === 'stop') { runDirect(action); return; }
            if (action === 'create') { scrollToCreate(); return; }
            if (action === 'restart' || action === 'delete' || action === 'reset_password' || action === 'credentials') { openView(action, btn); return; }
        });

        panel.addEventListener('input', function (ev) {
            var input = ev.target;
            if (!input || !input.hasAttribute || !input.hasAttribute('data-compute-confirm-input')) return;
            var view = input.closest ? input.closest('[data-compute-view]') : null;
            if (!view) return;
            var expected = view.getAttribute('data-compute-confirm-expected') || '';
            var submit = view.querySelector('[data-compute-submit]');
            if (submit) submit.disabled = input.value.trim() !== expected;
        });

        panel.addEventListener('submit', function (ev) {
            var form = ev.target;
            if (!form || !form.hasAttribute || !form.hasAttribute('data-compute-form')) return;
            if (!form.checkValidity()) return;
            var formAction = form.getAttribute('data-compute-form-action') || '';
            if (formAction === 'reset_password') {
                var pw = form.querySelector('[name="password"]');
                var pw2 = form.querySelector('[name="password_confirmation"]');
                if (pw && pw2 && pw.value !== pw2.value) {
                    ev.preventDefault();
                    showFeedback('error', 'Passwords do not match.');
                    try { pw2.focus(); } catch (e) {}
                    return;
                }
            }
            ev.preventDefault();
            submitForm(form, formAction);
        });

        document.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Escape' && ev.key !== 'Esc') return;
            if (!state.open) return;
            ev.preventDefault();
            closeView(true);
        });

        if (state.last === null && @json($computeIsRunning)) startPolling();
        window.addEventListener('beforeunload', stopPolling);
    });
    </script>
@endcan
