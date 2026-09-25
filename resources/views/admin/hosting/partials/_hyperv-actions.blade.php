{{-- Hyper-V module actions — one inline VM control panel.

     Every action happens INSIDE this panel: the action bar swaps one form or
     reveal into the disclosure region below it, progress and feedback render
     inline, and nothing ever covers the page. The old Bootstrap modals are
     deliberately gone — do not bring them back. --}}
@php
    // Defensive defaults — backend may not yet provide the vmStatus bundle.
    $vmStatus = $vmStatus ?? null;
    $vmGuestUsername = $vmGuestUsername ?? 'Administrator';
    $vmHasStoredPassword = $vmHasStoredPassword ?? false;

    $hvAct = is_array($vmStatus['action'] ?? null) ? $vmStatus['action'] : null;
    $hvVm = is_array($vmStatus['vm'] ?? null) ? $vmStatus['vm'] : null;
    $hvCan = is_array($vmStatus['can'] ?? null) ? $vmStatus['can'] : [];
    $hvReasons = is_array($vmStatus['reasons'] ?? null) ? $vmStatus['reasons'] : [];
    $hvCredentials = is_array($vmStatus['credentials'] ?? null) ? $vmStatus['credentials'] : [];
    $hvIsRunning = (bool) ($hvAct['running'] ?? false);
    // Fallback: when the backend vmStatus is not wired yet, a running
    // provisioning event also counts as busy.
    $latestEvt = $latestProvisioningEvent ?? null;
    $fallbackRunning = $latestEvt && (($latestEvt->status ?? $latestEvt->event_status) === 'running');
    $hvIsRunningEffective = $hvIsRunning || (bool) $fallbackRunning;
    if ($fallbackRunning && ! $hvIsRunning) {
        // surface fallback stage so panel isn't empty
        $hvAct = $hvAct ?? ['stage_label' => ucfirst($latestEvt->event_type ?? 'provision') . ' in progress…'];
    }
    $hvProgress = max(0, min(100, (int) ($hvAct['progress'] ?? 0)));
    $hvStageLabel = trim((string) ($hvAct['stage_label'] ?? ($hvAct['stage'] ?? 'Working…')));
    if ($hvStageLabel === '') { $hvStageLabel = 'Working…'; }
    $hvElapsed = (int) ($hvAct['elapsed'] ?? 0);
    $hvElapsedFmt = sprintf('%02d:%02d', intdiv($hvElapsed, 60), $hvElapsed % 60);
    $hvProgressMessage = trim((string) ($hvAct['message'] ?? ''));

    // Terminal failures from earlier runs, rendered server-side so they
    // survive a reload. The JS feedback region only covers new failures.
    $hvFailedActId = ($hvAct['status'] ?? null) === 'failed' ? (int) ($hvAct['event_id'] ?? 0) : 0;
    $hvLatestFailed = ! empty($latestEvt) && ($latestEvt->status ?? $latestEvt->event_status) === 'failed';
    $hvShowLatestFailed = $hvLatestFailed && (int) ($latestEvt->id ?? 0) !== $hvFailedActId;

    // Live VM state pill.
    $hvVmStateRaw = $hvVm['state'] ?? null;
    $hvVmExists = $hvVm['exists'] ?? null; // null = unknown (probe failed / backend absent)
    if ($hvVmExists === false) {
        $hvVmStateLabel = 'Not created';
        $hvVmStateTheme = 'secondary';
    } elseif (is_string($hvVmStateRaw) && $hvVmStateRaw !== '') {
        $low = strtolower(trim($hvVmStateRaw));
        if ($low === 'running') { $hvVmStateLabel = 'Running'; $hvVmStateTheme = 'success'; }
        elseif ($low === 'off') { $hvVmStateLabel = 'Off'; $hvVmStateTheme = 'secondary'; }
        elseif ($low === 'saved') { $hvVmStateLabel = 'Saved'; $hvVmStateTheme = 'warning'; }
        else { $hvVmStateLabel = $hvVmStateRaw; $hvVmStateTheme = 'secondary'; }
    } else {
        // fallback when backend absent: use account status hint
        $hvVmStateLabel = 'Unknown';
        $hvVmStateTheme = 'secondary';
    }
    $hvStateLow = is_string($hvVmStateRaw) ? strtolower(trim($hvVmStateRaw)) : null;
    $hvProbeError = trim((string) ($hvVm['probe_error'] ?? ''));

    $hvSlug = $mod->slug ?? 'hyperv';
    $hvHostName = (string) $hostingAccount->host_name;

    // hvOptions fallback compute (moved from show.blade.php)
    $hvOptions = $hvOptions ?? ($hypervEffectiveOptions ?? null);
    $hvDefault = $hvDefault ?? ($hypervEffectiveDefault ?? null);
    $hvCuratedCount = $hvCuratedCount ?? ($hypervCuratedCount ?? 0);
    if ($hvOptions === null) {
        if (isset($hostingAccount->server) && $hostingAccount->server && method_exists($hostingAccount->server, 'hypervTemplateOptions')) {
            $hvOptions = $hostingAccount->server->hypervTemplateOptions();
            $hvDefault = $hostingAccount->server->hypervDefaultTemplate();
            $hvCuratedCount = count($hostingAccount->server->hypervTemplateVMs());
        } else {
            $tmpTemplates = $hostingAccount->server?->hypervTemplateVms() ?? [];
            $hvDefault = $hostingAccount->server?->hypervDefaultTemplate();
            $hvCuratedCount = count($tmpTemplates);
            $hvOptions = [];
            foreach ($tmpTemplates as $n) { $hvOptions[] = ['name' => $n, 'label' => $n]; }
        }
    }

    // ── View decisions (presentation only — the can-matrix stays server-side) ──
    // when we have no vmStatus/can, fallback to permissive (JS will refine); when we do, respect it
    $hvHasCan = ! empty($hvCan);

    // Per-action disabled state + the user-facing reason. The reason is also
    // mirrored by the always-visible hint below, because a disabled button is
    // not focusable and a title alone is unreachable by keyboard users.
    $hvDisabled = [];
    $hvTitle = [];
    foreach (['create', 'start', 'stop', 'restart', 'delete', 'reset_password'] as $act) {
        $canThis = $hvHasCan ? (bool) ($hvCan[$act] ?? false) : true;
        $reason = trim((string) ($hvReasons[$act] ?? ''));
        $hvDisabled[$act] = $hvIsRunningEffective || ! $canThis;
        $hvTitle[$act] = $hvIsRunningEffective ? 'An action is already running — please wait.' : $reason;
    }

    // Credentials reveal: the presenter carries no can flag for it — its state
    // comes from credentials.stored instead.
    $hvCredsStored = (bool) ($hvCredentials['stored'] ?? $vmHasStoredPassword);
    $hvCredsDisabled = $hvIsRunningEffective || ! $hvCredsStored;
    $hvCredsTitle = $hvIsRunningEffective
        ? 'An action is already running — please wait.'
        : (! $hvCredsStored ? 'No Administrator credentials are stored for this VM.' : '');

    // ── VM Console gate (presentation only) ─────────────────────────────
    // The Hyper-V VMConnect console needs two server-side facts this panel can
    // see: a VM GUID (the presenter's live-probe vmId) and the Hyper-V host's
    // API credentials on the Server row. The token endpoint re-resolves both
    // server-side and fails closed regardless — this only decides whether the
    // button is offered, and the visible reason when it is not.
    $hvConsoleRouteExists = \Illuminate\Support\Facades\Route::has('admin.rdp-console.vmConsole');
    $hvConsoleServer = $hostingAccount->server ?? null;
    $hvConsoleHasHostCreds = $hvConsoleServer !== null
        && trim((string) $hvConsoleServer->api_url) !== ''
        && trim((string) $hvConsoleServer->api_username) !== ''
        // Raw attribute check only — never decrypt credentials in a view.
        && trim((string) $hvConsoleServer->getRawOriginal('api_password_encrypted')) !== '';
    $hvConsoleVmGuid = trim((string) ($hvVm['vmId'] ?? ''));
    $hvConsoleReason = null;
    if (! $hvConsoleHasHostCreds) {
        $hvConsoleReason = 'No Hyper-V host administrator credentials are configured for this service.';
    } elseif ($hvConsoleVmGuid === '') {
        if ($hvVmExists === false) {
            $hvConsoleReason = 'No VM exists on the host yet — create one first.';
        } elseif ($hvProbeError !== '') {
            $hvConsoleReason = 'Live VM state is unavailable — re-check the host with Retry.';
        } else {
            $hvConsoleReason = 'No VM GUID is recorded for this VM yet.';
        }
    } elseif ($hvIsRunningEffective) {
        $hvConsoleReason = 'An action is already running — please wait.';
    }
    $hvConsoleAvailable = $hvConsoleReason === null;

    // The one correct next step for the live state. Null when the state is
    // unknown — recommending an action then would be a guess.
    if ($hvVmExists === false) { $hvPrimary = 'create'; }
    elseif ($hvStateLow === 'running') { $hvPrimary = 'stop'; }
    elseif (in_array($hvStateLow, ['off', 'saved'], true)) { $hvPrimary = 'start'; }
    else { $hvPrimary = null; }
    // Only render the primary when the can-matrix (or its absence) allows it.
    if ($hvPrimary !== null && $hvHasCan && ! ($hvCan[$hvPrimary] ?? false)) { $hvPrimary = null; }

    $hvPrimaryMeta = [
        'create' => ['label' => 'Create VM', 'class' => 'btn-success'],
        'start' => ['label' => 'Start VM', 'class' => 'btn-primary'],
        'stop' => ['label' => 'Stop VM', 'class' => 'btn-warning'],
    ][$hvPrimary] ?? null;

    // `create` is a provisioning verb, not a power verb, and the primary slot
    // only recommends it for a VM that is observed absent. It must stay
    // reachable whenever it is not semantically refused — including while
    // another action runs and while the host probe is inconclusive, where the
    // can-matrix zeroes everything transiently (and the running branch sets
    // only reasons, never can). It is refused only when a VM was actually
    // observed on the host (exists === true), which is also when the
    // presenter refuses it. It is rendered disabled in those transient states,
    // never as a guessed primary.
    $hvCreateRefused = $hvVmExists === true;
    $hvShowSecondaryCreate = $hvPrimary !== 'create' && ! $hvCreateRefused;

    // Same phrasing as computeHint() in the JS — keep the two in sync.
    if ($hvIsRunningEffective) {
        $hvStateHint = 'An action is already running — the controls unlock when it finishes.';
    } elseif ($hvProbeError !== '') {
        $hvStateHint = 'Host unreachable — actions may fail. Fix connectivity, then Retry.';
    } elseif ($hvVmExists === false) {
        $hvStateHint = 'No VM exists on the host yet — create one first.';
    } elseif ($hvStateLow === 'running') {
        $hvStateHint = 'VM is running — Stop is graceful, Restart needs the account name typed.';
    } elseif (in_array($hvStateLow, ['off', 'saved'], true)) {
        $hvStateHint = 'VM is stopped — Start is safe; Delete needs the account name typed.';
    } elseif ($hvVmExists === true) {
        $hvStateHint = 'VM state is unknown — re-check the host with Retry.';
    } else {
        $hvStateHint = 'Live VM state is unavailable right now — the controls follow the last known state.';
    }

    // Routine verbs (outline) vs destructive verbs (boxed danger zone) vs
    // utilities. Create/Start/Stop can all be the primary, so the primary is
    // rendered from its own slot and only the remaining verbs stay routine.
    $hvBtnClass = [
        'start' => 'btn-outline-primary',
        'stop' => 'btn-outline-warning',
        'restart' => 'btn-outline-warning',
        'delete' => 'btn-outline-danger',
        'reset_password' => 'btn-outline-secondary',
        'credentials' => 'btn-outline-secondary',
    ];
    $hvBtnLabel = [
        'start' => 'Start',
        'stop' => 'Stop',
        'restart' => 'Restart',
        'delete' => 'Delete',
        'reset_password' => 'Reset password',
    ];

    // The reset-password form only asks for the current password when none is stored.
    $hvNeedsCurrent = ! ($hvCredentials['stored'] ?? $vmHasStoredPassword);
@endphp

@once
    {{-- Inline (not @push('css')): this partial renders in the body, after the
         head's @stack('css'), so a pushed stylesheet would never print. The
         wrapper keeps a multi-module card from emitting the block twice.
         Everything is scoped under .hv-panel and uses only tokens.css values. --}}
    <style>
        .hv-panel {
            border: 1px solid var(--color-border);
            border-radius: var(--radius-lg);
            background: var(--color-surface);
            padding: var(--space-4);
            margin-bottom: var(--space-2);
        }
        /* Non-last module row in a multi-module card keeps a stronger bottom edge. */
        .hv-panel--stacked { border-bottom-color: var(--color-border-strong); }

        /* ── Head: identity + live state + probe retry ────────────────────── */
        .hv-panel__head { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); }
        .hv-panel__title { font-size: var(--text-base); font-weight: var(--font-weight-semibold); color: var(--color-text); }
        .hv-panel__slug { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--color-text-muted); }
        .hv-panel__probe { display: inline-flex; flex-wrap: wrap; align-items: center; gap: var(--space-1); font-size: var(--text-xs); color: var(--color-danger); }
        .hv-panel__hint { display: flex; align-items: flex-start; gap: var(--space-1); margin: var(--space-2) 0 0; font-size: var(--text-xs); line-height: var(--leading-normal); color: var(--color-text-muted); }
        .hv-panel__hint .bi { color: var(--color-text-faint); }
        .hv-panel .alert { font-size: var(--text-xs); }

        /* ── Action bar: primary, routine, danger zone, utilities ─────────── */
        .hv-actions { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); margin-top: var(--space-3); padding-top: var(--space-3); border-top: 1px solid var(--color-border); }
        .hv-actions__group { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); }
        .hv-actions__group--danger {
            padding: var(--space-1) var(--space-2);
            border: 1px solid color-mix(in srgb, var(--color-danger) 35%, transparent);
            background: color-mix(in srgb, var(--color-danger) 6%, transparent);
            border-radius: var(--radius-md);
        }
        .hv-actions__group--utility { margin-left: auto; }
        .hv-actions__label { font-size: var(--text-xs); font-weight: var(--font-weight-semibold); letter-spacing: var(--tracking-wide); text-transform: uppercase; color: var(--color-danger); }
        .hv-panel .btn {
            border-radius: var(--radius-md);
            font-weight: var(--font-weight-medium);
            transition: background-color var(--duration-fast) var(--ease-default),
                border-color var(--duration-fast) var(--ease-default),
                color var(--duration-fast) var(--ease-default),
                box-shadow var(--duration-fast) var(--ease-default);
        }
        .hv-panel .btn:focus-visible { outline: 2px solid var(--color-focus-ring); outline-offset: 2px; box-shadow: none; }

        /* ── Progress strip: real layout space, height animated so the cards
              below never jump when it opens. ─────────────────────────────── */
        .hv-progress-slot { display: grid; grid-template-rows: 0fr; transition: grid-template-rows var(--duration-slow) var(--ease-default); }
        .hv-progress-slot.is-open { grid-template-rows: 1fr; }
        .hv-progress-slot__inner { overflow: hidden; min-height: 0; }
        .hv-progress { margin-top: var(--space-3); padding: var(--space-2) var(--space-3); border: 1px solid var(--color-border); border-radius: var(--radius-md); background: var(--color-bg-subtle); }
        .hv-progress__head { display: flex; align-items: center; justify-content: space-between; gap: var(--space-2); margin-bottom: var(--space-1); }
        .hv-progress__label { font-size: var(--text-sm); font-weight: var(--font-weight-medium); color: var(--color-text); }
        .hv-progress__elapsed { font-size: var(--text-xs); color: var(--color-text-muted); font-variant-numeric: tabular-nums; }
        .hv-progress .progress { height: var(--space-2); border-radius: var(--radius-full); background: var(--color-bg-muted); }
        .hv-progress .progress-bar { background-color: var(--color-primary); transition: width var(--duration-base) var(--ease-default); }
        .hv-progress__message { margin-top: var(--space-1); font-size: var(--text-xs); color: var(--color-text-muted); }
        .hv-progress__message:empty { display: none; }

        /* ── Inline feedback: errors persist until dismissed, success fades ── */
        .hv-feedback { display: flex; align-items: flex-start; gap: var(--space-2); margin-top: var(--space-3); padding: var(--space-2) var(--space-3); border: 1px solid transparent; border-radius: var(--radius-md); font-size: var(--text-sm); line-height: var(--leading-normal); color: var(--color-text); }
        .hv-feedback[data-kind="error"] { background: var(--color-danger-subtle); border-color: color-mix(in srgb, var(--color-danger) 35%, transparent); }
        .hv-feedback[data-kind="warning"] { background: var(--color-warning-subtle); border-color: color-mix(in srgb, var(--color-warning) 35%, transparent); }
        .hv-feedback[data-kind="success"] { background: var(--color-success-subtle); border-color: color-mix(in srgb, var(--color-success) 35%, transparent); }
        .hv-feedback[data-kind="info"] { background: var(--color-info-subtle); border-color: color-mix(in srgb, var(--color-info) 35%, transparent); }
        .hv-feedback[data-kind="error"] .hv-feedback__icon { color: var(--color-danger); }
        .hv-feedback[data-kind="warning"] .hv-feedback__icon { color: var(--color-warning); }
        .hv-feedback[data-kind="success"] .hv-feedback__icon { color: var(--color-success); }
        .hv-feedback[data-kind="info"] .hv-feedback__icon { color: var(--color-info); }
        .hv-feedback__body { flex: 1 1 auto; }
        .hv-feedback__dismiss { flex: 0 0 auto; padding: 0; border: 0; background: none; font-size: var(--text-xs); font-weight: var(--font-weight-medium); color: var(--color-text-muted); text-decoration: underline; }
        .hv-feedback__dismiss:hover { color: var(--color-text); }

        /* ── Disclosure: one view at a time, height animated, never overlay ── */
        .hv-disclosure { display: grid; grid-template-rows: 0fr; transition: grid-template-rows var(--duration-slow) var(--ease-default); }
        .hv-disclosure.is-open { grid-template-rows: 1fr; }
        .hv-disclosure__inner { overflow: hidden; min-height: 0; }
        .hv-view {
            margin-top: var(--space-3);
            padding: var(--space-3);
            /* Reading measure: the panel spans the card, but a form control at
               card width (the default width:100%) cannot be scanned. ch keeps
               every view a designed, left-aligned column at any breakpoint —
               and the footer's flex-end then lines the buttons up with the
               fields instead of the card edge. Below this measure (tablet,
               mobile) the column simply fills its parent. */
            max-width: 76ch;
            border: 1px solid var(--color-border);
            border-left: 3px solid var(--color-primary);
            border-radius: var(--radius-md);
            background: var(--color-bg-subtle);
        }
        .hv-view[data-hv-tone="danger"] { border-left-color: var(--color-danger); }
        .hv-view__title { font-size: var(--text-base); font-weight: var(--font-weight-semibold); color: var(--color-text); margin-bottom: var(--space-1); }
        .hv-view__lead { font-size: var(--text-sm); line-height: var(--leading-normal); color: var(--color-text-muted); margin-bottom: var(--space-3); }
        .hv-view__field { margin-bottom: var(--space-2); }
        .hv-view__note { margin-top: var(--space-1); font-size: var(--text-xs); color: var(--color-text-muted); }
        .hv-view__result { margin-top: var(--space-2); padding: var(--space-2); border: 1px solid var(--color-border); border-radius: var(--radius-md); background: var(--color-surface); }
        .hv-view__footer { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: var(--space-2); margin-top: var(--space-3); }
        .hv-disclosure code { font-size: var(--text-sm); word-break: break-all; }
        .hv-creds__row { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); }
        .hv-creds__value { letter-spacing: 0.15em; }

        @media (prefers-reduced-motion: reduce) {
            .hv-panel *, .hv-panel *::before, .hv-panel *::after { transition: none !important; animation: none !important; }
        }
    </style>
@endonce

{{-- Stacked rows: a card may hold more than one module row, so a non-last
     panel takes a stronger bottom edge (the loop-aware separator the flat
     action row used to draw with .border-bottom). --}}
<div class="hv-panel {{ ! $loop->last ? 'hv-panel--stacked' : '' }}" id="hv-panel-{{ $hvSlug }}">
    <div class="hv-panel__head">
        <strong class="hv-panel__title">{{ $mod->name }}</strong>
        <span class="hv-panel__slug">{{ $mod->slug }}</span>
        <span class="badge {{ $mode === 'manual' ? 'text-bg-warning' : 'text-bg-success' }}">{{ ucfirst($mode) }}</span>
        {{-- Live VM state — always visible so the button states are self-explanatory --}}
        <span id="hv-vm-state" class="badge text-bg-{{ $hvVmStateTheme }}" title="Live VM state on the host">{{ $hvVmStateLabel }}</span>
        <span class="hv-panel__probe" id="hv-probe-{{ $hvSlug }}" data-hv-probe @if ($hvProbeError === '') hidden @endif>
            <i class="bi bi-plug" aria-hidden="true"></i>
            <span>Host unreachable — <span data-hv-probe-text>{{ $hvProbeError }}</span></span>
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" id="hv-retry-status-{{ $hvSlug }}" data-hv-retry title="Re-check the VM on the host">
                <i class="bi bi-arrow-clockwise" aria-hidden="true"></i><span class="ms-1 small">Retry</span>
            </button>
        </span>
    </div>

    {{-- Always-visible state gate explanation. The per-action reasons also sit
         in title attributes below, but disabled buttons are not focusable, so
         the plain-language reason must live on the page itself. --}}
    <p class="hv-panel__hint" id="hv-state-hint-{{ $hvSlug }}">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <span data-hv-hint-text>{{ $hvStateHint }}</span>
    </p>

    @if ($hvFailedActId > 0 && ! empty($hvAct['error']))
        <div class="alert alert-danger py-2 px-3 mt-2 mb-0" role="alert">
            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Last {{ $hvAct['event_type'] ?? ($hvAct['action'] ?? 'action') }} failed: {{ $hvAct['error'] }}
            <a href="{{ route('admin.provisioning-events.show', $hvFailedActId) }}" class="alert-link ms-1">View event</a>
        </div>
    @endif
    @if ($hvShowLatestFailed)
        <div class="alert alert-danger py-2 px-3 mt-2 mb-0" role="alert">
            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Last {{ $latestEvt->event_type }} failed: {{ $latestEvt->last_error ?? ($latestEvt->result['error'] ?? 'Unknown error') }}
            <span class="text-muted small">{{ $latestEvt->created_at?->diffForHumans() }}</span>
            <a href="{{ route('admin.provisioning-events.show', $latestEvt) }}" class="alert-link ms-1">View event</a>
        </div>
    @endif

    {{-- Action bar. Start and Stop act in one click (the host state-checks
         them); every other verb opens its view in the disclosure below. --}}
    <div class="hv-actions" id="hv-actions-row-{{ $hvSlug }}">
        @can('hosting.edit')
            {{-- Primary: the one correct next step for the live state. --}}
            <button type="button"
                    class="btn btn-sm {{ $hvPrimaryMeta['class'] ?? 'btn-primary' }} hv-actions__primary"
                    id="hv-primary-{{ $hvSlug }}"
                    data-hv-primary
                    data-hv-action="{{ $hvPrimary ?? '' }}"
                    @if ($hvPrimary === null) hidden @endif
                    @if ($hvPrimary !== null && ! in_array($hvPrimary, ['start', 'stop'], true))
                        aria-expanded="false" aria-controls="hv-disclosure-{{ $hvSlug }}"
                    @endif
                    @disabled($hvPrimary !== null && ($hvDisabled[$hvPrimary] ?? false))
                    @if ($hvPrimary !== null && ($hvTitle[$hvPrimary] ?? '') !== '') title="{{ $hvTitle[$hvPrimary] }}" @endif
            >{{ $hvPrimaryMeta['label'] ?? '' }}</button>

            {{-- Create is a provisioning verb, not a power verb: when it is not
                 the state-aware primary it gets its own named group. It stays
                 visible but disabled while another action runs, so a permitted
                 action is never unreachable — and it is never dressed up as
                 the primary. --}}
            @if ($hvShowSecondaryCreate)
                <div class="hv-actions__group" role="group" aria-label="Provisioning">
                    <button type="button" class="btn btn-sm btn-outline-success"
                            data-hv-action="create" data-hv-create-secondary
                            aria-expanded="false" aria-controls="hv-disclosure-{{ $hvSlug }}"
                            @disabled($hvDisabled['create'])
                            @if ($hvTitle['create'] !== '') title="{{ $hvTitle['create'] }}" @endif
                    >Create VM</button>
                </div>
            @endif

            {{-- Routine lifecycle verbs — whichever is not the primary above. --}}
            <div class="hv-actions__group" role="group" aria-label="Power controls">
                @foreach (['start', 'stop'] as $act)
                    @continue($act === $hvPrimary)
                    <button type="button" class="btn btn-sm {{ $hvBtnClass[$act] }}"
                            data-hv-action="{{ $act }}" data-hv-routine="{{ $act }}"
                            @disabled($hvDisabled[$act])
                            @if ($hvTitle[$act] !== '') title="{{ $hvTitle[$act] }}" @endif
                    >{{ $hvBtnLabel[$act] }}</button>
                @endforeach
            </div>

            {{-- Destructive verbs are boxed in their own tinted zone so they
                 never read as routine power toggles. The visible label is the
                 group's accessible name (aria-labelledby, not aria-hidden) —
                 the grouping is the point, so assistive tech must hear it. --}}
            <div class="hv-actions__group hv-actions__group--danger" role="group" aria-labelledby="hv-danger-label-{{ $hvSlug }}">
                <span class="hv-actions__label" id="hv-danger-label-{{ $hvSlug }}">Danger zone</span>
                @foreach (['restart', 'delete'] as $act)
                    <button type="button" class="btn btn-sm {{ $hvBtnClass[$act] }}"
                            data-hv-action="{{ $act }}"
                            aria-expanded="false" aria-controls="hv-disclosure-{{ $hvSlug }}"
                            @disabled($hvDisabled[$act])
                            @if ($hvTitle[$act] !== '') title="{{ $hvTitle[$act] }}" @endif
                    >{{ $hvBtnLabel[$act] }}</button>
                @endforeach
            </div>

            <div class="hv-actions__group hv-actions__group--utility" role="group" aria-label="Passwords and credentials">
                <button type="button" class="btn btn-sm {{ $hvBtnClass['reset_password'] }}" data-hv-action="reset_password"
                        aria-expanded="false" aria-controls="hv-disclosure-{{ $hvSlug }}"
                        @disabled($hvDisabled['reset_password'])
                        @if ($hvTitle['reset_password'] !== '') title="{{ $hvTitle['reset_password'] }}" @endif
                >{{ $hvBtnLabel['reset_password'] }}</button>
                {{-- Credentials reveal: fetched on demand, never server-rendered --}}
                <button type="button" class="btn btn-sm {{ $hvBtnClass['credentials'] }}" data-hv-action="credentials"
                        aria-expanded="false" aria-controls="hv-disclosure-{{ $hvSlug }}"
                        @disabled($hvCredsDisabled)
                        @if ($hvCredsTitle !== '') title="{{ $hvCredsTitle }}" @endif
                >Credentials</button>
                {{-- VM Console: opens the Hyper-V VMConnect console owned by the
                     rdp-console module (route only exists while that module is
                     active). Manage-gated because it is interactive control —
                     keyboard, mouse, boot screens — the same capability class
                     as the SSH console. A disabled button is not focusable, so
                     the reason is rendered as visible text, not only a title. --}}
                @can('hosting.manage')
                    @if ($hvConsoleRouteExists)
                        @if ($hvConsoleAvailable)
                            <a href="{{ route('admin.rdp-console.vmConsole', $hostingAccount) }}"
                               class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-display" aria-hidden="true"></i><span class="ms-1">VM Console</span>
                            </a>
                        @else
                            <button type="button" class="btn btn-sm btn-outline-secondary" disabled
                                    title="{{ $hvConsoleReason }}">
                                <i class="bi bi-display" aria-hidden="true"></i><span class="ms-1">VM Console</span>
                            </button>
                            <span class="text-muted small">{{ $hvConsoleReason }}</span>
                        @endif
                    @endif
                @endcan
            </div>
        @endcan
    </div>

    {{-- Progress: a real inline region, not a float. Height animates open so
         the cards below do not jump; aria-hidden swaps with the is-open class. --}}
    <div class="hv-progress-slot {{ $hvIsRunningEffective ? 'is-open' : '' }}"
         data-hv-progress-slot
         aria-hidden="{{ $hvIsRunningEffective ? 'false' : 'true' }}">
        <div class="hv-progress-slot__inner">
            <div class="hv-progress" id="hv-action-progress">
                <div class="hv-progress__head">
                    <span class="hv-progress__label" id="hv-progress-label" role="status" aria-live="polite">{{ $hvStageLabel }}</span>
                    <span class="hv-progress__elapsed" id="hv-progress-elapsed">{{ $hvElapsedFmt }}</span>
                </div>
                <div class="progress">
                    {{-- Dynamic width is data, not a design value — the colour and
                         motion come from the scoped block above. --}}
                    <div id="hv-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated"
                         role="progressbar"
                         aria-valuenow="{{ $hvProgress }}" aria-valuemin="0" aria-valuemax="100"
                         style="width: {{ $hvProgress }}%;"></div>
                </div>
                <div class="hv-progress__message" data-hv-progress-message>{{ $hvProgressMessage }}</div>
            </div>
        </div>
    </div>

    {{-- Inline feedback. aria-live sits on the container; the JS swaps the
         inner role between alert (failures, persistent until dismissed or
         superseded) and status (success, auto-dismissed). --}}
    <div class="hv-feedback" id="hv-alert-{{ $hvSlug }}" data-kind="info" aria-live="polite" aria-atomic="true" hidden>
        <i class="bi bi-info-circle-fill hv-feedback__icon" data-hv-alert-icon aria-hidden="true"></i>
        <div class="hv-feedback__body" data-hv-alert-body role="status"></div>
        <button type="button" class="hv-feedback__dismiss" data-hv-alert-dismiss hidden>Dismiss</button>
    </div>

    @can('hosting.edit')
        {{-- Disclosure: exactly one view open at a time, swapped inline. --}}
        <div class="hv-disclosure" id="hv-disclosure-{{ $hvSlug }}" data-hv-disclosure hidden aria-hidden="true">
            <div class="hv-disclosure__inner">
                {{-- Create: template + guest credentials + the two opt-ins. --}}
                <div class="hv-view" data-hv-view="create" hidden>
                    <h6 class="hv-view__title">Create Hyper-V VM</h6>
                    <p class="hv-view__lead">Provision a new VM on the Hyper-V host with this product's CPU / RAM / disk. The host is called for real — a refusal fails loudly, nothing is faked.</p>
                    <form method="POST" action="{{ route('admin.hosting.module-action', $hostingAccount) }}" data-hv-form data-hv-form-action="create">
                        @csrf
                        <input type="hidden" name="module_slug" value="{{ $hvSlug }}">
                        <input type="hidden" name="action" value="create">
                        @if (! empty($hvOptions))
                            <div class="hv-view__field">
                                <label for="hv-template-{{ $hvSlug }}" class="form-label small mb-1">Template VM</label>
                                <select id="hv-template-{{ $hvSlug }}" name="template_vm" class="form-select form-select-sm" required aria-label="Template VM">
                                    @foreach ($hvOptions as $opt)
                                        <option value="{{ $opt['name'] }}" @selected($opt['name'] === $hvDefault)>{{ $opt['label'] }}</option>
                                    @endforeach
                                </select>
                                <div class="form-text small">Template must be shut down (Off)</div>
                            </div>
                        @elseif ($hvCuratedCount > 0)
                            <p class="text-danger small mb-2">No templates are available for this product on this server — contact support.</p>
                        @endif
                        <div class="hv-view__field">
                            <label for="hv-guest-username-{{ $hvSlug }}" class="form-label small mb-1">Administrator username</label>
                            <input id="hv-guest-username-{{ $hvSlug }}" name="guest_username" type="text" class="form-control form-control-sm"
                                   value="{{ $vmGuestUsername }}" maxlength="64" autocomplete="username" aria-label="Administrator username">
                        </div>
                        <div class="hv-view__field">
                            {{-- No Generate button here on purpose: this password must
                                 match the template image, it must not be invented. --}}
                            <label for="hv-guest-password-{{ $hvSlug }}" class="form-label small mb-1">Administrator password</label>
                            <input id="hv-guest-password-{{ $hvSlug }}" name="guest_password" type="password" class="form-control form-control-sm"
                                   autocomplete="new-password" aria-label="Administrator password" placeholder="The password already set inside the template image">
                            <div class="form-text small">Must match the password already set inside the template image. Stored encrypted; used for RDP and for <em>Reset password</em> (which changes it inside Windows). Leave blank if unknown.</div>
                        </div>
                        <div class="form-check hv-view__field">
                            <input class="form-check-input" type="checkbox" name="apply_password" value="1" id="hv-apply-password-{{ $hvSlug }}">
                            <label class="form-check-label small" for="hv-apply-password-{{ $hvSlug }}">Set a new Administrator password after the VM starts (view it under Credentials)</label>
                            <div class="form-text small">The panel authenticates with the current password above, then sets a new one inside the guest.</div>
                        </div>
                        <div class="form-check hv-view__field">
                            <input class="form-check-input" type="checkbox" name="start_after_create" value="1" id="hv-start-after-create-{{ $hvSlug }}" checked>
                            <label class="form-check-label small" for="hv-start-after-create-{{ $hvSlug }}">Start the VM after creation</label>
                        </div>
                        <div class="hv-view__footer">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-hv-cancel>Cancel</button>
                            <button type="submit" class="btn btn-sm btn-success" data-hv-submit>Create VM</button>
                        </div>
                    </form>
                </div>

                {{-- Restart: typed host-name confirmation. --}}
                <div class="hv-view" data-hv-view="restart" data-hv-tone="danger" data-hv-confirm-expected="{{ $hvHostName }}" hidden>
                    <h6 class="hv-view__title">Restart VM</h6>
                    <p class="hv-view__lead">Reboot this VM? Only a RUNNING VM is rebooted — a stopped VM is refused, never surprise-started.</p>
                    <form method="POST" action="{{ route('admin.hosting.module-action', $hostingAccount) }}" data-hv-form data-hv-form-action="restart">
                        @csrf
                        <input type="hidden" name="module_slug" value="{{ $hvSlug }}">
                        <input type="hidden" name="action" value="restart">
                        <div class="hv-view__field">
                            <label class="form-label small mb-1" for="hv-restart-confirm-{{ $hvSlug }}">Type <code>{{ $hvHostName }}</code> to confirm</label>
                            <input id="hv-restart-confirm-{{ $hvSlug }}" name="confirm" type="text" class="form-control form-control-sm"
                                   autocomplete="off" required placeholder="{{ $hvHostName }}"
                                   data-hv-confirm-input aria-label="Type {{ $hvHostName }} to confirm restart">
                        </div>
                        <div class="hv-view__footer">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-hv-cancel>Cancel</button>
                            <button type="submit" class="btn btn-sm btn-warning" id="hv-restart-submit-{{ $hvSlug }}" data-hv-submit disabled>Restart VM</button>
                        </div>
                    </form>
                </div>

                {{-- Delete: typed host-name confirmation + the VHD choice. The
                     hidden 0 must stay before the checkbox: PHP keeps the last
                     value for a repeated key, so unchecked posts 0 and checked
                     posts 1. --}}
                <div class="hv-view" data-hv-view="delete" data-hv-tone="danger" data-hv-confirm-expected="{{ $hvHostName }}" hidden>
                    <h6 class="hv-view__title">Delete VM (permanent)</h6>
                    <p class="hv-view__lead">PERMANENTLY delete this VM? A RUNNING VM is refused — Stop it first, then Delete.</p>
                    <form method="POST" action="{{ route('admin.hosting.module-action', $hostingAccount) }}" data-hv-form data-hv-form-action="delete">
                        @csrf
                        <input type="hidden" name="module_slug" value="{{ $hvSlug }}">
                        <input type="hidden" name="action" value="delete">
                        <div class="hv-view__field">
                            <label class="form-label small mb-1" for="hv-delete-confirm-{{ $hvSlug }}">Type <code>{{ $hvHostName }}</code> to confirm</label>
                            <input id="hv-delete-confirm-{{ $hvSlug }}" name="confirm" type="text" class="form-control form-control-sm"
                                   autocomplete="off" required placeholder="{{ $hvHostName }}"
                                   data-hv-confirm-input aria-label="Type {{ $hvHostName }} to confirm delete">
                        </div>
                        <input type="hidden" name="delete_vhd" value="0">
                        <div class="form-check hv-view__field">
                            <input class="form-check-input" type="checkbox" name="delete_vhd" value="1" id="hv-delete-vhd-{{ $hvSlug }}" checked>
                            <label class="form-check-label small" for="hv-delete-vhd-{{ $hvSlug }}">Also delete the recorded VHD (uncheck to orphan the disk)</label>
                        </div>
                        <div class="hv-view__footer">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-hv-cancel>Cancel</button>
                            <button type="submit" class="btn btn-sm btn-danger" id="hv-delete-submit-{{ $hvSlug }}" data-hv-submit disabled>Delete VM</button>
                        </div>
                    </form>
                </div>

                {{-- Reset Administrator password. --}}
                <div class="hv-view" data-hv-view="reset_password" hidden>
                    <h6 class="hv-view__title">Reset Administrator password</h6>
                    <p class="hv-view__lead">Reset the Administrator password inside the running guest. The VM must be running.</p>
                    <form method="POST" action="{{ route('admin.hosting.reset-vm-password', $hostingAccount) }}" data-hv-form data-hv-form-action="reset_password">
                        @csrf
                        <div class="hv-view__field">
                            <label for="hv-reset-username-{{ $hvSlug }}" class="form-label small mb-1">Administrator username</label>
                            <input id="hv-reset-username-{{ $hvSlug }}" name="username" type="text" class="form-control form-control-sm"
                                   value="{{ $vmGuestUsername }}" maxlength="64" autocomplete="username" aria-label="Administrator username">
                        </div>
                        <div class="hv-view__field">
                            {{-- WHY this field has its own id instead of reusing any
                                 container id: the Generate button targets it by id
                                 and must land on the INPUT — a container with the
                                 same id would silently swallow the value (a real
                                 bug when this form lived in a modal whose root the
                                 id collided with). --}}
                            <label for="hv-reset-new-password-{{ $hvSlug }}" class="form-label small mb-1">New password</label>
                            <div class="input-group input-group-sm">
                                <input id="hv-reset-new-password-{{ $hvSlug }}" name="password" type="password" class="form-control"
                                       autocomplete="new-password" required minlength="8" aria-label="New password">
                                <button type="button" class="btn btn-outline-secondary" data-hv-generate-target="hv-reset-new-password-{{ $hvSlug }}">Generate</button>
                            </div>
                        </div>
                        <div class="hv-view__field">
                            <label for="hv-reset-password-confirm-{{ $hvSlug }}" class="form-label small mb-1">Confirm new password</label>
                            <input id="hv-reset-password-confirm-{{ $hvSlug }}" name="password_confirmation" type="password" class="form-control form-control-sm"
                                   autocomplete="new-password" required minlength="8" aria-label="Confirm new password">
                        </div>
                        <div class="hv-view__field {{ $hvNeedsCurrent ? '' : 'd-none' }}" id="hv-reset-current-wrap-{{ $hvSlug }}">
                            <label for="hv-reset-current-{{ $hvSlug }}" class="form-label small mb-1">Current password</label>
                            <input id="hv-reset-current-{{ $hvSlug }}" name="current_password" type="password" class="form-control form-control-sm"
                                   autocomplete="current-password" @if ($hvNeedsCurrent) required @endif aria-label="Current password">
                            <div class="form-text small">Required because no password is stored for this account.</div>
                        </div>
                        <div id="hv-reset-result" class="hv-view__result d-none" role="status" aria-live="polite">
                            <div class="d-flex align-items-center justify-content-between gap-2">
                                <code id="hv-reset-result-password"></code>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="hv-reset-copy">Copy</button>
                            </div>
                            <div class="hv-view__note">Copy this password now — it will not be shown again.</div>
                        </div>
                        <div class="hv-view__footer">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-hv-cancel>Cancel</button>
                            <button type="submit" class="btn btn-sm btn-primary" data-hv-submit>Reset password</button>
                        </div>
                    </form>
                </div>

                {{-- Credentials reveal: a plain view (nothing posts). The password
                     is fetched on demand via the vm-credentials endpoint and is
                     never server-rendered into this HTML. --}}
                <div class="hv-view" data-hv-view="credentials" hidden>
                    <h6 class="hv-view__title">Administrator credentials</h6>
                    <p class="hv-view__lead">The password is fetched only when you click Show or Copy, and is never written into the page.</p>
                    <div class="hv-view__field">
                        <span class="form-label small mb-1 d-block">Username</span>
                        <code id="hv-credentials-username-{{ $hvSlug }}" data-hv-creds-username>{{ $vmGuestUsername }}</code>
                    </div>
                    <div class="hv-view__field">
                        <span class="form-label small mb-1 d-block">Password</span>
                        <div class="hv-creds__row">
                            <code id="hv-credentials-password-{{ $hvSlug }}" class="hv-creds__value" data-hv-creds-password>••••••••</code>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="hv-credentials-show-{{ $hvSlug }}" data-hv-creds-show title="Show password" aria-label="Show password">
                                <i class="bi bi-eye" aria-hidden="true"></i><span id="hv-credentials-show-text-{{ $hvSlug }}" class="ms-1 small" data-hv-creds-show-text>Show</span>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="hv-credentials-copy-{{ $hvSlug }}" data-hv-creds-copy title="Copy password" aria-label="Copy password">
                                <i class="bi bi-clipboard" aria-hidden="true"></i><span class="ms-1 small">Copy</span>
                            </button>
                            <span id="hv-credentials-feedback-{{ $hvSlug }}" class="text-success small d-none" role="status">Copied!</span>
                        </div>
                    </div>
                    <div id="hv-credentials-error-{{ $hvSlug }}" class="alert alert-danger py-2 px-3 mt-2 mb-0 d-none" role="alert"></div>
                    <div class="hv-view__footer">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-hv-cancel>Close</button>
                    </div>
                </div>
            </div>
        </div>
    @endcan
</div>

@once
    @push('js')
        @include('admin.hosting.partials._hyperv-actions-js', [
            'hostingAccount' => $hostingAccount,
            'hvSlug' => $hvSlug,
        ])
    @endpush
@endonce
