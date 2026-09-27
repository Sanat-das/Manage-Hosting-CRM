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
     * for verbs, GET vm-status for live state/progress. Guest-credential and
     * password-reset affordances stay Hyper-V-only for now.
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
        $computeStateTheme = 'secondary';
    } elseif (is_string($computeStateRaw) && $computeStateRaw !== '') {
        $computeLow = strtolower(trim($computeStateRaw));
        if ($computeLow === 'running') { $computeStateLabel = 'Running'; $computeStateTheme = 'success'; }
        elseif ($computeLow === 'off' || $computeLow === 'stopped') { $computeStateLabel = 'Off'; $computeStateTheme = 'secondary'; }
        elseif ($computeLow === 'saved') { $computeStateLabel = 'Saved'; $computeStateTheme = 'warning'; }
        else { $computeStateLabel = $computeStateRaw; $computeStateTheme = 'secondary'; }
    } else {
        $computeStateLabel = 'Unknown';
        $computeStateTheme = 'secondary';
    }

    $computeCanCreate = (bool) ($computeCan['create'] ?? false) && $computeOptions !== [];
    $computeCanStart = (bool) ($computeCan['start'] ?? false);
    $computeCanStop = (bool) ($computeCan['stop'] ?? false);
    $computeCanDelete = (bool) ($computeCan['delete'] ?? false);
    $computeProbeError = trim((string) ($computeVm['probe_error'] ?? ''));
@endphp
<div class="ma-entry" id="compute-panel-{{ $computeSlug }}">
    <div class="d-flex flex-wrap align-items-center gap-2 py-2">
        <strong>{{ $computeName }}</strong>
        <span class="text-muted small">{{ $computeSlug }}</span>
        <span class="badge {{ $computeMode === 'manual' ? 'text-bg-warning' : 'text-bg-success' }}">{{ ucfirst($computeMode) }}</span>
        <span id="compute-state-{{ $computeSlug }}" class="badge text-bg-{{ $computeStateTheme }}">{{ $computeStateLabel }}</span>
        <span class="text-muted small" data-compute-hint>
            @if ($computeIsRunning)
                An action is already running — the controls unlock when it finishes.
            @elseif ($computeProbeError !== '')
                Host unreachable — actions may fail.
            @elseif (($computeVm['exists'] ?? null) === false)
                No VM exists on the host yet — create one first.
            @endif
        </span>
        <span class="ms-auto d-flex flex-wrap align-items-center gap-2">
            @can('hosting.edit')
                <button type="button" class="btn btn-sm btn-success" data-compute-action="create"
                        @if(! $computeCanCreate || $computeIsRunning) disabled @endif>Create VM</button>
                <button type="button" class="btn btn-sm btn-primary" data-compute-action="start"
                        @if(! $computeCanStart || $computeIsRunning) disabled @endif>Start</button>
                <button type="button" class="btn btn-sm btn-warning" data-compute-action="stop"
                        @if(! $computeCanStop || $computeIsRunning) disabled @endif>Stop</button>
                @if ($computeCanRestart)
                    <button type="button" class="btn btn-sm btn-warning" data-compute-action="restart"
                            @if($computeIsRunning || ($computeVm['exists'] ?? null) !== true) disabled @endif>Restart</button>
                @endif
                <button type="button" class="btn btn-sm btn-outline-danger" data-compute-action="delete"
                        @if(! $computeCanDelete || $computeIsRunning) disabled @endif>Delete</button>
            @endcan
            @if ($computeProbeError !== '')
                <button type="button" class="btn btn-sm btn-outline-secondary" data-compute-retry>Retry</button>
            @endif
        </span>
    </div>

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

    <div class="alert py-2 px-3 my-2 d-none" role="alert" data-compute-feedback></div>

    @can('hosting.edit')
        <div class="compute-disclosure border rounded-2 p-3 mb-2" data-compute-disclosure hidden aria-hidden="true">
            {{-- Create --}}
            <div data-compute-view="create" hidden>
                <h6 class="fw-semibold mb-2">Create VM on {{ $computeName }}</h6>
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
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-compute-cancel>Cancel</button>
                        <button type="submit" class="btn btn-sm btn-success" data-compute-submit>Create VM</button>
                    </div>
                </form>
            </div>

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
        var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || @json(csrf_token());

        var POLL_MS = 3000;
        var FIRST_POLL_MS = 800;
        var RELOAD_MS = 1200;
        var RUNNING_REASON = 'An action is already running — please wait.';

        var statePill = document.getElementById('compute-state-' + slug);
        var hint = panel.querySelector('[data-compute-hint]');
        var progress = panel.querySelector('[data-compute-progress]');
        var progressBar = panel.querySelector('[data-compute-progress-bar]');
        var progressLabel = panel.querySelector('[data-compute-progress-label]');
        var progressElapsed = panel.querySelector('[data-compute-progress-elapsed]');
        var progressMessage = panel.querySelector('[data-compute-progress-message]');
        var feedback = panel.querySelector('[data-compute-feedback]');
        var disclosure = panel.querySelector('[data-compute-disclosure]');
        var buttons = Array.prototype.slice.call(panel.querySelectorAll('[data-compute-action]'));

        var state = { last: null, busy: false, open: null, trigger: null, poll: null, alert: null };

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
            var action = status.action || null;
            var vm = status.vm || null;
            var can = status.can || {};
            var reasons = status.reasons || {};
            var running = !!(action && action.running);

            if (statePill) {
                var meta = stateMeta(vm);
                statePill.textContent = meta.label;
                statePill.className = 'badge text-bg-' + meta.theme;
            }

            if (hint) {
                var text = '';
                if (running) text = 'An action is already running — the controls unlock when it finishes.';
                else if (vm && vm.probe_error) text = 'Host unreachable — actions may fail.';
                else if (vm && vm.exists === false) text = 'No VM exists on the host yet — create one first.';
                hint.textContent = text;
            }

            buttons.forEach(function (btn) {
                var act = btn.getAttribute('data-compute-action');
                if (act === 'restart') { btn.disabled = running || !(vm && vm.exists === true); return; }
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
                if (action.status === 'failed') showFeedback('error', action.error || action.message || 'Action failed.');
                else if (action.status === 'completed') showFeedback('success', action.message || 'Done.');
                setTimeout(function () { window.location.reload(); }, 1500);
            }).catch(function () { /* transient poll errors keep the loop alive */ });
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
                showFeedback('success', data.message || 'Done.');
                setTimeout(function () { window.location.reload(); }, RELOAD_MS);
            }).catch(function (err) {
                state.busy = false;
                showFeedback('error', (err && err.message) ? err.message : 'Network error.');
                setProgress(false);
                fetchStatus(false);
            });
        }

        panel.addEventListener('click', function (ev) {
            var target = ev.target;
            if (!target || !target.closest) return;

            if (target.closest('[data-compute-cancel]')) { closeView(true); return; }

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
            if (action === 'create' || action === 'restart' || action === 'delete') { openView(action, btn); return; }
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
            ev.preventDefault();
            submitForm(form, form.getAttribute('data-compute-form-action') || '');
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
