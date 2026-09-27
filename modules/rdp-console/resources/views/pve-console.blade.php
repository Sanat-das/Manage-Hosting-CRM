{{-- Proxmox VE VNC console page — the module's third connection mode.

     The page itself carries no target data from the request: the controller
     resolves the node and VMID strictly server-side (PanelAccount + Server
     rows) in facts-only mode — no vncproxy ticket is minted to render this
     page — and only non-secret facts plus an operator-safe error reach this
     template. The VNC port is assigned when the console connects (the token
     endpoint mints the one-shot ticket then). The shared Guacamole canvas
     is wired to the PVE token endpoint, whose response shape is identical to
     the guest-RDP and VMConnect token endpoints. --}}
@extends('adminlte::page')

@section('title', 'Proxmox Console — '.$hostingAccount->username.' — VNC')

@section('content_header')
    <x-ui.page-header title="Proxmox VE Console" :subtitle="$hostingAccount->username . ($hostingAccount->domain ? ' · ' . $hostingAccount->domain : '') . ' · #' . $hostingAccount->id . ' — VNC via Guacamole'" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Products/Services', 'url' => route('admin.hosting.index')],
        ['label' => '#' . $hostingAccount->id, 'url' => route('admin.hosting.show', $hostingAccount)],
        ['label' => 'Proxmox Console', 'active' => true],
    ]" />
@stop

@section('content')
<div class="row g-3">
    <div class="col-12 col-xl-5">
        <x-adminlte-card title="How this console connects" icon="bi bi-hdd-stack">
            @if (! $consoleEnabled)
                <x-adminlte-alert theme="danger">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    {{ $consoleDisabledReason }}
                </x-adminlte-alert>
            @endif

            <table class="table table-sm table-borderless mb-3">
                <tbody>
                    <tr>
                        <th class="text-muted w-25">PVE node</th>
                        <td><code>{{ $consoleNode ?? '—' }}</code></td>
                    </tr>
                    <tr>
                        <th class="text-muted">VMID</th>
                        <td><code>{{ $consoleVmid ?? '—' }}</code></td>
                    </tr>
                    @if ($consoleVncPort !== null)
                    <tr>
                        <th class="text-muted">VNC port</th>
                        <td>{{ $consoleVncPort }}</td>
                    </tr>
                    @endif
                </tbody>
            </table>

            <div class="text-muted small">
                Connects through the <strong>Proxmox VE API</strong>: a one-shot VNC ticket is minted server-side and
                the console sidecar opens the node's VNC websocket with it, bridging the session to the browser.
                The ticket travels only inside the short-lived encrypted token; it is never rendered into this page.
            </div>

            <hr class="my-3">

            <div class="small text-muted">
                <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1 text-muted"></i> Operational prerequisite</div>
                This console requires the deployed Guacamole sidecar with Proxmox VNC relay support — without it the
                session cannot be established.
            </div>

            <div class="mt-3">
                <a href="{{ route('admin.hosting.show', $hostingAccount) }}" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> Back to service
                </a>
            </div>
        </x-adminlte-card>
    </div>

    <div class="col-12 col-xl-7">
        @include('rdp-console::partials._guacamole-canvas', [
            'hostingAccount' => $hostingAccount,
            'consoleTokenUrl' => route('admin.rdp-console.pveConsoleToken', $hostingAccount),
            'consoleEnabled' => $consoleEnabled,
            'consoleDisabledReason' => $consoleDisabledReason,
            'consoleHint' => 'Keyboard, mouse and clipboard are forwarded to the VM — including boot and pre-OS screens.',
        ])
    </div>
</div>
@stop
