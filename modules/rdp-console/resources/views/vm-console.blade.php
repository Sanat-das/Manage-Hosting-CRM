{{-- Hyper-V VMConnect console page — the module's second connection mode.

     The page itself carries no target data from the request: the controller
     resolves the Hyper-V host, the VM GUID and the HOST's administrator
     credentials strictly server-side (PanelAccount + Server rows) and only
     non-secret facts plus an operator-safe error reach this template. The
     shared Guacamole canvas is wired to the VMConnect token endpoint, whose
     response shape is identical to the guest-RDP token endpoint. --}}
@extends('adminlte::page')

@section('title', 'VM Console — '.$hostingAccount->username.' — Hyper-V')

@section('content_header')
    <x-ui.page-header title="Hyper-V VM Console" :subtitle="$hostingAccount->username . ($hostingAccount->domain ? ' · ' . $hostingAccount->domain : '') . ' · #' . $hostingAccount->id . ' — VMConnect via Guacamole'" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Products/Services', 'url' => route('admin.hosting.index')],
        ['label' => '#' . $hostingAccount->id, 'url' => route('admin.hosting.show', $hostingAccount)],
        ['label' => 'VM Console', 'active' => true],
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
                        <th class="text-muted w-25">Hyper-V host</th>
                        <td><code>{{ $consoleHost ?? '—' }}</code></td>
                    </tr>
                    <tr>
                        <th class="text-muted">Port</th>
                        <td>{{ $consolePort }} (vmrdp)</td>
                    </tr>
                    <tr>
                        <th class="text-muted">Security</th>
                        <td><code>vmconnect</code></td>
                    </tr>
                    <tr>
                        <th class="text-muted">VM GUID</th>
                        <td><code>{{ $consoleVmGuid ?? '—' }}</code></td>
                    </tr>
                </tbody>
            </table>

            <div class="text-muted small">
                Connects to the <strong>Hyper-V host</strong> with the host administrator credentials and the VM GUID —
                not to the guest's own RDP service. It therefore works without guest networking and at boot / pre-OS
                screens. The credentials live in the server record and travel only inside the short-lived encrypted
                token; they are never rendered into this page.
            </div>

            <hr class="my-3">

            <div class="small text-muted">
                <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1 text-muted"></i> Operational prerequisite</div>
                TCP {{ $consolePort }} must be reachable from the guacd host to the Hyper-V host — allow it from the
                guacd host's address only. A live session has not been verified in this environment.
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
            'consoleTokenUrl' => route('admin.rdp-console.vmConsoleToken', $hostingAccount),
            'consoleEnabled' => $consoleEnabled,
            'consoleDisabledReason' => $consoleDisabledReason,
            'consoleHint' => 'Keyboard, mouse and clipboard are forwarded to the VM — including boot and pre-OS screens.',
        ])
    </div>
</div>
@stop
