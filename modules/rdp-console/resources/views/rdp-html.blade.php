{{-- HTML RDP view for a hosting account. Receives $hostingAccount, $rdpConfig, $effectiveHost, $effectivePort, $fullAddress --}}
@extends('adminlte::page')

@section('title', 'RDP — '.$hostingAccount->username.' — HTML')

@section('content_header')
    <x-ui.page-header title="Remote Desktop — HTML" :subtitle="$hostingAccount->username . ($hostingAccount->domain ? ' · ' . $hostingAccount->domain : '') . ' · #' . $hostingAccount->id . ' — Browser RDP via Guacamole'" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Products/Services', 'url' => route('admin.hosting.index')],
        ['label' => '#' . $hostingAccount->id, 'url' => route('admin.hosting.show', $hostingAccount)],
        ['label' => 'RDP HTML', 'active' => true],
    ]" />
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
        @include('rdp-console::partials._guacamole-canvas', [
            'hostingAccount' => $hostingAccount,
            'consoleTokenUrl' => route('admin.rdp-console.token', $hostingAccount),
            'consoleEnabled' => $hasHost && $gatewayConfigured,
            'consoleDisabledReason' => $consoleDisabledReason,
            'consoleHint' => 'Keyboard, mouse and clipboard are forwarded to the remote desktop.',
        ])
    </div>
</div>
@stop

@push('js')
<script>
// Connection-details copy buttons + native launch (unchanged shell behavior).
// The Guacamole canvas wiring lives in the shared partial.
(() => {
    'use strict';

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
