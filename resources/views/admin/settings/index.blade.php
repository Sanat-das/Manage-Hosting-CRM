@extends('adminlte::page')

@section('title', 'Settings')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Settings</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active">Settings</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif

    <form method="POST" action="{{ route('admin.settings.update') }}">
        @csrf

        {{-- Portal Settings --}}
        <x-adminlte-card icon="bi bi-person" title="Portal Settings">
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-select name="settings[registration_enabled]" label="Client Self-Registration">
                        <option value="yes" @selected(($settings['registration_enabled'] ?? 'yes') === 'yes')>Enabled</option>
                        <option value="no" @selected(($settings['registration_enabled'] ?? 'yes') === 'no')>Disabled</option>
                    </x-adminlte-select>
                </div>
            </div>
        </x-adminlte-card>

        {{-- General Settings --}}
        <x-adminlte-card icon="bi bi-gear" title="General Settings">
            <div class="row">
                <div class="col-md-6">
                    <x-adminlte-input name="settings[company_name]" label="Company Name"
                        value="{{ old('settings.company_name', $settings['company_name'] ?? '') }}" />
                </div>
                <div class="col-md-6">
                    <x-adminlte-input name="settings[company_email]" label="Company Email" type="email"
                        value="{{ old('settings.company_email', $settings['company_email'] ?? '') }}" />
                </div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <x-adminlte-input name="settings[company_phone]" label="Company Phone"
                        value="{{ old('settings.company_phone', $settings['company_phone'] ?? '') }}" />
                </div>
                <div class="col-md-6">
                    <x-adminlte-input name="settings[company_address]" label="Company Address"
                        value="{{ old('settings.company_address', $settings['company_address'] ?? '') }}" />
                </div>
            </div>
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-input name="settings[default_currency]" label="Default Currency"
                        value="{{ old('settings.default_currency', $settings['default_currency'] ?? 'INR') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[default_tax_rate]" label="Default Tax Rate (%)"
                        value="{{ old('settings.default_tax_rate', $settings['default_tax_rate'] ?? '18') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[timezone]" label="Timezone"
                        value="{{ old('settings.timezone', $settings['timezone'] ?? 'Asia/Kolkata') }}" />
                </div>
            </div>
        </x-adminlte-card>

        {{-- Billing Settings --}}
        <x-adminlte-card icon="bi bi-receipt" title="Billing Settings">
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-input name="settings[invoice_prefix]" label="Invoice Prefix"
                        value="{{ old('settings.invoice_prefix', $settings['invoice_prefix'] ?? 'INV-') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[quote_prefix]" label="Quote Prefix"
                        value="{{ old('settings.quote_prefix', $settings['quote_prefix'] ?? 'QT-') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[ticket_prefix]" label="Ticket Prefix"
                        value="{{ old('settings.ticket_prefix', $settings['ticket_prefix'] ?? 'T-') }}" />
                </div>
            </div>
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-input name="settings[auto_generate_invoice]" label="Auto-generate invoices" placeholder="yes/no"
                        value="{{ old('settings.auto_generate_invoice', $settings['auto_generate_invoice'] ?? 'yes') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[due_days]" label="Days until due"
                        value="{{ old('settings.due_days', $settings['due_days'] ?? '7') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[gst_enabled]" label="GST Enabled" placeholder="yes/no"
                        value="{{ old('settings.gst_enabled', $settings['gst_enabled'] ?? 'yes') }}" />
                </div>
            </div>
        </x-adminlte-card>

        {{-- Email Settings --}}
        <x-adminlte-card icon="bi bi-envelope" title="Email Settings">
            <div class="row">
                <div class="col-md-6">
                    <x-adminlte-input name="settings[smtp_host]" label="SMTP Host"
                        value="{{ old('settings.smtp_host', $settings['smtp_host'] ?? '') }}" />
                </div>
                <div class="col-md-3">
                    <x-adminlte-input name="settings[smtp_port]" label="SMTP Port"
                        value="{{ old('settings.smtp_port', $settings['smtp_port'] ?? '587') }}" />
                </div>
                <div class="col-md-3">
                    <x-adminlte-select name="settings[smtp_encryption]" label="Encryption">
                        <option value="tls" @selected(($settings['smtp_encryption'] ?? 'tls') === 'tls')>TLS</option>
                        <option value="ssl" @selected(($settings['smtp_encryption'] ?? '') === 'ssl')>SSL</option>
                    </x-adminlte-select>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <x-adminlte-input name="settings[smtp_username]" label="SMTP Username"
                        value="{{ old('settings.smtp_username', $settings['smtp_username'] ?? '') }}" />
                </div>
                <div class="col-md-6">
                    <x-adminlte-input name="settings[smtp_password]" label="SMTP Password" type="password"
                        value="" placeholder="Leave blank to keep current" />
                </div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <x-adminlte-input name="settings[mail_from_address]" label="From Address"
                        value="{{ old('settings.mail_from_address', $settings['mail_from_address'] ?? '') }}" />
                </div>
                <div class="col-md-6">
                    <x-adminlte-input name="settings[mail_from_name]" label="From Name"
                        value="{{ old('settings.mail_from_name', $settings['mail_from_name'] ?? '') }}" />
                </div>
            </div>
        </x-adminlte-card>

        {{-- Security Settings --}}
        <x-adminlte-card icon="bi bi-shield-lock" title="Security Settings">
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-input name="settings[session_timeout]" label="Session Timeout (minutes)"
                        value="{{ old('settings.session_timeout', $settings['session_timeout'] ?? '120') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[max_login_attempts]" label="Max Login Attempts"
                        value="{{ old('settings.max_login_attempts', $settings['max_login_attempts'] ?? '5') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[lockout_duration]" label="Lockout Duration (minutes)"
                        value="{{ old('settings.lockout_duration', $settings['lockout_duration'] ?? '15') }}" />
                </div>
            </div>
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-input name="settings[force_2fa]" label="Force 2FA" placeholder="yes/no"
                        value="{{ old('settings.force_2fa', $settings['force_2fa'] ?? 'no') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[password_min_length]" label="Min Password Length"
                        value="{{ old('settings.password_min_length', $settings['password_min_length'] ?? '8') }}" />
                </div>
            </div>
        </x-adminlte-card>

        {{-- Notification Settings --}}
        <x-adminlte-card icon="bi bi-bell" title="Notification Settings">
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-input name="settings[notify_overdue_invoices]" label="Overdue invoice notifications" placeholder="yes/no"
                        value="{{ old('settings.notify_overdue_invoices', $settings['notify_overdue_invoices'] ?? 'yes') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[notify_domain_expiry]" label="Domain expiry notifications" placeholder="yes/no"
                        value="{{ old('settings.notify_domain_expiry', $settings['notify_domain_expiry'] ?? 'yes') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[notify_new_tickets]" label="New ticket notifications" placeholder="yes/no"
                        value="{{ old('settings.notify_new_tickets', $settings['notify_new_tickets'] ?? 'yes') }}" />
                </div>
            </div>
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-input name="settings[domain_expiry_warning_days]" label="Domain expiry warning (days)"
                        value="{{ old('settings.domain_expiry_warning_days', $settings['domain_expiry_warning_days'] ?? '30') }}" />
                </div>
            </div>
        </x-adminlte-card>

        <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-check-lg me-1"></i> Save All Settings</button>
    </form>
@stop
