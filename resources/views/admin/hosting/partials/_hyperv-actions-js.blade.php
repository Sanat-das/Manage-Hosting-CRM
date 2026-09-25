<script>
/* Hyper-V action panel driver — inline disclosure, inline progress, inline
   feedback. No overlays exist here: the Bootstrap JS namespace and toastr are
   undefined in this build, and native browser dialogs are off-limits. Keep it
   that way.

   Endpoints (unchanged):
     POST moduleActionUrl   { module_slug, action, confirm, delete_vhd, template_vm,
                              start_after_create, guest_username, guest_password, apply_password }
     POST resetUrl          { username, password, password_confirmation, current_password }
     GET  statusUrl         presenter payload: { action, vm, can, reasons, credentials }
     GET  credentialsUrl    { ok, stored, username, password } */
document.addEventListener('DOMContentLoaded', function () {
    var slug = @json($hvSlug ?? 'hyperv');
    var panel = document.getElementById('hv-panel-' + slug);
    if (!panel) return;

    // ── Config from the server ───────────────────────────────────────────
    var vmStatus = @json($vmStatus ?? null);
    var moduleActionUrl = @json(route('admin.hosting.module-action', $hostingAccount));
    var statusUrl = @json(route('admin.hosting.vm-status', $hostingAccount));
    var credentialsUrl = @json(route('admin.hosting.vm-credentials', $hostingAccount));
    var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || @json(csrf_token());

    // Keep in sync with the scoped tokens in the partial.
    var REDUCED_MOTION = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    var COLLAPSE_MS = 240;        // --duration-slow
    var POLL_INTERVAL_MS = 3000;  // build progress cadence
    var FIRST_POLL_DELAY_MS = 800;
    var SUCCESS_ALERT_MS = 4000;  // success messages are transient
    var RELOAD_DELAY_MS = 1200;   // let the success message be read, then reload
    var RUNNING_REASON = 'An action is already running — please wait.';
    var NO_CREDENTIALS_REASON = 'No Administrator credentials are stored for this VM.';

    // ── Elements (data hooks; the legacy ids stay for compatibility) ─────
    var actionsRow = document.getElementById('hv-actions-row-' + slug);
    var primaryBtn = document.getElementById('hv-primary-' + slug);
    var statePill = document.getElementById('hv-vm-state');
    var probeRow = document.getElementById('hv-probe-' + slug);
    var probeText = probeRow ? probeRow.querySelector('[data-hv-probe-text]') : null;
    var hintText = panel.querySelector('[data-hv-hint-text]');
    var progressSlot = panel.querySelector('[data-hv-progress-slot]');
    var progressBar = document.getElementById('hv-progress-bar');
    var progressLabel = document.getElementById('hv-progress-label');
    var progressElapsed = document.getElementById('hv-progress-elapsed');
    var progressMessage = panel.querySelector('[data-hv-progress-message]');
    var feedback = document.getElementById('hv-alert-' + slug);
    var feedbackIcon = feedback ? feedback.querySelector('[data-hv-alert-icon]') : null;
    var feedbackBody = feedback ? feedback.querySelector('[data-hv-alert-body]') : null;
    var feedbackDismiss = feedback ? feedback.querySelector('[data-hv-alert-dismiss]') : null;
    var disclosure = document.getElementById('hv-disclosure-' + slug);
    var routineBtns = actionsRow ? Array.prototype.slice.call(actionsRow.querySelectorAll('[data-hv-routine]')) : [];
    // The provisioning verb rendered outside the primary slot (unknown /
    // busy states). Generic [data-hv-action] handling covers its disabled
    // state; only its visibility needs the primary-slot coordination below.
    var secondaryCreateBtn = actionsRow ? actionsRow.querySelector('[data-hv-create-secondary]') : null;

    // Replaced by the credentials module below; declared here so the
    // disclosure code can call them without ordering games.
    var resetCredentialsView = function () {};
    var invalidateCredentialsView = function () {};

    var state = {
        last: vmStatus,
        busy: false,    // a submit is in flight
        open: null,     // action whose view is open
        trigger: null,  // button that opened it (focus returns here on close)
        credsStored: !!(vmStatus && vmStatus.credentials && vmStatus.credentials.stored) || {{ $hvCredsStored ? 'true' : 'false' }},
        alertTimer: null,
        closeTimer: null
    };
    var pollTimer = null;

    // ── Helpers ──────────────────────────────────────────────────────────
    function fmtElapsed(sec) {
        sec = Math.max(0, parseInt(sec, 10) || 0);
        var m = Math.floor(sec / 60);
        var s = sec % 60;
        return (m < 10 ? '0' + m : '' + m) + ':' + (s < 10 ? '0' + s : '' + s);
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

    // Mirror of the server-rendered hint in the partial — same wording, so the
    // line does not visibly rephrase itself after a poll.
    function computeHint(status) {
        if (!status || typeof status !== 'object') return '';
        var action = status.action || null;
        var vm = status.vm || null;
        if (action && action.running) {
            return 'An action is already running — the controls unlock when it finishes.';
        }
        var probeErr = vm && vm.probe_error ? String(vm.probe_error).trim() : '';
        if (probeErr !== '') {
            return 'Host unreachable — actions may fail. Fix connectivity, then Retry.';
        }
        if (!vm) return '';
        if (vm.exists === false) return 'No VM exists on the host yet — create one first.';
        var low = (vm.state || '').toString().toLowerCase().trim();
        if (low === 'running') return 'VM is running — Stop is graceful, Restart needs the account name typed.';
        if (low === 'off' || low === 'saved') return 'VM is stopped — Start is safe; Delete needs the account name typed.';
        if (vm.exists === true) return 'VM state is unknown — re-check the host with Retry.';
        return 'Live VM state is unavailable right now — the controls follow the last known state.';
    }

    function setTitle(btn, text) {
        if (!btn) return;
        if (text) btn.setAttribute('title', text);
        else btn.removeAttribute('title');
    }

    function setProgressLabel(text) {
        if (progressLabel && text) progressLabel.textContent = text;
    }

    function setProgressBar(pct) {
        if (!progressBar) return;
        var value = Math.max(0, Math.min(100, parseInt(pct, 10) || 0));
        progressBar.style.width = value + '%';
        progressBar.setAttribute('aria-valuenow', String(value));
    }

    function setProgressMessage(text) {
        if (progressMessage) progressMessage.textContent = text || '';
    }

    function setProgress(open) {
        if (!progressSlot) return;
        if (open) {
            progressSlot.classList.add('is-open');
            progressSlot.setAttribute('aria-hidden', 'false');
        } else {
            progressSlot.classList.remove('is-open');
            progressSlot.setAttribute('aria-hidden', 'true');
        }
    }

    function setLoading(btn, on) {
        if (!btn) return;
        if (on) {
            if (!btn.dataset.hvLabel) btn.dataset.hvLabel = btn.textContent.trim();
            btn.textContent = '';
            var spinner = document.createElement('span');
            spinner.className = 'spinner-border spinner-border-sm me-1';
            spinner.setAttribute('role', 'status');
            spinner.setAttribute('aria-hidden', 'true');
            btn.appendChild(spinner);
            btn.appendChild(document.createTextNode(btn.dataset.hvLabel));
        } else if (btn.dataset.hvLabel) {
            btn.textContent = btn.dataset.hvLabel;
            delete btn.dataset.hvLabel;
        }
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

    function parseJsonResponse(res) {
        return res.text().then(function (text) {
            var data = null;
            try { data = JSON.parse(text); } catch (ignored) { data = null; }
            if (!res.ok) {
                var msg = (data && (data.message || data.error)) ? (data.message || data.error) : ('Request failed (' + res.status + ').');
                // Laravel validation errors: { message, errors: { field: [msg] } }
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

    // ── Inline feedback ──────────────────────────────────────────────────
    var ALERT_ICONS = {
        error: 'bi-exclamation-triangle-fill',
        warning: 'bi-exclamation-triangle-fill',
        success: 'bi-check-circle-fill',
        info: 'bi-info-circle-fill'
    };

    function showAlert(kind, message) {
        if (!feedback) return;
        if (state.alertTimer) { clearTimeout(state.alertTimer); state.alertTimer = null; }
        var text = (message === null || message === undefined) ? '' : String(message);
        feedback.setAttribute('data-kind', kind);
        feedback.hidden = false;
        if (feedbackIcon) {
            feedbackIcon.className = 'bi ' + (ALERT_ICONS[kind] || ALERT_ICONS.info) + ' hv-feedback__icon';
        }
        if (feedbackBody) {
            // textContent only — host output can be echoed back in these messages.
            feedbackBody.textContent = text;
            feedbackBody.setAttribute('role', (kind === 'error' || kind === 'warning') ? 'alert' : 'status');
        }
        // Failures persist until dismissed or superseded; success is transient.
        if (feedbackDismiss) feedbackDismiss.hidden = (kind === 'success');
        if (kind === 'success') {
            state.alertTimer = setTimeout(hideAlert, SUCCESS_ALERT_MS);
        }
    }

    function hideAlert() {
        if (!feedback) return;
        if (state.alertTimer) { clearTimeout(state.alertTimer); state.alertTimer = null; }
        feedback.hidden = true;
        if (feedbackBody) feedbackBody.textContent = '';
    }

    // ── Buttons / primary action ─────────────────────────────────────────
    function updateButtons(can, reasons, running) {
        if (!actionsRow) return;
        actionsRow.querySelectorAll('[data-hv-action]').forEach(function (btn) {
            var act = btn.getAttribute('data-hv-action');
            if (!act) return;
            if (act === 'credentials') {
                // No can key exists for credentials — its gate is credentials.stored.
                btn.disabled = running || !state.credsStored;
                setTitle(btn, running ? RUNNING_REASON : (!state.credsStored ? NO_CREDENTIALS_REASON : ''));
                return;
            }
            var canThis = !!can[act];
            var reason = (reasons[act] || '').toString();
            btn.disabled = running || !canThis;
            setTitle(btn, running ? RUNNING_REASON : reason);
        });
    }

    function setBusyButtons() {
        if (!actionsRow) return;
        actionsRow.querySelectorAll('[data-hv-action]').forEach(function (btn) { btn.disabled = true; });
    }

    var PRIMARY_META = {
        create: { label: 'Create VM', cls: 'btn-success' },
        start: { label: 'Start VM', cls: 'btn-primary' },
        stop: { label: 'Stop VM', cls: 'btn-warning' }
    };

    function primaryFor(vm) {
        if (!vm) return null;
        if (vm.exists === false) return 'create';
        var low = (vm.state || '').toString().toLowerCase().trim();
        if (low === 'running') return 'stop';
        if (low === 'off' || low === 'saved') return 'start';
        return null;
    }

    function syncRoutine(primaryAction, vm) {
        routineBtns.forEach(function (btn) {
            btn.hidden = btn.getAttribute('data-hv-routine') === primaryAction;
        });
        // The secondary Create must never duplicate the primary slot, and it is
        // refused only when a VM is observed on the host — the same rule the
        // server applies when it decides to render the button at all.
        if (secondaryCreateBtn) {
            secondaryCreateBtn.hidden = primaryAction === 'create' || !!(vm && vm.exists === true);
        }
    }

    function syncPrimary(vm, can, running) {
        if (!primaryBtn) return;
        var act = primaryFor(vm);
        var canThis = act !== null ? !!can[act] : false;
        // Match the server rule: no primary when the can-matrix forbids it.
        // While an action runs, keep the button visible but disabled instead of
        // popping it out of the row (that would make the row jump).
        if (act !== null && !canThis && !running) act = null;

        if (act === null) {
            primaryBtn.hidden = true;
            primaryBtn.setAttribute('data-hv-action', '');
            primaryBtn.removeAttribute('aria-expanded');
            primaryBtn.removeAttribute('aria-controls');
            syncRoutine(null, vm);
            return;
        }

        if (primaryBtn.getAttribute('data-hv-action') !== act) {
            var meta = PRIMARY_META[act];
            primaryBtn.setAttribute('data-hv-action', act);
            primaryBtn.textContent = meta.label;
            primaryBtn.className = 'btn btn-sm ' + meta.cls + ' hv-actions__primary';
            if (act === 'create') {
                primaryBtn.setAttribute('aria-expanded', 'false');
                primaryBtn.setAttribute('aria-controls', 'hv-disclosure-' + slug);
            } else {
                primaryBtn.removeAttribute('aria-expanded');
                primaryBtn.removeAttribute('aria-controls');
            }
        }
        primaryBtn.hidden = false;
        var disabled = running || !canThis;
        primaryBtn.disabled = disabled;
        setTitle(primaryBtn, disabled
            ? (running ? RUNNING_REASON : ((state.last && state.last.reasons && state.last.reasons[act]) || ''))
            : '');
        syncRoutine(act, vm);
    }

    // ── Disclosure (one at a time, focus managed) ────────────────────────
    function setExpanded(trigger, expanded) {
        if (!trigger || !trigger.hasAttribute('aria-controls')) return;
        trigger.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }

    function focusFirst(container) {
        var candidates = container.querySelectorAll('input:not([type="hidden"]), select, textarea, button, a[href]');
        for (var i = 0; i < candidates.length; i++) {
            var el = candidates[i];
            if (!el.disabled && el.offsetParent !== null) {
                try { el.focus(); } catch (e) {}
                return;
            }
        }
    }

    function resetConfirmView(view) {
        var input = view.querySelector('[data-hv-confirm-input]');
        var submit = view.querySelector('[data-hv-submit]');
        if (input) input.value = '';
        if (submit) submit.disabled = true;
    }

    function openDisclosure(action, trigger) {
        if (!disclosure) return;
        if (state.open === action) { closeDisclosure(true); return; }

        disclosure.querySelectorAll('[data-hv-view]').forEach(function (view) {
            var isTarget = view.getAttribute('data-hv-view') === action;
            view.hidden = !isTarget;
            if (isTarget && (action === 'restart' || action === 'delete')) resetConfirmView(view);
        });

        state.open = action;
        state.trigger = trigger || null;
        if (state.closeTimer) { clearTimeout(state.closeTimer); state.closeTimer = null; }
        disclosure.hidden = false;
        disclosure.setAttribute('aria-hidden', 'false');
        void disclosure.offsetHeight; // flush the display change so the height transition runs
        disclosure.classList.add('is-open');
        setExpanded(trigger, true);
        if (action === 'credentials') resetCredentialsView();
        focusFirst(disclosure);
    }

    function closeDisclosure(focusTrigger) {
        if (!disclosure || !state.open) return;
        var trigger = state.trigger;
        state.open = null;
        disclosure.classList.remove('is-open');
        setExpanded(trigger, false);

        var finalize = function () {
            if (state.open === null) {
                disclosure.hidden = true;
                disclosure.setAttribute('aria-hidden', 'true');
            }
        };
        if (REDUCED_MOTION) {
            finalize();
        } else {
            if (state.closeTimer) clearTimeout(state.closeTimer);
            state.closeTimer = setTimeout(finalize, COLLAPSE_MS);
        }

        if (focusTrigger && trigger && document.contains(trigger) && !trigger.disabled) {
            try { trigger.focus(); } catch (e) {}
        }
    }

    // ── Status → DOM ─────────────────────────────────────────────────────
    function applyStatus(status) {
        if (!status || typeof status !== 'object') return;
        state.last = status;

        var action = status.action || null;
        var vm = status.vm || null;
        var can = status.can || {};
        var reasons = status.reasons || {};
        var running = !!(action && action.running);

        if (statePill) {
            var pill = vmStateLabel(vm);
            statePill.textContent = pill.label;
            statePill.className = 'badge text-bg-' + pill.theme;
        }

        var probeErr = vm && vm.probe_error ? String(vm.probe_error).trim() : '';
        if (probeRow) {
            if (probeErr !== '') {
                if (probeText) probeText.textContent = probeErr;
                probeRow.hidden = false;
            } else {
                probeRow.hidden = true;
            }
        }

        if (hintText) hintText.textContent = computeHint(status);

        if (running && action) {
            setProgress(true);
            setProgressLabel(action.stage_label || action.stage || 'Working…');
            setProgressBar(action.progress || 0);
            if (progressElapsed) progressElapsed.textContent = fmtElapsed(action.elapsed || 0);
            setProgressMessage(action.message || '');
        } else if (!state.busy) {
            setProgress(false);
        }

        // The presenter always sends credentials; only trust it when present.
        if (status.credentials && typeof status.credentials === 'object') {
            state.credsStored = !!status.credentials.stored;
        }
        updateButtons(can, reasons, running);
        syncPrimary(vm, can, running);
        if (state.busy) setBusyButtons();
    }

    // ── Polling (3s cadence, first check after 800ms) ────────────────────
    function pollOnce() {
        return fetch(statusUrl, {
            method: 'GET',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function (res) {
            return res.json().catch(function () { return null; }).then(function (data) {
                var payload = data;
                if (!payload) throw new Error('Empty status response');
                // Some responses wrap the presenter payload in { data: {...} }.
                if (payload.data && typeof payload.data === 'object' && (payload.data.vm || payload.data.can)) {
                    payload = payload.data;
                }
                if (payload.ok === false && !payload.vm && !payload.can) {
                    throw new Error(payload.message || payload.error || 'Status check failed');
                }
                applyStatus(payload);

                var action = payload.action || null;
                var running = !!(action && action.running);
                if (running) return;

                // Terminal: stop and report, then reload so the panel shows the
                // server's resumed state (and its persistent failure banner).
                stopPolling();
                if (action) {
                    if (action.status === 'failed') {
                        showAlert('error', action.error || action.message || 'Action failed.');
                    } else if (action.status === 'completed') {
                        var message = (action.message || 'Done.').toString();
                        showAlert(message.toLowerCase().indexOf('failed to start') !== -1 ? 'warning' : 'success', message);
                    }
                }
                setTimeout(function () { window.location.reload(); }, 1500);
            });
        }).catch(function (ignored) {
            // A transient poll error must not stop the loop — only a terminal
            // action state or an explicit navigation does.
        });
    }

    function startPolling() {
        stopPolling();
        pollTimer = setInterval(pollOnce, POLL_INTERVAL_MS);
        setTimeout(pollOnce, FIRST_POLL_DELAY_MS);
    }

    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    function refreshStatusOnce() {
        fetch(statusUrl, {
            method: 'GET',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function (res) {
            return res.json().catch(function () { return null; });
        }).then(function (payload) {
            if (!payload) return;
            if (payload.data && typeof payload.data === 'object' && (payload.data.vm || payload.data.can)) {
                payload = payload.data;
            }
            if (payload.vm || payload.can) applyStatus(payload);
        }).catch(function (ignored) {
            // Best effort only — the action it followed already succeeded.
        });
    }

    // ── Submit pipeline ──────────────────────────────────────────────────
    function canSubmitView(form) {
        var input = form.querySelector('[data-hv-confirm-input]');
        if (!input) return true;
        var view = form.closest ? form.closest('[data-hv-view]') : null;
        var expected = view ? (view.getAttribute('data-hv-confirm-expected') || '') : '';
        return input.value.trim() === expected;
    }

    function failAction(err) {
        var msg = (err && err.message) ? err.message : 'Network error.';
        showAlert('error', msg);
        state.busy = false;
        setProgress(false);
        applyStatus(state.last);
    }

    function handleActionResponse(act, data) {
        var ok = data.ok !== undefined ? !!data.ok : (data.success !== undefined ? !!data.success : true);
        if (!ok) {
            showAlert('error', data.message || data.error || 'Action failed.');
            state.busy = false;
            setProgress(false);
            applyStatus(state.last);
            return;
        }

        var message = data.message || data.msg || '';

        // Reset shows the new password once, in place, and never reloads.
        if (act === 'reset_password') {
            var newPw = data.password || data.new_password || (data.data && data.data.password) || '';
            var resultBlock = document.getElementById('hv-reset-result');
            var resultPw = document.getElementById('hv-reset-result-password');
            if (resultBlock && resultPw) {
                resultPw.textContent = newPw || '(no password returned)';
                resultBlock.classList.remove('d-none');
            }
            invalidateCredentialsView();
            state.busy = false;
            setProgress(false);
            applyStatus(state.last);
            showAlert('success', message || 'Password reset.');
            refreshStatusOnce();
            return;
        }

        // Async create accepted (202): close the form so the progress strip it
        // started is never covered, then poll to completion.
        if (data.started) {
            state.busy = false;
            closeDisclosure(false);
            setProgress(true);
            setProgressLabel(data.stage_label || data.stage || 'Creating the VM on the host');
            setProgressBar(4);
            setProgressMessage('');
            setBusyButtons();
            showAlert('success', message || 'VM creation started.');
            startPolling();
            return;
        }

        // Synchronous action succeeded — the host state changed; reload so the
        // server-rendered panel replaces this one.
        state.busy = false;
        closeDisclosure(false);
        showAlert('success', message || 'Done.');
        setTimeout(function () { window.location.reload(); }, RELOAD_DELAY_MS);
    }

    function submitForm(form, act) {
        if (state.busy) return; // a submit is already in flight

        var submitBtn = form.querySelector('[data-hv-submit]');
        var url = form.getAttribute('action') || moduleActionUrl;

        state.busy = true;
        setBusyButtons();
        setProgress(true);
        setProgressLabel('Working…');
        setProgressBar(4);
        setProgressMessage('');
        if (submitBtn) { submitBtn.disabled = true; setLoading(submitBtn, true); }

        fetch(url, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: new FormData(form)
        }).then(parseJsonResponse).then(function (data) {
            handleActionResponse(act, data);
        }).catch(function (err) {
            failAction(err);
        }).then(function () {
            if (submitBtn && document.contains(submitBtn)) {
                setLoading(submitBtn, false);
                submitBtn.disabled = !canSubmitView(form);
            }
        });
    }

    // Start / Stop are state-checked on the host and non-destructive — one
    // click, no confirmation view.
    function runDirectAction(act) {
        if (state.busy) return;
        state.busy = true;
        setBusyButtons();
        setProgress(true);
        setProgressLabel(act === 'start' ? 'Starting the VM…' : 'Stopping the VM…');
        setProgressBar(4);
        setProgressMessage('');

        var body = new FormData();
        body.append('module_slug', slug);
        body.append('action', act);

        fetch(moduleActionUrl, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: body
        }).then(parseJsonResponse).then(function (data) {
            handleActionResponse(null, act, data);
        }).catch(function (err) {
            failAction(err);
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

    // Any button carrying data-hv-generate-target fills that input with a
    // strong random password. The create view has no such button on purpose:
    // its password must match the template image, it must not be invented.
    function fillGeneratedPassword(gen) {
        var input = document.getElementById(gen.getAttribute('data-hv-generate-target') || '');
        // Guard against id collisions: a non-input with the same id would
        // swallow the value silently (that is why the reset password field
        // carries its own id rather than any container's).
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

    // ── Reset result copy ────────────────────────────────────────────────
    function copyResetPassword(btn) {
        var pwEl = document.getElementById('hv-reset-result-password');
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
        var view = disclosure ? disclosure.querySelector('[data-hv-view="credentials"]') : null;
        if (!view) return;
        var userEl = view.querySelector('[data-hv-creds-username]');
        var passEl = view.querySelector('[data-hv-creds-password]');
        var showBtn = view.querySelector('[data-hv-creds-show]');
        var showText = view.querySelector('[data-hv-creds-show-text]');
        var copyBtn = view.querySelector('[data-hv-creds-copy]');
        var copiedEl = view.querySelector('[data-hv-creds-feedback]');
        var errorBox = document.getElementById('hv-credentials-error-' + slug);
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
                        throw new Error((data && data.message) ? data.message : 'No Administrator credentials are stored for this VM.');
                    }
                    cached = { username: data.username || 'Administrator', password: data.password || '' };
                    return cached;
                });
            });
        }

        function setVisible(on, password) {
            visible = !!on;
            passEl.textContent = on ? (password || '') : masked;
            passEl.style.letterSpacing = on ? 'normal' : '0.15em';
            if (showText) showText.textContent = on ? 'Hide' : 'Show';
            if (showBtn) {
                showBtn.setAttribute('title', on ? 'Hide password' : 'Show password');
                showBtn.setAttribute('aria-label', on ? 'Hide password' : 'Show password');
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
                    if (!cred.password) { showError('No Administrator credentials are stored for this VM.'); return; }
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
                    if (!cred.password) { showError('No Administrator credentials are stored for this VM.'); return; }
                    copyText(cred.password, showCopied);
                }).catch(function (err) {
                    showError((err && err.message) ? err.message : 'Failed to load credentials.');
                });
            });
        }
    })();

    // ── Create view: "set a new password" makes the template password required ──
    (function initApplyPassword() {
        var box = document.getElementById('hv-apply-password-' + slug);
        var guestPassword = document.getElementById('hv-guest-password-' + slug);
        if (!box || !guestPassword) return;
        box.addEventListener('change', function () {
            if (box.checked) {
                guestPassword.setAttribute('required', 'required');
                try { guestPassword.focus(); } catch (e) {}
            } else {
                guestPassword.removeAttribute('required');
            }
        });
    })();

    // ── Retry probe: one-shot operator action (?refresh=1 drops the short
    //    cache). Separate from pollOnce so it never starts or stops the build
    //    polling loop — it only re-checks the host and the state pill. ────────
    (function initRetry() {
        var retryBtn = document.getElementById('hv-retry-status-' + slug);
        if (!retryBtn) return;
        retryBtn.addEventListener('click', function () {
            if (retryBtn.disabled) return;
            retryBtn.disabled = true;
            setLoading(retryBtn, true);
            fetch(statusUrl + '?refresh=1', {
                method: 'GET',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            }).then(function (res) {
                return res.json().catch(function () { return null; }).then(function (data) {
                    if (!res.ok) {
                        throw new Error((data && (data.message || data.error)) ? (data.message || data.error) : 'Status check failed.');
                    }
                    if (!data) throw new Error('Status check failed.');
                    var payload = data;
                    if (payload.data && typeof payload.data === 'object' && (payload.data.vm || payload.data.can)) {
                        payload = payload.data;
                    }
                    applyStatus(payload);
                    var probeErr = payload && payload.vm && payload.vm.probe_error ? String(payload.vm.probe_error).trim() : '';
                    if (probeErr !== '') showAlert('error', 'Host still unreachable: ' + probeErr);
                    else showAlert('success', 'Host reached — live VM state refreshed.');
                });
            }).catch(function (err) {
                showAlert('error', (err && err.message) ? err.message : 'Status check failed.');
            }).then(function () {
                retryBtn.disabled = false;
                setLoading(retryBtn, false);
            });
        });
    })();

    // ── Events ───────────────────────────────────────────────────────────
    panel.addEventListener('click', function (ev) {
        var target = ev.target;
        if (!target || !target.closest) return;

        var dismiss = target.closest('[data-hv-alert-dismiss]');
        if (dismiss) { hideAlert(); return; }

        var cancel = target.closest('[data-hv-cancel]');
        if (cancel) { closeDisclosure(true); return; }

        var generate = target.closest('[data-hv-generate-target]');
        if (generate) { fillGeneratedPassword(generate); return; }

        var copyReset = target.closest('#hv-reset-copy');
        if (copyReset) { copyResetPassword(copyReset); return; }

        var btn = target.closest('[data-hv-action]');
        // Triggers live in the action bar only. Scoping to it (instead of the
        // whole panel) keeps a click on any element that merely *contains*
        // action content from being mistaken for its trigger — that is how a
        // form carrying the attribute once made every field click close the
        // disclosure. `data-hv-form-action` is the form's own marker.
        if (!btn || !actionsRow || !actionsRow.contains(btn) || btn.disabled) return;
        var action = btn.getAttribute('data-hv-action');
        if (!action) return;

        if (action === 'start' || action === 'stop') {
            runDirectAction(action);
            return;
        }
        openDisclosure(action, btn);
    });

    // Typed confirmation: the confirm button arms only on an exact match.
    panel.addEventListener('input', function (ev) {
        var input = ev.target;
        if (!input || !input.hasAttribute || !input.hasAttribute('data-hv-confirm-input')) return;
        var view = input.closest ? input.closest('[data-hv-view]') : null;
        if (!view) return;
        var expected = view.getAttribute('data-hv-confirm-expected') || '';
        var submit = view.querySelector('[data-hv-submit]');
        if (submit) submit.disabled = input.value.trim() !== expected;
    });

    panel.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (!form || !form.hasAttribute || !form.hasAttribute('data-hv-form')) return;
        // Distinct from the trigger attribute on purpose: `data-hv-action`
        // identifies a button in the action bar, `data-hv-form-action` names
        // the action this form submits. Overloading one name for both made
        // clicks inside the form resolve to the form as its own trigger.
        var act = form.getAttribute('data-hv-form-action') || '';
        if (!form.checkValidity()) return; // let the browser explain

        ev.preventDefault();

        if (act === 'reset_password') {
            var pw = form.querySelector('[name="password"]');
            var pw2 = form.querySelector('[name="password_confirmation"]');
            if (pw && pw2 && pw.value !== pw2.value) {
                showAlert('error', 'Passwords do not match.');
                try { pw2.focus(); } catch (e) {}
                return;
            }
        }

        submitForm(form, act);
    });

    // Esc closes the open disclosure and returns focus to its trigger.
    document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Escape' && ev.key !== 'Esc') return;
        if (!state.open) return;
        ev.preventDefault();
        closeDisclosure(true);
    });

    // ── Boot ─────────────────────────────────────────────────────────────
    applyStatus(vmStatus);
    if (vmStatus && vmStatus.action && vmStatus.action.running) {
        startPolling();
    }
    window.addEventListener('beforeunload', stopPolling);
});
</script>
