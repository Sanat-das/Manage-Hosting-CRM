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

        {{-- Domain Settings --}}
        <x-adminlte-card icon="bi bi-globe" title="Domain Settings">
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-input name="settings[domain_default_registrar]" label="Default Registrar"
                        value="{{ old('settings.domain_default_registrar', $settings['domain_default_registrar'] ?? '') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[domain_pricing_tier]" label="Pricing Tier"
                        value="{{ old('settings.domain_pricing_tier', $settings['domain_pricing_tier'] ?? 'standard') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[domain_renewal_reminder_days]" label="Renewal Reminder (days)"
                        value="{{ old('settings.domain_renewal_reminder_days', $settings['domain_renewal_reminder_days'] ?? '30') }}" />
                </div>
            </div>
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-input name="settings[domain_transfer_lock_days]" label="Transfer Lock (days)"
                        value="{{ old('settings.domain_transfer_lock_days', $settings['domain_transfer_lock_days'] ?? '60') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[domain_nameserver1]" label="Nameserver 1"
                        value="{{ old('settings.domain_nameserver1', $settings['domain_nameserver1'] ?? '') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[domain_nameserver2]" label="Nameserver 2"
                        value="{{ old('settings.domain_nameserver2', $settings['domain_nameserver2'] ?? '') }}" />
                </div>
            </div>
        </x-adminlte-card>

        {{-- Integration Settings --}}
        <x-adminlte-card icon="bi bi-plug" title="Integration Settings">
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-input name="settings[cpanel_host]" label="cPanel Host"
                        value="{{ old('settings.cpanel_host', $settings['cpanel_host'] ?? '') }}" />
                </div>
                <div class="col-md-2">
                    <x-adminlte-input name="settings[cpanel_port]" label="cPanel Port"
                        value="{{ old('settings.cpanel_port', $settings['cpanel_port'] ?? '2083') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[plesk_host]" label="Plesk Host"
                        value="{{ old('settings.plesk_host', $settings['plesk_host'] ?? '') }}" />
                </div>
                <div class="col-md-2">
                    <x-adminlte-input name="settings[plesk_port]" label="Plesk Port"
                        value="{{ old('settings.plesk_port', $settings['plesk_port'] ?? '8443') }}" />
                </div>
            </div>
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-input name="settings[resellerclub_api_id]" label="ResellerClub API ID"
                        value="{{ old('settings.resellerclub_api_id', $settings['resellerclub_api_id'] ?? '') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[resellerclub_username]" label="ResellerClub Username"
                        value="{{ old('settings.resellerclub_username', $settings['resellerclub_username'] ?? '') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[plesk_username]" label="Plesk Username"
                        value="{{ old('settings.plesk_username', $settings['plesk_username'] ?? '') }}" />
                </div>
            </div>
        </x-adminlte-card>

        {{-- Hosting Settings --}}
        <x-adminlte-card icon="bi bi-hdd" title="Hosting Settings">
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-input name="settings[hosting_default_panel]" label="Default Control Panel"
                        value="{{ old('settings.hosting_default_panel', $settings['hosting_default_panel'] ?? 'cpanel') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[hosting_default_server_group]" label="Default Server Group"
                        value="{{ old('settings.hosting_default_server_group', $settings['hosting_default_server_group'] ?? '') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[hosting_provision_retries]" label="Provision Retries"
                        value="{{ old('settings.hosting_provision_retries', $settings['hosting_provision_retries'] ?? '3') }}" />
                </div>
            </div>
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-input name="settings[hosting_suspend_after_days]" label="Suspend After (days)"
                        value="{{ old('settings.hosting_suspend_after_days', $settings['hosting_suspend_after_days'] ?? '7') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[hosting_terminate_after_days]" label="Terminate After (days)"
                        value="{{ old('settings.hosting_terminate_after_days', $settings['hosting_terminate_after_days'] ?? '30') }}" />
                </div>
            </div>
        </x-adminlte-card>

        {{-- IPAM Settings --}}
        <x-adminlte-card icon="bi bi-diagram-3" title="IPAM Settings">
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-input name="settings[ipam_default_ipv4_gateway]" label="Default IPv4 Gateway"
                        value="{{ old('settings.ipam_default_ipv4_gateway', $settings['ipam_default_ipv4_gateway'] ?? '') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[ipam_default_ipv6_prefix]" label="Default IPv6 Prefix"
                        value="{{ old('settings.ipam_default_ipv6_prefix', $settings['ipam_default_ipv6_prefix'] ?? '') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[ipam_reservation_hold_days]" label="Reservation Hold (days)"
                        value="{{ old('settings.ipam_reservation_hold_days', $settings['ipam_reservation_hold_days'] ?? '14') }}" />
                </div>
            </div>
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-input name="settings[ipam_scan_interval_minutes]" label="Scan Interval (minutes)"
                        value="{{ old('settings.ipam_scan_interval_minutes', $settings['ipam_scan_interval_minutes'] ?? '60') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[ipam_dns_reverse_zone]" label="DNS Reverse Zone"
                        value="{{ old('settings.ipam_dns_reverse_zone', $settings['ipam_dns_reverse_zone'] ?? '') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[ipam_unused_release_days]" label="Release Unused After (days)"
                        value="{{ old('settings.ipam_unused_release_days', $settings['ipam_unused_release_days'] ?? '90') }}" />
                </div>
            </div>
        </x-adminlte-card>

        {{-- Inventory Settings --}}
        <x-adminlte-card icon="bi bi-box-seam" title="Inventory Settings">
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-input name="settings[inventory_stock_unit]" label="Stock Unit"
                        value="{{ old('settings.inventory_stock_unit', $settings['inventory_stock_unit'] ?? 'units') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[inventory_low_stock_threshold]" label="Low Stock Threshold"
                        value="{{ old('settings.inventory_low_stock_threshold', $settings['inventory_low_stock_threshold'] ?? '5') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[inventory_restock_min_quantity]" label="Restock Min Quantity"
                        value="{{ old('settings.inventory_restock_min_quantity', $settings['inventory_restock_min_quantity'] ?? '10') }}" />
                </div>
            </div>
        </x-adminlte-card>

        {{-- Catalog Settings --}}
        <x-adminlte-card icon="bi bi-shop" title="Catalog Settings">
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-input name="settings[catalog_default_sort]" label="Default Sort Order"
                        value="{{ old('settings.catalog_default_sort', $settings['catalog_default_sort'] ?? 'sort_order') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[catalog_products_per_page]" label="Products Per Page"
                        value="{{ old('settings.catalog_products_per_page', $settings['catalog_products_per_page'] ?? '12') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[catalog_price_precision]" label="Price Precision"
                        value="{{ old('settings.catalog_price_precision', $settings['catalog_price_precision'] ?? '2') }}" />
                </div>
            </div>
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-input name="settings[catalog_currency_symbol]" label="Currency Symbol"
                        value="{{ old('settings.catalog_currency_symbol', $settings['catalog_currency_symbol'] ?? '₹') }}" />
                </div>
                <div class="col-md-8">
                    <x-adminlte-input name="settings[catalog_featured_product_ids]" label="Featured Product IDs"
                        value="{{ old('settings.catalog_featured_product_ids', $settings['catalog_featured_product_ids'] ?? '') }}" />
                </div>
            </div>
        </x-adminlte-card>

        {{-- Product Settings --}}
        <x-adminlte-card icon="bi bi-tags" title="Product Settings">
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-input name="settings[product_sku_prefix]" label="SKU Prefix"
                        value="{{ old('settings.product_sku_prefix', $settings['product_sku_prefix'] ?? '') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[product_default_billing_cycle]" label="Default Billing Cycle"
                        value="{{ old('settings.product_default_billing_cycle', $settings['product_default_billing_cycle'] ?? 'monthly') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[product_trial_days]" label="Trial Days"
                        value="{{ old('settings.product_trial_days', $settings['product_trial_days'] ?? '0') }}" />
                </div>
            </div>
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-input name="settings[product_license_key_prefix]" label="License Key Prefix"
                        value="{{ old('settings.product_license_key_prefix', $settings['product_license_key_prefix'] ?? '') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[product_reseller_markup_percent]" label="Reseller Markup (%)"
                        value="{{ old('settings.product_reseller_markup_percent', $settings['product_reseller_markup_percent'] ?? '0') }}" />
                </div>
            </div>
        </x-adminlte-card>

        {{-- Analytics Settings --}}
        <x-adminlte-card icon="bi bi-graph-up" title="Analytics Settings">
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-input name="settings[analytics_tracking_code]" label="Tracking Code"
                        value="{{ old('settings.analytics_tracking_code', $settings['analytics_tracking_code'] ?? '') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[analytics_retention_days]" label="Data Retention (days)"
                        value="{{ old('settings.analytics_retention_days', $settings['analytics_retention_days'] ?? '180') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[analytics_report_email]" label="Report Email"
                        value="{{ old('settings.analytics_report_email', $settings['analytics_report_email'] ?? '') }}" />
                </div>
            </div>
        </x-adminlte-card>

        {{-- Automation Settings --}}
        <x-adminlte-card icon="bi bi-robot" title="Automation Settings">
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-input name="settings[automation_default_workflow]" label="Default Workflow"
                        value="{{ old('settings.automation_default_workflow', $settings['automation_default_workflow'] ?? '') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[automation_auto_close_ticket_days]" label="Auto-close Tickets After (days)"
                        value="{{ old('settings.automation_auto_close_ticket_days', $settings['automation_auto_close_ticket_days'] ?? '5') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[automation_invoice_reminder_days]" label="Invoice Reminder (days)"
                        value="{{ old('settings.automation_invoice_reminder_days', $settings['automation_invoice_reminder_days'] ?? '3') }}" />
                </div>
            </div>
        </x-adminlte-card>

        {{-- Cron Settings --}}
        <x-adminlte-card icon="bi bi-clock-history" title="Cron Settings">
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-input name="settings[cron_domain_expiry_check]" label="Domain Expiry Check"
                        value="{{ old('settings.cron_domain_expiry_check', $settings['cron_domain_expiry_check'] ?? 'daily') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[cron_overdue_invoice_check]" label="Overdue Invoice Check"
                        value="{{ old('settings.cron_overdue_invoice_check', $settings['cron_overdue_invoice_check'] ?? 'daily') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[cron_backup_check]" label="Backup Check"
                        value="{{ old('settings.cron_backup_check', $settings['cron_backup_check'] ?? 'weekly') }}" />
                </div>
            </div>
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-input name="settings[cron_usage_sync]" label="Usage Sync"
                        value="{{ old('settings.cron_usage_sync', $settings['cron_usage_sync'] ?? 'hourly') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[cron_log_cleanup_days]" label="Log Cleanup After (days)"
                        value="{{ old('settings.cron_log_cleanup_days', $settings['cron_log_cleanup_days'] ?? '30') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[cron_notify_email]" label="Failure Notify Email"
                        value="{{ old('settings.cron_notify_email', $settings['cron_notify_email'] ?? '') }}" />
                </div>
            </div>
        </x-adminlte-card>

        {{-- Role Settings --}}
        <x-adminlte-card icon="bi bi-person-badge" title="Role Settings">
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-input name="settings[role_default_role]" label="Default Role"
                        value="{{ old('settings.role_default_role', $settings['role_default_role'] ?? 'client') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[role_guard]" label="Auth Guard"
                        value="{{ old('settings.role_guard', $settings['role_guard'] ?? 'web') }}" />
                </div>
            </div>
        </x-adminlte-card>

        {{-- User Settings --}}
        <x-adminlte-card icon="bi bi-people" title="User Settings">
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-input name="settings[user_default_timezone]" label="Default Timezone"
                        value="{{ old('settings.user_default_timezone', $settings['user_default_timezone'] ?? 'Asia/Kolkata') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[user_session_timeout_minutes]" label="Session Timeout (minutes)"
                        value="{{ old('settings.user_session_timeout_minutes', $settings['user_session_timeout_minutes'] ?? '120') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="settings[user_max_login_attempts]" label="Max Login Attempts"
                        value="{{ old('settings.user_max_login_attempts', $settings['user_max_login_attempts'] ?? '5') }}" />
                </div>
            </div>
        </x-adminlte-card>

        <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-check-lg me-1"></i> Save All Settings</button>
    </form>
@stop
