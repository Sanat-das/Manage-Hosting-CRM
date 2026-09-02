{{-- HTML RDP view for a hosting account. Receives $hostingAccount, $rdpConfig, $effectiveHost, $effectivePort, $fullAddress --}}
@extends('adminlte::page')

@section('title', 'RDP — '.$hostingAccount->username.' — HTML')

@section('content_header')
    <div class="row">
        <div class="col-sm-8">
            <h1 class="m-0">Remote Desktop — HTML</h1>
            <p class="text-muted mb-0 small">
                <i class="bi bi-display me-1"></i>
                {{ $hostingAccount->username }}
                @if($hostingAccount->domain) <span class="mx-1">·</span> {{ $hostingAccount->domain }} @endif
                <span class="mx-1">·</span> #{{ $hostingAccount->id }}
            </p>
        </div>
        <div class="col-sm-4">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.hosting.index') }}">Products/Services</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.hosting.show', $hostingAccount) }}">#{{ $hostingAccount->id }}</a></li>
                <li class="breadcrumb-item active">RDP HTML</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
@php
    $hasHost = $effectiveHost !== null && trim((string)$effectiveHost) !== '';
    $isFromIp = $rdpConfig?->host === null && $hasHost;
@endphp

<div class="row g-3">
    <div class="col-12 col-xl-5">
        <x-adminlte-card title="Connection" icon="bi bi-display">
            @if(!$hasHost)
                <x-adminlte-alert theme="danger">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    No RDP host available. Configure an RDP host or assign an IP to this account, then reload.
                </x-adminlte-alert>
            @else
                <table class="table table-sm table-borderless mb-3">
                    <tbody>
                        <tr>
                            <th class="text-muted w-25">Full address</th>
                            <td>
                                <code id="rdp-full-address">{{ $fullAddress }}</code>
                                <button type="button" class="btn btn-xs btn-outline-secondary ms-2" data-copy="#rdp-full-address" title="Copy">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                                @if($isFromIp)<span class="text-muted small ms-1">(from assigned IP)</span>@endif
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">Host</th>
                            <td><code>{{ $effectiveHost }}</code></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Port</th>
                            <td>{{ $effectivePort }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">Username</th>
                            <td>
                                @if($rdpConfig?->username)
                                    <code id="rdp-username">{{ $rdpConfig->username }}</code>
                                    <button type="button" class="btn btn-xs btn-outline-secondary ms-2" data-copy="#rdp-username" title="Copy"><i class="bi bi-clipboard"></i></button>
                                @else <span class="text-muted">—</span> @endif
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">Domain</th>
                            <td>{{ $rdpConfig?->domain ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>

                <div class="text-muted small mb-3">
                    @if($rdpConfig?->password_encrypted)
                        Password is stored encrypted. The downloaded .rdp now embeds <code>password 51:b:</code> (DPAPI, server-user bound) — it may auto-login on the server's Windows account; on other machines you'll still be prompted, so copy from the hosting page.
                    @else
                        Password is stored encrypted and never shown. Use the username above — the client will prompt for password.
                    @endif
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ route('admin.rdp-console.download', $hostingAccount) }}" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-download me-1"></i> Download .rdp
                    </a>
                    <a href="{{ route('admin.hosting.show', $hostingAccount) }}" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-pencil me-1"></i> Edit RDP settings
                    </a>
                    <button type="button" class="btn btn-primary btn-sm" id="rdp-launch-native" @if(!$hasHost) disabled @endif>
                        <i class="bi bi-box-arrow-up-right me-1"></i> Open with native RDP
                    </button>
                </div>

                <hr class="my-3">

                <div class="small">
                    <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1 text-muted"></i> How to connect</div>
                    <ol class="ps-3 mb-0 text-muted">
                        <li>Click <em>Download .rdp</em> or <em>Open with native RDP</em> (Windows will launch mstsc).</li>
                        <li>Enter the password when prompted. Domain is <code>{{ $rdpConfig?->domain ?? '—' }}</code> if shown.</li>
                        <li>For browser-based access, click <em>Connect</em> on the HTML console — credentials travel only inside a short-lived encrypted token to the local guacamole sidecar.</li>
                    </ol>
                </div>
            @endif
        </x-adminlte-card>

        <x-adminlte-card title="RDP file preview" icon="bi bi-filetype-rdp" class="collapsed-card">
            <pre class="mb-0 small bg-light p-3 rounded border" style="white-space: pre-wrap; font-family: ui-monospace, SFMono-Regular, monospace;">@if($hasHost)full address:s:{{ $fullAddress }}
@if($rdpConfig?->username)username:s:{{ $rdpConfig->username }}@endif
@if($rdpConfig?->domain)domain:s:{{ $rdpConfig->domain }}@endif
@if($rdpConfig?->password_encrypted)password 51:b:01000000… (encrypted, server-user bound)@endif
screen mode id:i:2
session bpp:i:32
autoreconnection enabled:i:1
compression:i:1
keyboardhook:i:2
audiomode:i:0
displayconnectionbar:i:1
prompt for credentials:i:{{ $rdpConfig?->password_encrypted ? '0' : '1' }}
authentication level:i:2
enablecredsspsupport:i:1
@endif</pre>
        </x-adminlte-card>
    </div>

    <div class="col-12 col-xl-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center py-2">
                <span class="small fw-bold"><i class="bi bi-window me-1"></i> HTML console (browser RDP)</span>
                <span class="badge text-bg-secondary" id="guac-status">@if(!$hasHost)unavailable@else idle @endif</span>
            </div>
            <div class="card-body p-2">
                <div class="d-flex flex-wrap gap-2 px-1 pb-2">
                    <button type="button" class="btn btn-primary btn-sm" id="guac-connect" @if(!$hasHost) disabled @endif>
                        <i class="bi bi-play-fill me-1"></i> Connect
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm" id="guac-disconnect" disabled>
                        <i class="bi bi-x-circle me-1"></i> Close session
                    </button>
                    <span class="text-muted small align-self-center">
                        Keyboard, mouse and clipboard are forwarded to the remote desktop.
                    </span>
                </div>

                {{-- Error banner: shown on tunnel/client errors and incomplete configuration --}}
                <div class="alert alert-danger alert-dismissible py-2 small mx-1 d-none" id="guac-error-container" role="alert">
                    <i class="bi bi-exclamation-octagon me-1"></i>
                    <span id="guac-error-message"></span>
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
    </div>
</div>
@stop

@push('js')
<script src="{{ route('admin.rdp-console.clientAsset', $hostingAccount) }}"></script>
<script>
(() => {
    'use strict';

    const hasHost = @json((bool) $hasHost);
    const tokenUrl = @json(route('admin.rdp-console.token', $hostingAccount));

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
        connectBtn.disabled = !hasHost;
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
                throw new Error(res.status === 404
                    ? (payload.error || 'RDP connection details are incomplete for this account.')
                    : 'Could not obtain a console token (HTTP ' + res.status + ').');
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
        connectBtn.disabled = !hasHost;
    });
    document.getElementById('guac-error-dismiss').addEventListener('click', clearError);

    if (!hasHost) {
        showOverlay('No host configured');
        reconnectBtn.disabled = true;
    } else if (typeof window.Guacamole === 'undefined') {
        setStatus('unavailable', 'danger');
        showError('Guacamole client library failed to load.');
    } else {
        setStatus('ready', 'info');
    }

    // --- Connection-details copy buttons + native launch (unchanged shell behavior) ---
    document.querySelectorAll('[data-copy]').forEach(function(btn){
        btn.addEventListener('click', function(){
            var sel = this.getAttribute('data-copy');
            var el = document.querySelector(sel);
            if(!el) return;
            var text = el.textContent.trim();
            if(navigator.clipboard && navigator.clipboard.writeText){
                navigator.clipboard.writeText(text).then(function(){
                    var orig = btn.innerHTML;
                    btn.innerHTML = '<i class="bi bi-check"></i>';
                    setTimeout(function(){ btn.innerHTML = orig; }, 1200);
                });
            } else {
                var ta = document.createElement('textarea');
                ta.value = text; document.body.appendChild(ta); ta.select();
                try{ document.execCommand('copy'); }catch(e){}
                document.body.removeChild(ta);
            }
        });
    });
    var launch = document.getElementById('rdp-launch-native');
    if(launch){
        launch.addEventListener('click', function(){
            var addr = document.getElementById('rdp-full-address');
            if(!addr) return;
            // rdp:// URI is handled by some Windows handlers; fallback is download
            var full = addr.textContent.trim();
            // Try rdp scheme — if not handled, user still has Download button
            window.location.href = 'rdp://full%20address=s:' + encodeURIComponent(full);
        });
    }
})();
</script>
@endpush
