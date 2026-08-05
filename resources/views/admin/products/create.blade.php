@extends('adminlte::page')

@section('title', 'Add Product')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Add Product</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.products.index') }}">Products</a></li>
                <li class="breadcrumb-item active" aria-current="page">Add Product</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-adminlte-alert>
    @endif

    <x-adminlte.partials.form-card
        icon="bi bi-box"
        title="New Product"
        :action="route('admin.products.store')"
        submit-label="Save Product"
        :cancel-url="route('admin.products.index')"
    >
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="name" label="Name" placeholder="e.g. Business cPanel Hosting"
                                  value="{{ old('name') }}" required />
            </div>
            <div class="col-md-6">
                <x-adminlte-select name="type" label="Type" required>
                    @foreach ($types as $value => $label)
                        <option value="{{ $value }}" @selected(old('type', 'shared_hosting') === $value)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="product_group_id" label="Product group">
                    <option value="">— None —</option>
                    @foreach ($groups as $group)
                        <option value="{{ $group->id }}" @selected((string) old('product_group_id') === (string) $group->id)>{{ $group->name }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-select name="billing_cycle" label="Default billing cycle" required>
                    @foreach ($defaultCycles as $value => $label)
                        <option value="{{ $value }}" @selected(old('billing_cycle', 'monthly') === $value)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="provisioning_module" label="Provisioning module" required>
                    @foreach ($provisioningModules as $value => $label)
                        <option value="{{ $value }}" @selected(old('provisioning_module', 'none') === $value)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-select name="server_group_id" label="Server group">
                    <option value="">— None —</option>
                    @foreach ($serverGroups as $serverGroup)
                        <option value="{{ $serverGroup->id }}" @selected((string) old('server_group_id') === (string) $serverGroup->id)>{{ $serverGroup->name }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="welcome_email_template_id" label="Welcome email template">
                    <option value="">— None —</option>
                    @foreach ($emailTemplates as $template)
                        <option value="{{ $template->id }}" @selected((string) old('welcome_email_template_id') === (string) $template->id)>{{ $template->name }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-select name="status" label="Status">
                    <option value="active" @selected(old('status', 'active') === 'active')>Active</option>
                    <option value="inactive" @selected(old('status') === 'inactive')>Inactive</option>
                </x-adminlte-select>
            </div>
        </div>

        <x-adminlte-input name="sort_order" type="number" min="0" label="Sort order"
                          value="{{ old('sort_order', 0) }}" />

        <x-adminlte-textarea name="description" label="Description" rows="2"
                             placeholder="Optional product description">{{ old('description') }}</x-adminlte-textarea>

        <div class="row">
            @foreach ([
                'require_domain' => 'Requires a domain',
                'show_in_order' => 'Visible in order form',
                'show_in_affiliate' => 'Visible to affiliates',
                'only_admin' => 'Admin-only ordering',
            ] as $field => $label)
                <div class="col-md-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="{{ $field }}" value="1"
                               id="{{ $field }}" @checked(old($field, true))>
                        <label class="form-check-label" for="{{ $field }}">{{ $label }}</label>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Quotas --}}
        <x-adminlte-card title="Resource quotas" icon="bi bi-speedometer2" class="mt-3" body-class="p-3">
            <div class="row">
                @foreach ([
                    'quota_disk' => 'Disk (MB)',
                    'quota_bandwidth' => 'Bandwidth (MB)',
                    'quota_email' => 'Email accounts',
                    'quota_database' => 'Databases',
                    'quota_cpu_cores' => 'CPU cores',
                    'quota_cpu_speed' => 'CPU speed (MHz)',
                    'quota_ram' => 'RAM (MB)',
                    'quota_ips' => 'IP addresses',
                    'quota_ftp_accounts' => 'FTP accounts',
                    'quota_subdomains' => 'Subdomains',
                ] as $field => $label)
                    <div class="col-md-3 col-lg-2">
                        <x-adminlte-input name="{{ $field }}" type="number" min="0" label="{{ $label }}"
                                          value="{{ old($field, 0) }}" />
                    </div>
                @endforeach
            </div>
            <p class="text-muted small mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>0 means unlimited / not tracked.</p>
        </x-adminlte-card>

        {{-- GST --}}
        <x-adminlte-card title="GST settings" icon="bi bi-percent" class="mt-3" body-class="p-3">
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="gst_enabled" value="1"
                       id="gst_enabled" @checked(old('gst_enabled', false))>
                <label class="form-check-label" for="gst_enabled">Apply per-product GST rates</label>
            </div>
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-select name="gst_type" label="GST type">
                        @foreach ($gstTypes as $value => $label)
                            <option value="{{ $value }}" @selected(old('gst_type', 'standard') === $value)>{{ $label }}</option>
                        @endforeach
                    </x-adminlte-select>
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="gst_rate" type="number" step="0.01" min="0" max="100"
                                      label="GST rate (%)" value="{{ old('gst_rate') }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="cgst_rate" type="number" step="0.01" min="0" max="100"
                                      label="CGST rate (%)" value="{{ old('cgst_rate') }}" />
                </div>
                <div class="col-md-6">
                    <x-adminlte-input name="sgst_rate" type="number" step="0.01" min="0" max="100"
                                      label="SGST rate (%)" value="{{ old('sgst_rate') }}" />
                </div>
                <div class="col-md-6">
                    <x-adminlte-input name="igst_rate" type="number" step="0.01" min="0" max="100"
                                      label="IGST rate (%)" value="{{ old('igst_rate') }}" />
                </div>
            </div>
        </x-adminlte-card>

        {{-- Multi-cycle pricing ladder --}}
        <x-adminlte-card title="Pricing" icon="bi bi-currency-rupee" class="mt-3" body-class="p-3">
            <p class="text-muted small">
                <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                The default cycle price/setup fee above is mirrored onto the product row; the full ladder is saved here.
                Cycles without a price are skipped (except Free).
            </p>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Cycle</th>
                            <th class="text-end" style="min-width: 110px;">Price</th>
                            <th class="text-end" style="min-width: 110px;">Setup fee</th>
                            <th class="text-end" style="min-width: 110px;">Promo price</th>
                            <th style="min-width: 130px;">Promo start</th>
                            <th style="min-width: 130px;">Promo end</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($cycles as $cycle => $cycleLabel)
                            <tr>
                                <td><strong>{{ $cycleLabel }}</strong></td>
                                <td>
                                    <input type="number" name="pricing[{{ $cycle }}][price]" step="0.01" min="0"
                                           class="form-control form-control-sm text-end"
                                           value="{{ old("pricing.$cycle.price") }}" placeholder="0.00">
                                </td>
                                <td>
                                    <input type="number" name="pricing[{{ $cycle }}][setup_fee]" step="0.01" min="0"
                                           class="form-control form-control-sm text-end"
                                           value="{{ old("pricing.$cycle.setup_fee") }}" placeholder="0.00">
                                </td>
                                <td>
                                    <input type="number" name="pricing[{{ $cycle }}][promo_price]" step="0.01" min="0"
                                           class="form-control form-control-sm text-end"
                                           value="{{ old("pricing.$cycle.promo_price") }}" placeholder="0.00">
                                </td>
                                <td>
                                    <input type="date" name="pricing[{{ $cycle }}][promo_start]"
                                           class="form-control form-control-sm"
                                           value="{{ old("pricing.$cycle.promo_start") }}">
                                </td>
                                <td>
                                    <input type="date" name="pricing[{{ $cycle }}][promo_end]"
                                           class="form-control form-control-sm"
                                           value="{{ old("pricing.$cycle.promo_end") }}">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-adminlte-card>
    </x-adminlte.partials.form-card>
@stop
