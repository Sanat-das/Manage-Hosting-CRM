{{-- Shared Guacamole canvas: the browser RDP surface and its client wiring.

     Included by the guest-RDP HTML page (rdp-console::rdp-html) and the
     Hyper-V VMConnect page (rdp-console::vm-console) so both modes render
     and drive the exact same canvas — only the token endpoint differs, and
     that endpoint is chosen server-side per page (never from request input).

     Expects:
       $hostingAccount          — for the vendored client asset route
       $consoleTokenUrl         — JSON token endpoint returning { ws_url, token }
       $consoleEnabled          — false renders the disabled overlay + reason
       $consoleDisabledReason   — operator-safe reason, may be ''
       $consoleHint             — small copy under the buttons
--}}
@php
    $consoleEnabled = (bool) ($consoleEnabled ?? false);
    $consoleDisabledReason = (string) ($consoleDisabledReason ?? '');
    $consoleHint = (string) ($consoleHint ?? '');
@endphp

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center py-2">
        <span class="small fw-bold"><i class="bi bi-window me-1"></i> HTML console (browser RDP)</span>
        <span class="badge text-bg-secondary" id="guac-status">{{ $consoleEnabled ? 'idle' : 'unavailable' }}</span>
    </div>
    <div class="card-body p-2">
        <div class="d-flex flex-wrap gap-2 px-1 pb-2">
            <button type="button" class="btn btn-primary btn-sm" id="guac-connect" @disabled(! $consoleEnabled)>
                <i class="bi bi-play-fill me-1"></i> Connect
            </button>
            <button type="button" class="btn btn-outline-danger btn-sm" id="guac-disconnect" disabled>
                <i class="bi bi-x-circle me-1"></i> Close session
            </button>
            @if ($consoleHint !== '')
                <span class="text-muted small align-self-center">{{ $consoleHint }}</span>
            @endif
        </div>

        {{-- Error banner: tunnel/client errors and configuration gaps. When the
             console is disabled the reason is server-rendered (no d-none) so
             it is visible even before the JS runs; the client keeps it in
             sync on connect. --}}
        <div class="alert alert-danger alert-dismissible py-2 small mx-1 {{ $consoleEnabled ? 'd-none' : '' }}" id="guac-error-container" role="alert">
            <i class="bi bi-exclamation-octagon me-1"></i>
            <span id="guac-error-message">{{ $consoleEnabled ? '' : $consoleDisabledReason }}</span>
            <button type="button" class="btn-close" id="guac-error-dismiss" aria-label="Dismiss"></button>
        </div>

        <div class="border rounded overflow-hidden position-relative d-flex align-items-center justify-content-center"
             id="guac-display"
             style="height: 70vh; background:#0b1220;">
            {{-- Guacamole display canvas mounts here; overlay covers it when disconnected --}}
            <div id="guac-overlay" class="position-absolute top-0 start-0 w-100 h-100 d-flex flex-column align-items-center justify-content-center text-center p-4 d-none"
                 style="background: rgba(11, 18, 32, 0.92);">
                <i class="bi bi-display text-white-50 mb-2" style="font-size: 2rem; opacity:.6;"></i>
                <div class="text-white small fw-semibold" id="guac-overlay-title">Not connected</div>
                <div class="text-white-50 small mt-1 mb-3" style="max-width: 420px;">The remote desktop renders here over WebSocket through the guacamole sidecar.</div>
                <button type="button" class="btn btn-outline-light btn-sm" id="guac-reconnect">
                    <i class="bi bi-arrow-clockwise me-1"></i> Reconnect
                </button>
            </div>
        </div>
    </div>
</div>

@push('js')
<script src="{{ route('admin.rdp-console.clientAsset', $hostingAccount) }}"></script>
<script>
(() => {
    'use strict';

    const consoleEnabled = @json($consoleEnabled);
    const disabledReason = @json($consoleDisabledReason);
    const tokenUrl = @json($consoleTokenUrl);

    const statusBadge = document.getElementById('guac-status');
    const connectBtn = document.getElementById('guac-connect');
    const disconnectBtn = document.getElementById('guac-disconnect');
    const displayHost = document.getElementById('guac-display');
    const overlay = document.getElementById('guac-overlay');
    const overlayTitle = document.getElementById('guac-overlay-title');
    const reconnectBtn = document.getElementById('guac-reconnect');
    const errorContainer = document.getElementById('guac-error-container');
    const errorMessage = document.getElementById('guac-error-message');

    let client = null;
    let keyboard = null;
    let mouse = null;
    let busy = false;

    function setStatus(text, theme) {
        statusBadge.textContent = text;
        statusBadge.className = 'badge text-bg-' + (theme || 'secondary');
    }

    function showError(message) {
        errorMessage.textContent = message;
        errorContainer.classList.remove('d-none');
    }

    function clearError() {
        errorContainer.classList.add('d-none');
        errorMessage.textContent = '';
    }

    function showOverlay(title) {
        overlayTitle.textContent = title || 'Session ended';
        overlay.classList.remove('d-none');
    }

    function hideOverlay() {
        overlay.classList.add('d-none');
    }

    // --- Fit the remote display into the panel, preserving aspect ratio ---
    function fitToPanel() {
        if (!client) return;
        const display = client.getDisplay();
        const width = display.getWidth();
        const height = display.getHeight();
        if (!width || !height || !displayHost.clientWidth || !displayHost.clientHeight) return;
        display.scale(Math.max(Math.min(
            displayHost.clientWidth / width,
            displayHost.clientHeight / height,
        ), 0.01));
    }

    window.addEventListener('resize', () => { if (client) fitToPanel(); });

    // --- Clipboard forwarding (both directions, text/plain only) ---
    async function sendClipboardText(text) {
        if (!client || !text) return;
        const writer = new Guacamole.StringWriter(client.createClipboardStream('text/plain'));
        writer.sendText(text);
        writer.sendEnd();
    }

    function onLocalCopyOrCut() {
        if (!client) return;
        const selection = window.getSelection();
        sendClipboardText(selection ? selection.toString() : '');
    }

    async function onLocalPaste(event) {
        if (!client) return;
        let text = '';
        if (event && event.clipboardData) {
            text = event.clipboardData.getData('text/plain');
        } else if (navigator.clipboard && navigator.clipboard.readText) {
            try { text = await navigator.clipboard.readText(); } catch (e) { /* permission denied */ }
        }
        await sendClipboardText(text);
    }

    function wireRemoteClipboard(c) {
        c.onclipboard = (stream, mimetype) => {
            if (mimetype !== 'text/plain') return;
            const reader = new Guacamole.StringReader(stream);
            reader.ontext = (text) => {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).catch(() => {});
                }
            };
        };
        document.addEventListener('copy', onLocalCopyOrCut);
        document.addEventListener('cut', onLocalCopyOrCut);
        document.addEventListener('paste', onLocalPaste);
    }

    function unwireLocalClipboard() {
        document.removeEventListener('copy', onLocalCopyOrCut);
        document.removeEventListener('cut', onLocalCopyOrCut);
        document.removeEventListener('paste', onLocalPaste);
    }

    function suppressContextMenu(event) {
        event.preventDefault();
    }

    function wireInput(c) {
        keyboard = new Guacamole.Keyboard(document);
        keyboard.onkeydown = (key) => c.sendKey(key, 1);
        keyboard.onkeyup = (key) => c.sendKey(key, 0);

        mouse = new Guacamole.Mouse(c.getDisplay().getElement());
        mouse.onmousedown = mouse.onmouseup = mouse.onmousemove = (state) => c.sendMouse(state.state);

        c.getDisplay().getElement().addEventListener('contextmenu', suppressContextMenu);
    }

    function teardown() {
        unwireLocalClipboard();

        if (keyboard) {
            keyboard.onkeydown = null;
            keyboard.onkeyup = null;
            try { keyboard.reset(); } catch (e) { /* already dead */ }
            keyboard = null;
        }
        mouse = null;

        if (client) {
            try { client.disconnect(); } catch (e) { /* socket may be gone */ }
            client = null;
        }

        displayHost.querySelectorAll('canvas, div:not(#guac-overlay)').forEach((el) => el.remove());
        busy = false;
    }

    function fail(message) {
        teardown();
        setStatus('error', 'danger');
        disconnectBtn.disabled = true;
        connectBtn.disabled = !consoleEnabled;
        showError(message);
        showOverlay('Disconnected');
    }

    function tunnelStatusText(status) {
        if (status && status.message) return String(status.message);
        return 'WebSocket connection failed (code ' + ((status && status.code) || '?') + '). Verify the guacamole sidecar is running.';
    }

    async function connect() {
        if (busy || client) return;
        if (typeof window.Guacamole === 'undefined') {
            showError('Guacamole client library failed to load. Check that the vendored asset route is reachable.');
            return;
        }

        busy = true;
        clearError();
        hideOverlay();
        setStatus('requesting token…', 'warning');
        connectBtn.disabled = true;

        let payload;
        try {
            const res = await fetch(tokenUrl, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            });
            payload = await res.json().catch(() => ({}));
            if (!res.ok) {
                // The server's `error` is an operator-safe message (e.g. the
                // gateway-not-configured 503); use it whenever present.
                throw new Error(payload.error
                    || (res.status === 404
                        ? 'Connection details are incomplete for this account.'
                        : 'Could not obtain a console token (HTTP ' + res.status + ').'));
            }
        } catch (e) {
            fail(e.message || 'Network error while requesting the console token.');
            return;
        }

        // Token travels only in the websocket query string — never the page,
        // never a URL the browser navigates to.
        const separator = payload.ws_url.indexOf('?') >= 0 ? '&' : '?';
        const tunnel = new Guacamole.WebSocketTunnel(payload.ws_url + separator + 'token=' + encodeURIComponent(payload.token));
        client = new Guacamole.Client(tunnel);

        displayHost.appendChild(client.getDisplay().getElement());

        client.getDisplay().onresize = fitToPanel;
        wireInput(client);
        wireRemoteClipboard(client);

        tunnel.onstatechange = (state) => {
            if (state === Guacamole.Tunnel.State.OPEN) {
                setStatus('connected', 'success');
            } else if (state === Guacamole.Tunnel.State.CONNECTING) {
                setStatus('connecting…', 'warning');
            } else if (state === Guacamole.Tunnel.State.CLOSED) {
                if (client) {
                    fail('Session ended.');
                }
            }
        };
        tunnel.onerror = (status) => fail(tunnelStatusText(status));
        client.onerror = (error) => fail(error.message || 'Unexpected console error.');

        setStatus('connecting…', 'warning');
        disconnectBtn.disabled = false;

        try {
            client.connect();
        } catch (e) {
            fail(e.message || 'Unable to start the console session.');
        }
    }

    connectBtn.addEventListener('click', () => { connect(); });
    reconnectBtn.addEventListener('click', () => {
        teardown();
        hideOverlay();
        connect();
    });
    disconnectBtn.addEventListener('click', () => {
        teardown();
        hideOverlay();
        clearError();
        setStatus('closed', 'secondary');
        disconnectBtn.disabled = true;
        connectBtn.disabled = !consoleEnabled;
    });
    document.getElementById('guac-error-dismiss').addEventListener('click', clearError);

    if (!consoleEnabled) {
        showOverlay('Not available');
        if (disabledReason) showError(disabledReason);
        reconnectBtn.disabled = true;
    } else if (typeof window.Guacamole === 'undefined') {
        setStatus('unavailable', 'danger');
        showError('Guacamole client library failed to load.');
    } else {
        setStatus('ready', 'info');
    }
})();
</script>
@endpush
