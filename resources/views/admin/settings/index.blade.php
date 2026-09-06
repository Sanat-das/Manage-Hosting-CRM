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
        <x-adminlte-alert theme="success" dismissible id="settings-success-alert">{{ session('success') }}</x-adminlte-alert>
    @endif
    {{-- Failure flash (no-JS test-email fallback posts a normal form and redirects here). --}}
    @if (session('error'))
        <x-adminlte-alert theme="danger" dismissible id="settings-error-alert">{{ session('error') }}</x-adminlte-alert>
    @endif
    @if (session('success'))
        <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1080">
            <div id="settings-save-toast" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body" id="settings-save-toast-body">{{ session('success') }}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        </div>
    @endif

    @php
        // Fallback for direct view rendering without controller (e.g., tests that mock).
        $sections = $sections ?? \App\Support\AppSettings::sections();
        $activeTab = $activeTab ?? (request()->query('tab') && in_array(request()->query('tab'), array_column($sections, 'id'), true) ? request()->query('tab') : ($sections[0]['id'] ?? 'portal'));
        $lastUpdated = $lastUpdated ?? [];

        // Full IANA timezone identifier list grouped by region prefix for every
        // Timezone select on this page (General `timezone`, User
        // `user_default_timezone`). Zones without a region prefix (UTC, CET, …)
        // are collected under "Other", kept last.
        $timezonesGrouped = [];
        foreach (\DateTimeZone::listIdentifiers(\DateTimeZone::ALL) as $tzIdentifier) {
            $regionParts = explode('/', $tzIdentifier, 2);
            $timezonesGrouped[$regionParts[0]][$tzIdentifier] = $tzIdentifier;
        }
        uksort($timezonesGrouped, function ($a, $b) {
            if ($a === 'Other') {
                return 1;
            }
            if ($b === 'Other') {
                return -1;
            }

            return strcmp($a, $b);
        });

        // When validation fails, activate the tab that owns the first error so the
        // user lands on the pane holding the problem (mirrors products/edit pattern).
        if ($errors->any()) {
            $keyToSectionForActive = \App\Support\AppSettings::keyToSection();
            foreach ($errors->keys() as $errorKey) {
                $rawKeyForActive = preg_replace('/^settings\./', '', (string) $errorKey);
                $rawKeyForActive = str_replace(['[', ']'], ['', ''], $rawKeyForActive);
                if (isset($keyToSectionForActive[$rawKeyForActive]) && in_array($keyToSectionForActive[$rawKeyForActive], array_column($sections, 'id'), true)) {
                    $activeTab = $keyToSectionForActive[$rawKeyForActive];
                    break;
                }
            }
        }
    @endphp

    <form method="POST" action="{{ route('admin.settings.update') }}" id="settings-form" enctype="multipart/form-data">
        @csrf
        <input type="hidden" name="active_tab" id="active-tab-input" value="{{ $activeTab }}">

        @php
            $sectionsByGroup = [];
            foreach ($sections as $s) {
                $sectionsByGroup[$s['group']][] = $s;
            }
        @endphp
        <div class="row g-0 align-items-start" id="settings-layout">
            {{-- Sidebar: search, grouped nav, save --}}
            <div class="col-lg-3 col-xl-2" id="settings-sidebar-col">
                <div class="settings-sidebar pe-3 pb-4">
                    {{-- Search --}}
                    <div class="mb-3" id="settings-search-wrap">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text" aria-hidden="true"><i class="bi bi-search"></i></span>
                            <input id="settings-search" type="search" class="form-control" placeholder="Search settings…"
                                aria-label="Search settings" autocomplete="off">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="settings-search-clear" aria-label="Clear search">Clear</button>
                        </div>
                        <div class="d-flex align-items-center gap-2 mt-1">
                            <span id="search-count" class="badge text-bg-secondary d-none" aria-live="polite"></span>
                            <span id="search-no-matches" class="text-muted small d-none">No matches</span>
                        </div>
                    </div>

                    {{-- Grouped nav pills —  id kept as settings-tabs-nav so existing JS references work --}}
                    <ul class="nav flex-column nav-pills mb-3 settings-nav" role="tablist" id="settings-tabs-nav">
                        @foreach ($sectionsByGroup as $groupName => $groupSections)
                            <li class="nav-item settings-nav-group" role="none">
                                <span class="settings-nav-group-label">{{ $groupName }}</span>
                            </li>
                            @foreach ($groupSections as $section)
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link w-100 text-start @if ($activeTab === $section['id']) active @endif"
                                            id="tab-{{ $section['id'] }}"
                                            data-bs-toggle="tab"
                                            data-bs-target="#pane-{{ $section['id'] }}"
                                            type="button"
                                            role="tab"
                                            tabindex="{{ $activeTab === $section['id'] ? 0 : -1 }}"
                                            aria-controls="pane-{{ $section['id'] }}"
                                            aria-selected="{{ $activeTab === $section['id'] ? 'true' : 'false' }}">
                                        <i class="{{ $section['icon'] }} me-2" aria-hidden="true"></i>{{ $section['label'] }}
                                    </button>
                                </li>
                            @endforeach
                        @endforeach
                    </ul>

                    {{-- Save --}}
                    <hr class="my-2">
                    <button type="submit" form="settings-form" id="save-all-btn" name="save_all" value="1"
                            class="btn btn-primary w-100 mb-2">
                        <i class="bi bi-check-lg me-1"></i> Save All Settings
                    </button>
                    <small id="save-all-concurrency-note" class="text-muted d-block text-center" style="font-size:0.75rem;">
                        <i class="bi bi-people me-1"></i> Changes save immediately — coordinate with team when editing at the same time
                    </small>
                </div>
            </div>

            {{-- Content column --}}
            <div class="col-lg-9 col-xl-10 ps-lg-4">
                {{-- Validation error summary --}}
                @if($errors->any())
                    @php
                        $keyToSection = \App\Support\AppSettings::keyToSection();
                    @endphp
                    <div class="alert alert-danger" id="settings-error-summary" role="alert" aria-labelledby="settings-error-summary-heading">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                            <strong id="settings-error-summary-heading">{{ $errors->count() }} {{ Str::plural('error', $errors->count()) }} found — fix the highlighted fields</strong>
                        </div>
                        <ul class="mb-0 ps-3">
                            @foreach($errors->messages() as $field => $messages)
                                @php
                                    $rawKey = preg_replace('/^settings\./', '', (string) $field);
                                    $rawKey = str_replace(['[', ']'], ['', ''], $rawKey);
                                    $tabForKey = $keyToSection[$rawKey] ?? $activeTab;
                                    $labelForKey = str_replace('_', ' ', $rawKey);
                                @endphp
                                <li>
                                    <a href="#pane-{{ $tabForKey }}" class="alert-link error-summary-link" data-tab="{{ $tabForKey }}" data-field="{{ $field }}" data-raw-key="{{ $rawKey }}">
                                        {{ $labelForKey }} ({{ $tabForKey }})
                                    </a>
                                    — {{ $messages[0] }}
                                </li>
                            @endforeach
                        </ul>
                        <small class="d-block mt-2 text-muted">Click a link to jump to its tab and focus the field.</small>
                    </div>
                @endif

                <div class="tab-content">
            {{-- Portal --}}
            <div class="tab-pane fade @if ($activeTab === 'portal') show active @endif" id="pane-portal" role="tabpanel" aria-labelledby="tab-portal">
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
                @php $lu = $lastUpdated['portal'] ?? $lastUpdated['all'] ?? null; @endphp
                <small class="text-muted d-block mb-2 last-updated" data-section="portal">@if($lu)Last updated: {{ \Illuminate\Support\Carbon::parse($lu->created_at)->format('Y-m-d H:i:s') }} — <span title="{{ $lu->description }}">{{ \Illuminate\Support\Str::limit($lu->description, 120) }}</span>@else Last updated: never @endif</small>
            </div>

            {{-- Branding --}}
            <div class="tab-pane fade @if ($activeTab === 'branding') show active @endif" id="pane-branding" role="tabpanel" aria-labelledby="tab-branding">
                <x-adminlte-card icon="bi bi-palette" title="Branding — HostVexa">
                    @php
                        $brandingData = $branding ?? \App\Support\Branding::all();
                        $brandingLogoUrl = $brandingData['logo_url'] ?? asset('img/hostvexa-logo.svg');
                        $brandingFaviconUrl = $brandingData['favicon_url'] ?? asset('img/hostvexa-favicon.svg');
                        $brandingPrimary = old('settings.branding_primary_color', $settings['branding_primary_color'] ?? '#0EA5E9');
                        $brandingAccent = old('settings.branding_accent_color', $settings['branding_accent_color'] ?? '#6366F1');
                        // Normalize to hex with hash for color input
                        if (! preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $brandingPrimary)) { $brandingPrimary = '#0EA5E9'; }
                        if (! preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $brandingAccent)) { $brandingAccent = '#6366F1'; }
                    @endphp
                    <div class="row">
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[branding_app_name]" label="App Name *"
                                value="{{ old('settings.branding_app_name', $settings['branding_app_name'] ?? 'HostVexa') }}"
                                maxlength="50" required />
                            <small class="form-text text-muted">Shown in sidebar, title, emails</small>
                            @error('settings.branding_app_name')
                                <span class="invalid-feedback d-block" role="alert">{{ $message }}</span>
                            @enderror
                        </div>
                        <div class="col-md-8">
                            <x-adminlte-input name="settings[branding_tagline]" label="Tagline"
                                value="{{ old('settings.branding_tagline', $settings['branding_tagline'] ?? 'Hosting Management Platform') }}"
                                maxlength="100" />
                            <small class="form-text text-muted">Only in browser title/meta, e.g. Hosting Management Platform</small>
                            @error('settings.branding_tagline')
                                <span class="invalid-feedback d-block" role="alert">{{ $message }}</span>
                            @enderror
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-3">
                            <label for="branding_primary_color" class="form-label">Primary Color</label>
                            <div class="input-group">
                                <input type="color" id="branding_primary_color_picker" value="{{ $brandingPrimary }}" class="form-control form-control-color" style="max-width:3rem;padding:0.2rem;" title="Pick primary color">
                                <input type="text" name="settings[branding_primary_color]" id="branding_primary_color"
                                    value="{{ old('settings.branding_primary_color', $settings['branding_primary_color'] ?? '#0EA5E9') }}"
                                    placeholder="#0EA5E9" maxlength="7" pattern="^#[0-9A-Fa-f]{6}$"
                                    class="form-control @error('settings.branding_primary_color') is-invalid @enderror">
                            </div>
                            @error('settings.branding_primary_color')
                                <span class="invalid-feedback d-block" role="alert">{{ $message }}</span>
                            @enderror
                            <small class="form-text text-muted">Hex #RRGGBB — suggested <code>#0EA5E9</code></small>
                        </div>
                        <div class="col-md-3">
                            <label for="branding_accent_color" class="form-label">Accent Color</label>
                            <div class="input-group">
                                <input type="color" id="branding_accent_color_picker" value="{{ $brandingAccent }}" class="form-control form-control-color" style="max-width:3rem;padding:0.2rem;" title="Pick accent color">
                                <input type="text" name="settings[branding_accent_color]" id="branding_accent_color"
                                    value="{{ old('settings.branding_accent_color', $settings['branding_accent_color'] ?? '#6366F1') }}"
                                    placeholder="#6366F1" maxlength="7" pattern="^#[0-9A-Fa-f]{6}$"
                                    class="form-control @error('settings.branding_accent_color') is-invalid @enderror">
                            </div>
                            @error('settings.branding_accent_color')
                                <span class="invalid-feedback d-block" role="alert">{{ $message }}</span>
                            @enderror
                            <small class="form-text text-muted">Hex #RRGGBB — suggested <code>#6366F1</code></small>
                        </div>
                        <div class="col-md-3">
                            @php $sidebarThemeCurrent = old('settings.branding_sidebar_theme', $settings['branding_sidebar_theme'] ?? ''); @endphp
                            <x-adminlte-select name="settings[branding_sidebar_theme]" label="Sidebar Theme">
                                <option value="" @selected($sidebarThemeCurrent === '')>Use default</option>
                                <option value="dark" @selected($sidebarThemeCurrent === 'dark')>Dark — navy #0F172A</option>
                                <option value="light" @selected($sidebarThemeCurrent === 'light')>Light</option>
                            </x-adminlte-select>
                            <small class="form-text text-muted">Overrides General sidebar theme for brand.</small>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <div class="w-100">
                                <label class="form-label">Preview</label>
                                <div id="branding-preview-card" class="rounded d-flex align-items-center gap-2 px-3 py-2" style="background:#0F172A;color:#fff;min-height:44px;border:1px solid #1e293b;">
                                    <img id="branding-preview-logo" src="{{ $brandingLogoUrl }}" alt="Logo preview" style="height:24px;width:auto;object-fit:contain;background:rgba(255,255,255,0.08);border-radius:4px;padding:2px;">
                                    <span id="branding-preview-name" class="fw-semibold small">{{ old('settings.branding_app_name', $settings['branding_app_name'] ?? 'HostVexa') }}</span>
                                    <span class="ms-auto d-inline-flex gap-1">
                                        <span id="branding-preview-primary" class="rounded-circle d-inline-block" style="width:18px;height:18px;background:{{ $brandingPrimary }};border:2px solid rgba(255,255,255,0.5);" title="Primary"></span>
                                        <span id="branding-preview-accent" class="rounded-circle d-inline-block" style="width:18px;height:18px;background:{{ $brandingAccent }};border:2px solid rgba(255,255,255,0.5);" title="Accent"></span>
                                    </span>
                                </div>
                                <small class="form-text text-muted">How logo + colors look in the header.</small>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-md-6">
                            <label class="form-label">Logo</label>
                            <div class="d-flex align-items-center gap-3 mb-2 p-2 border rounded" style="background:var(--bs-tertiary-bg, #f8f9fa);">
                                <img src="{{ $brandingLogoUrl }}" alt="Current logo" style="height:36px;width:auto;max-width:140px;object-fit:contain;background:#fff;border-radius:4px;padding:4px;border:1px solid #dee2e6;">
                                <div class="small text-muted">
                                    Current: <code class="small">{{ $settings['branding_logo_path'] ?? '' ?: 'img/hostvexa-logo.svg (default)' }}</code><br>
                                    <span>Upload SVG, PNG, JPG or WEBP — max 2 MB.</span>
                                </div>
                                <img src="{{ $brandingFaviconUrl }}" alt="Favicon preview" style="height:16px;width:16px;object-fit:contain;" class="ms-auto d-none d-md-block" title="Favicon">
                            </div>
                            <input type="file" name="branding_logo" id="branding_logo"
                                accept="image/svg+xml,image/png,image/jpeg,image/webp,.ico"
                                class="form-control @error('branding_logo') is-invalid @enderror @error('settings.branding_logo') is-invalid @enderror">
                            @error('branding_logo')
                                <span class="invalid-feedback d-block" role="alert">{{ $message }}</span>
                            @enderror
                            @error('settings.branding_logo')
                                <span class="invalid-feedback d-block" role="alert">{{ $message }}</span>
                            @enderror
                            @error('settings.branding_logo_path')
                                <span class="invalid-feedback d-block" role="alert">{{ $message }}</span>
                            @enderror
                            <small class="form-text text-muted">Choose a new logo to replace the current. Leave empty to keep existing.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Favicon</label>
                            <div class="d-flex align-items-center gap-3 mb-2 p-2 border rounded" style="background:var(--bs-tertiary-bg, #f8f9fa);">
                                <img src="{{ $brandingFaviconUrl }}" alt="Current favicon" style="height:32px;width:32px;object-fit:contain;background:#fff;border-radius:4px;padding:4px;border:1px solid #dee2e6;">
                                <div class="small text-muted">
                                    Current: <code class="small">{{ $settings['branding_favicon_path'] ?? '' ?: 'img/hostvexa-favicon.svg (default)' }}</code><br>
                                    <span>SVG, PNG, JPG, WEBP or ICO — max 1 MB.</span>
                                </div>
                            </div>
                            <input type="file" name="branding_favicon" id="branding_favicon"
                                accept="image/svg+xml,image/png,image/jpeg,image/webp,.ico"
                                class="form-control @error('branding_favicon') is-invalid @enderror @error('settings.branding_favicon') is-invalid @enderror">
                            @error('branding_favicon')
                                <span class="invalid-feedback d-block" role="alert">{{ $message }}</span>
                            @enderror
                            @error('settings.branding_favicon')
                                <span class="invalid-feedback d-block" role="alert">{{ $message }}</span>
                            @enderror
                            @error('settings.branding_favicon_path')
                                <span class="invalid-feedback d-block" role="alert">{{ $message }}</span>
                            @enderror
                            <small class="form-text text-muted">Browser tab icon. Leave empty to keep existing.</small>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-8">
                            <label for="branding_footer_text" class="form-label">Footer Text</label>
                            <input type="text" name="settings[branding_footer_text]" id="branding_footer_text"
                                value="{{ old('settings.branding_footer_text', $settings['branding_footer_text'] ?? '© {year} HostVexa. All rights reserved.') }}"
                                maxlength="255" placeholder="© {year} HostVexa. All rights reserved."
                                class="form-control @error('settings.branding_footer_text') is-invalid @enderror">
                            @error('settings.branding_footer_text')
                                <span class="invalid-feedback d-block" role="alert">{{ $message }}</span>
                            @enderror
                            <small class="form-text text-muted">Use <code>{year}</code> for dynamic year. Shown in footer bar.</small>
                        </div>
                    </div>
                    <script>
                        (function(){
                            var pPicker = document.getElementById('branding_primary_color_picker');
                            var pInput = document.getElementById('branding_primary_color');
                            var aPicker = document.getElementById('branding_accent_color_picker');
                            var aInput = document.getElementById('branding_accent_color');
                            var pPreview = document.getElementById('branding-preview-primary');
                            var aPreview = document.getElementById('branding-preview-accent');
                            var nameInput = document.querySelector('[name="settings[branding_app_name]"]');
                            var namePreview = document.getElementById('branding-preview-name');
                            if(pPicker && pInput){
                                pPicker.addEventListener('input', function(){ pInput.value = pPicker.value; if(pPreview) pPreview.style.background = pPicker.value; });
                                pInput.addEventListener('input', function(){ if(/^#[0-9A-Fa-f]{6}$/.test(pInput.value)){ pPicker.value = pInput.value; if(pPreview) pPreview.style.background = pInput.value; } });
                            }
                            if(aPicker && aInput){
                                aPicker.addEventListener('input', function(){ aInput.value = aPicker.value; if(aPreview) aPreview.style.background = aPicker.value; });
                                aInput.addEventListener('input', function(){ if(/^#[0-9A-Fa-f]{6}$/.test(aInput.value)){ aPicker.value = aInput.value; if(aPreview) aPreview.style.background = aInput.value; } });
                            }
                            if(nameInput && namePreview){
                                nameInput.addEventListener('input', function(){ namePreview.textContent = nameInput.value || 'HostVexa'; });
                            }
                            var logoFile = document.getElementById('branding_logo');
                            var logoPreview = document.getElementById('branding-preview-logo');
                            if(logoFile && logoPreview){
                                logoFile.addEventListener('change', function(){
                                    if(logoFile.files && logoFile.files[0]){
                                        var url = URL.createObjectURL(logoFile.files[0]);
                                        logoPreview.src = url;
                                    }
                                });
                            }
                        })();
                    </script>
                </x-adminlte-card>
                @php $lu = $lastUpdated['branding'] ?? $lastUpdated['all'] ?? null; @endphp
                <small class="text-muted d-block mb-2 last-updated" data-section="branding">@if($lu)Last updated: {{ \Illuminate\Support\Carbon::parse($lu->created_at)->format('Y-m-d H:i:s') }} — <span title="{{ $lu->description }}">{{ \Illuminate\Support\Str::limit($lu->description, 120) }}</span>@else Last updated: never @endif</small>
            </div>

            {{-- General --}}
            <div class="tab-pane fade @if ($activeTab === 'general') show active @endif" id="pane-general" role="tabpanel" aria-labelledby="tab-general">
                <x-adminlte-card icon="bi bi-building" title="Company Information">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="company_name" class="form-label fw-semibold">Company Name</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-building" aria-hidden="true"></i></span>
                                <input type="text" name="settings[company_name]" id="company_name"
                                    value="{{ old('settings.company_name', $settings['company_name'] ?? '') }}"
                                    placeholder="HostVexa Pvt Ltd"
                                    maxlength="255"
                                    class="form-control @error('settings.company_name') is-invalid @enderror">
                            </div>
                            @error('settings.company_name')
                                <span class="invalid-feedback d-block" role="alert">{{ $message }}</span>
                            @enderror
                            <small class="form-text text-muted">Legal name as it appears on invoices</small>
                        </div>
                        <div class="col-md-6">
                            <label for="company_email" class="form-label fw-semibold">Company Email</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope" aria-hidden="true"></i></span>
                                <input type="email" name="settings[company_email]" id="company_email"
                                    value="{{ old('settings.company_email', $settings['company_email'] ?? '') }}"
                                    placeholder="billing@example.com"
                                    maxlength="255"
                                    class="form-control @error('settings.company_email') is-invalid @enderror">
                            </div>
                            @error('settings.company_email')
                                <span class="invalid-feedback d-block" role="alert">{{ $message }}</span>
                            @enderror
                            <small class="form-text text-muted">Support / billing contact — used in <code>@{{company_email}}</code></small>
                        </div>
                    </div>
                    @php
                        $companyPhoneRaw = old('settings.company_phone', $settings['company_phone'] ?? '');
                        $cpSelectedDial = '+91';
                        $cpNumberPart = '';
                        if (preg_match('/^\s*(\+\d{1,4})\s*(.*)\s*$/', trim((string) $companyPhoneRaw), $m)) {
                            $cpSelectedDial = $m[1];
                            $cpNumberPart = trim($m[2]);
                        } elseif (trim((string) $companyPhoneRaw) !== '') {
                            $cpNumberPart = trim((string) $companyPhoneRaw);
                        }
                        $cpOldCode = old('settings.company_phone_code', old('settings.phone_code'));
                        $cpOldNum = old('settings.company_phone_number', old('settings.phone_number'));
                        if ($cpOldCode) $cpSelectedDial = $cpOldCode;
                        if ($cpOldNum !== null) $cpNumberPart = $cpOldNum;
                        if (old('company_phone_code')) $cpSelectedDial = old('company_phone_code');
                        if (old('company_phone_number') !== null) $cpNumberPart = old('company_phone_number');
                        $cpCountries = [
                            ['code' => 'IN', 'name' => 'India', 'native' => 'भारत', 'dial' => '+91', 'flag' => '🇮🇳'],
                            ['code' => 'US', 'name' => 'United States', 'native' => '', 'dial' => '+1', 'flag' => '🇺🇸'],
                            ['code' => 'GB', 'name' => 'United Kingdom', 'native' => '', 'dial' => '+44', 'flag' => '🇬🇧'],
                            ['code' => 'AU', 'name' => 'Australia', 'native' => '', 'dial' => '+61', 'flag' => '🇦🇺'],
                            ['code' => 'CA', 'name' => 'Canada', 'native' => '', 'dial' => '+1', 'flag' => '🇨🇦'],
                            ['code' => 'AE', 'name' => 'United Arab Emirates', 'native' => 'الإمارات', 'dial' => '+971', 'flag' => '🇦🇪'],
                            ['code' => 'SG', 'name' => 'Singapore', 'native' => '', 'dial' => '+65', 'flag' => '🇸🇬'],
                            ['code' => 'DE', 'name' => 'Germany', 'native' => 'Deutschland', 'dial' => '+49', 'flag' => '🇩🇪'],
                            ['code' => 'FR', 'name' => 'France', 'native' => '', 'dial' => '+33', 'flag' => '🇫🇷'],
                            ['code' => 'BD', 'name' => 'Bangladesh', 'native' => 'বাংলাদেশ', 'dial' => '+880', 'flag' => '🇧🇩'],
                            ['code' => 'NP', 'name' => 'Nepal', 'native' => 'नेपाल', 'dial' => '+977', 'flag' => '🇳🇵'],
                            ['code' => 'PK', 'name' => 'Pakistan', 'native' => 'پاکستان', 'dial' => '+92', 'flag' => '🇵🇰'],
                            ['code' => 'LK', 'name' => 'Sri Lanka', 'native' => 'ශ්‍රී ලංකාව', 'dial' => '+94', 'flag' => '🇱🇰'],
                            ['code' => 'SA', 'name' => 'Saudi Arabia', 'native' => 'المملكة العربية السعودية', 'dial' => '+966', 'flag' => '🇸🇦'],
                            ['code' => 'MY', 'name' => 'Malaysia', 'native' => '', 'dial' => '+60', 'flag' => '🇲🇾'],
                            ['code' => 'CN', 'name' => 'China', 'native' => '中国', 'dial' => '+86', 'flag' => '🇨🇳'],
                            ['code' => 'JP', 'name' => 'Japan', 'native' => '日本', 'dial' => '+81', 'flag' => '🇯🇵'],
                        ];
                        $cpDials = array_column($cpCountries, 'dial');
                        if (!in_array($cpSelectedDial, $cpDials, true)) {
                            $cpCountries[] = ['code' => 'OT', 'name' => 'Other', 'native' => '', 'dial' => $cpSelectedDial, 'flag' => '🏳️'];
                        }
                    @endphp
                    {{-- Company Phone — customer phone-input parity (code select + number) --}}
                    <div class="mb-3" id="company-phone-field">
                        <label class="form-label fw-semibold">Mobile / Phone <span class="text-muted fw-normal">(Company)</span></label>
                        <div class="input-group" style="flex-wrap: nowrap;">
                            <select id="company_phone_code" name="settings[company_phone_code]" class="form-select" style="max-width: 160px; flex: 0 0 160px; cursor: pointer; border-top-right-radius: 0; border-bottom-right-radius: 0;" aria-label="Country code">
                                @foreach($cpCountries as $c)
                                    <option value="{{ $c['dial'] }}" @selected($cpSelectedDial === $c['dial'])>{{ $c['flag'] }} {{ $c['name'] }} {{ $c['dial'] }}</option>
                                @endforeach
                            </select>
                            <input type="tel" inputmode="numeric" autocomplete="tel" id="company_phone_number" name="settings[company_phone_number]" class="form-control @error('settings.company_phone') is-invalid @enderror" value="{{ $cpNumberPart }}" placeholder="98007 44827" style="border-top-left-radius: 0; border-bottom-left-radius: 0; margin-left: -1px;">
                        </div>
                        <input type="hidden" name="settings[company_phone]" id="company_phone_hidden" value="{{ old('settings.company_phone', $settings['company_phone'] ?? '') }}">
                        @error('settings.company_phone')
                            <span class="invalid-feedback d-block" role="alert">{{ $message }}</span>
                        @enderror
                        <div class="d-flex justify-content-between align-items-center mt-1">
                            <small class="form-text text-muted">Select country flag and enter number without leading 0 — same as customer phone.</small>
                            <small class="text-muted" id="company_phone_hint" aria-live="polite"></small>
                        </div>
                    </div>

                    {{-- Company Address — sundered ecommerce format (same fields as customer) --}}
                    @php
                        $caLegacy = old('settings.company_address', $settings['company_address'] ?? '');
                        $caHasSundered = trim((string)($settings['company_address_line1'] ?? '') . ($settings['company_city'] ?? '') . ($settings['company_state'] ?? '')) !== '';
                    @endphp
                    @if(!$caHasSundered && trim((string)$caLegacy) !== '')
                        <div class="alert alert-info py-2 small mb-2">
                            <i class="bi bi-info-circle me-1"></i> Migrating from legacy address: <code>{{ Str::limit($caLegacy, 120) }}</code> — split it into the fields below and save. It will replace the legacy line on invoices.
                        </div>
                    @endif
                    <div class="border rounded p-3 mb-3 bg-light-subtle">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <i class="bi bi-geo-alt text-primary"></i>
                            <h6 class="mb-0 fw-semibold">Company Address</h6>
                            <span class="text-muted small ms-1">— standard e-commerce fields, shown on invoices</span>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="company_address_line1" class="form-label">Street address</label>
                                <input type="text" name="settings[company_address_line1]" id="company_address_line1" value="{{ old('settings.company_address_line1', $settings['company_address_line1'] ?? '') }}" placeholder="House no., street name, area" maxlength="255" class="form-control @error('settings.company_address_line1') is-invalid @enderror">
                                @error('settings.company_address_line1') <span class="invalid-feedback d-block" role="alert">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="company_address_line2" class="form-label">Apartment / Suite <span class="text-muted">(optional)</span></label>
                                <input type="text" name="settings[company_address_line2]" id="company_address_line2" value="{{ old('settings.company_address_line2', $settings['company_address_line2'] ?? '') }}" placeholder="Apartment, suite, floor, landmark" maxlength="255" class="form-control @error('settings.company_address_line2') is-invalid @enderror">
                                @error('settings.company_address_line2') <span class="invalid-feedback d-block" role="alert">{{ $message }}</span> @enderror
                            </div>
                        </div>
                        <div class="row g-3 mt-1">
                            <div class="col-md-4">
                                <label for="company_city" class="form-label">City</label>
                                <input type="text" name="settings[company_city]" id="company_city" value="{{ old('settings.company_city', $settings['company_city'] ?? '') }}" placeholder="e.g. Mumbai" maxlength="100" class="form-control @error('settings.company_city') is-invalid @enderror">
                                @error('settings.company_city') <span class="invalid-feedback d-block" role="alert">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-4">
                                <label for="company_state" class="form-label">State / Province</label>
                                <input type="text" name="settings[company_state]" id="company_state" value="{{ old('settings.company_state', $settings['company_state'] ?? '') }}" placeholder="e.g. Maharashtra" maxlength="100" class="form-control @error('settings.company_state') is-invalid @enderror">
                                @error('settings.company_state') <span class="invalid-feedback d-block" role="alert">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-4">
                                <label for="company_postcode" class="form-label">Postcode / ZIP</label>
                                <input type="text" name="settings[company_postcode]" id="company_postcode" value="{{ old('settings.company_postcode', $settings['company_postcode'] ?? '') }}" placeholder="e.g. 400001" maxlength="20" class="form-control @error('settings.company_postcode') is-invalid @enderror">
                                @error('settings.company_postcode') <span class="invalid-feedback d-block" role="alert">{{ $message }}</span> @enderror
                            </div>
                        </div>
                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label for="company_country" class="form-label">Country</label>
                                @php $companyCountryCurrent = old('settings.company_country', $settings['company_country'] ?? 'India'); @endphp
                                <select name="settings[company_country]" id="company_country" class="form-select @error('settings.company_country') is-invalid @enderror">
                                    @php $cCountries = ['India','United States','United Kingdom','Canada','Australia','Singapore','United Arab Emirates','Germany','France','Other']; @endphp
                                    @foreach ($cCountries as $cIn)
                                        <option value="{{ $cIn }}" @selected($companyCountryCurrent === $cIn)>{{ $cIn }}</option>
                                    @endforeach
                                </select>
                                @error('settings.company_country') <span class="invalid-feedback d-block" role="alert">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <div class="form-text mb-2 w-100">State drives GST (CGST/SGST vs IGST). Postcode validates shipping/tax — same rules as customer address.</div>
                            </div>
                        </div>
                        {{-- Keep legacy field hidden for graceful fallback — synced in controller if empty --}}
                        <input type="hidden" name="settings[company_address]" value="{{ old('settings.company_address', $settings['company_address'] ?? '') }}">
                    </div>

                    {{-- Live invoice header preview — mirrors InvoiceEmailService handling --}}
                    <div class="mt-3 p-3 border rounded-3" id="company-preview" style="background: var(--bs-tertiary-bg, #f8f9fa);">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <small class="text-uppercase fw-semibold text-muted" style="letter-spacing:0.06em;font-size:0.7rem;">Invoice header preview</small>
                            <small class="text-muted" style="font-size:0.7rem;"><i class="bi bi-eye me-1"></i>How it looks on invoices</small>
                        </div>
                        <div class="bg-white border rounded p-3 shadow-sm" style="font-size:0.9rem;line-height:1.5;">
                            <div class="fw-bold" id="preview_company_name">{{ $settings['company_name'] ?? 'Your Company Name' }}</div>
                            <div class="text-muted small" id="preview_company_address" style="white-space: pre-line;">{{ ($settings['company_address'] ?? '') !== '' ? $settings['company_address'] : (trim(implode(', ', array_filter([$settings['company_address_line1'] ?? null, $settings['company_address_line2'] ?? null, $settings['company_city'] ?? null, $settings['company_state'] ?? null, $settings['company_postcode'] ?? null, $settings['company_country'] ?? null]))) ?: '123 Business Park, Mumbai, Maharashtra — 400001, India') }}</div>
                            <div class="small mt-1 text-muted">
                                <span id="preview_company_email"><i class="bi bi-envelope me-1"></i>{{ $settings['company_email'] ?? 'billing@example.com' }}</span>
                                <span class="mx-2">·</span>
                                <span id="preview_company_phone"><i class="bi bi-telephone me-1"></i>{{ $settings['company_phone'] ?? '+91 98765 43210' }}</span>
                            </div>
                        </div>
                    </div>
                    <script>
                        (function(){
                            var codeSel = document.getElementById('company_phone_code');
                            var numInput = document.getElementById('company_phone_number');
                            var hidden = document.getElementById('company_phone_hidden');
                            var phoneHint = document.getElementById('company_phone_hint');
                            var pName = document.getElementById('preview_company_name');
                            var pEmail = document.getElementById('preview_company_email');
                            var pPhone = document.getElementById('preview_company_phone');
                            var pAddr = document.getElementById('preview_company_address');
                            var nameInput = document.getElementById('company_name');
                            var emailInput = document.getElementById('company_email');
                            var addrIds = ['company_address_line1','company_address_line2','company_city','company_state','company_postcode','company_country'];
                            function syncPhone(){
                                if(!codeSel || !numInput || !hidden) return;
                                var code = (codeSel.value||'').trim();
                                var num = (numInput.value||'').trim().replace(/\s+/g,' ');
                                hidden.value = num ? (code+' '+num) : code;
                                if(phoneHint){
                                    var v = hidden.value.trim();
                                    if(v==='' || v===code && num===''){ phoneHint.textContent=''; phoneHint.className='text-muted'; }
                                    else {
                                        var digits = hidden.value.replace(/\D/g,'').length;
                                        var ok = /^[\+\d][\d\s\-\.\(\)]{6,49}$/.test(hidden.value) && digits>=7 && digits<=15;
                                        phoneHint.textContent = ok ? '✓ valid' : digits<7 ? digits+' digits — need 7+' : 'check format';
                                        phoneHint.className = ok ? 'text-success' : 'text-warning';
                                    }
                                }
                                if(pPhone) pPhone.innerHTML = '<i class="bi bi-telephone me-1"></i>' + (hidden.value.trim() || '+91 98765 43210');
                            }
                            function updateAddrPreview(){
                                if(!pAddr) return;
                                var parts = [];
                                addrIds.forEach(function(id){
                                    var el = document.getElementById(id);
                                    if(el && el.value.trim()!=='') parts.push(el.value.trim());
                                });
                                var txt = parts.length ? parts.join(', ') : '123 Business Park, Mumbai, Maharashtra — 400001, India';
                                pAddr.textContent = txt;
                            }
                            function updatePreview(){
                                if(pName && nameInput) pName.textContent = nameInput.value.trim() || 'Your Company Name';
                                if(pEmail && emailInput) pEmail.innerHTML = '<i class="bi bi-envelope me-1"></i>' + (emailInput.value.trim() || 'billing@example.com');
                            }
                            if(codeSel) codeSel.addEventListener('change', syncPhone);
                            if(numInput) numInput.addEventListener('input', syncPhone);
                            var form = document.getElementById('settings-form');
                            if(form) form.addEventListener('submit', syncPhone);
                            syncPhone();
                            addrIds.forEach(function(id){
                                var el = document.getElementById(id);
                                if(el) el.addEventListener('input', updateAddrPreview);
                                if(el && el.tagName==='SELECT') el.addEventListener('change', updateAddrPreview);
                            });
                            if(nameInput) nameInput.addEventListener('input', updatePreview);
                            if(emailInput) emailInput.addEventListener('input', updatePreview);
                            updateAddrPreview(); updatePreview();
                        })();
                    </script>
                    <div class="row">
                        <div class="col-md-4">
                            {{-- Deprecated alias: default_currency is legacy untyped; typed currency lives in Billing --}}
                            <x-adminlte-input name="settings[default_currency]" label="Default Currency (Deprecated)"
                                value="{{ old('settings.default_currency', $settings['default_currency'] ?? 'INR') }}" disabled />
                            <small class="form-text text-muted">Deprecated — use <strong>Currency</strong> in Billing tab (typed <code>currency</code>). Read-only alias.</small>
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[default_tax_rate]" label="Default Tax Rate (%)"
                                value="{{ old('settings.default_tax_rate', $settings['default_tax_rate'] ?? '18') }}" />
                        </div>
                        <div class="col-md-4">
                            @php $tzCurrent = old('settings.timezone', $settings['timezone'] ?? 'Asia/Kolkata'); @endphp
                            <x-adminlte-select name="settings[timezone]" label="Timezone">
                                @foreach ($timezonesGrouped as $tzRegion => $tzZoneList)
                                    <optgroup label="{{ $tzRegion }}">
                                        @foreach ($tzZoneList as $tzZone)
                                            <option value="{{ $tzZone }}" @selected($tzZone === $tzCurrent)>{{ $tzZone }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </x-adminlte-select>
                            <small class="form-text text-muted">Timezone used to display all dates and times across the application.</small>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[date_format]" label="Date Format"
                                value="{{ old('settings.date_format', $settings['date_format'] ?? 'Y-m-d') }}" />
                            <small class="form-text text-muted">PHP date format (e.g., Y-m-d)</small>
                        </div>
                    </div>
                </x-adminlte-card>
                @php $lu = $lastUpdated['general'] ?? $lastUpdated['all'] ?? null; @endphp
                <small class="text-muted d-block mb-2 last-updated" data-section="general">@if($lu)Last updated: {{ \Illuminate\Support\Carbon::parse($lu->created_at)->format('Y-m-d H:i:s') }} — <span title="{{ $lu->description }}">{{ \Illuminate\Support\Str::limit($lu->description, 120) }}</span>@else Last updated: never @endif</small>
            </div>

            {{-- Billing (includes Support ticket_prefix/ticket_next_number + legacy billing keys + typed currency) --}}
            <div class="tab-pane fade @if ($activeTab === 'billing') show active @endif" id="pane-billing" role="tabpanel" aria-labelledby="tab-billing">
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
                            <x-adminlte-select name="settings[auto_generate_invoice]" label="Auto-generate invoices">
                                <option value="yes" @selected(($settings['auto_generate_invoice'] ?? 'yes') === 'yes')>Yes</option>
                                <option value="no" @selected(($settings['auto_generate_invoice'] ?? 'yes') === 'no')>No</option>
                            </x-adminlte-select>
                            <small class="form-text text-muted">Yes / No</small>
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[due_days]" label="Days until due" type="number" min="0"
                                value="{{ old('settings.due_days', $settings['due_days'] ?? '7') }}">
                                <small class="form-text text-muted">0 or more days</small>
                            </x-adminlte-input>
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-select name="settings[gst_enabled]" label="GST Enabled">
                                <option value="yes" @selected(($settings['gst_enabled'] ?? 'yes') === 'yes')>Yes</option>
                                <option value="no" @selected(($settings['gst_enabled'] ?? 'yes') === 'no')>No</option>
                            </x-adminlte-select>
                            <small class="form-text text-muted">Yes / No</small>
                        </div>
                    </div>
                    {{-- Typed billing + support keys (previously unrendered) — density 8 fields, show Advanced if >120 total --}}
                    <details class="mt-3">
                        <summary class="small text-muted" style="cursor:pointer;">Advanced — Billing typed keys</summary>
                        <div class="row mt-2">
                            <div class="col-md-3">
                                <x-adminlte-input name="settings[currency]" label="Currency" maxlength="3"
                                    value="{{ old('settings.currency', $settings['currency'] ?? 'INR') }}" />
                                <small class="form-text text-muted">ISO 4217 — 3 letters (e.g., INR, USD)</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-input name="settings[invoice_next_number]" label="Next Invoice Number" type="number" min="0"
                                    value="{{ old('settings.invoice_next_number', $settings['invoice_next_number'] ?? '1') }}">
                                    <small class="form-text text-muted">Min 0</small>
                                </x-adminlte-input>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-input name="settings[tax_rate]" label="Tax Rate (%)" type="number" min="0" max="100" step="0.01"
                                    value="{{ old('settings.tax_rate', $settings['tax_rate'] ?? '18') }}">
                                    <small class="form-text text-muted">0 – 100 %</small>
                                </x-adminlte-input>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-input name="settings[ticket_next_number]" label="Next Ticket Number" type="number" min="0"
                                    value="{{ old('settings.ticket_next_number', $settings['ticket_next_number'] ?? '1') }}">
                                    <small class="form-text text-muted">Min 0</small>
                                </x-adminlte-input>
                            </div>
                        </div>
                    </details>
                </x-adminlte-card>
                @php $lu = $lastUpdated['billing'] ?? $lastUpdated['all'] ?? null; @endphp
                <small class="text-muted d-block mb-2 last-updated" data-section="billing">@if($lu)Last updated: {{ \Illuminate\Support\Carbon::parse($lu->created_at)->format('Y-m-d H:i:s') }} — <span title="{{ $lu->description }}">{{ \Illuminate\Support\Str::limit($lu->description, 120) }}</span>@else Last updated: never @endif</small>
            </div>

            {{-- Email --}}
            <div class="tab-pane fade @if ($activeTab === 'email') show active @endif" id="pane-email" role="tabpanel" aria-labelledby="tab-email">
                <x-adminlte-card icon="bi bi-envelope" title="Email Settings">
                    <div class="row">
                        <div class="col-md-6">
                            <x-adminlte-input name="settings[smtp_host]" label="SMTP Host"
                                value="{{ old('settings.smtp_host', $settings['smtp_host'] ?? '') }}" />
                        </div>
                        <div class="col-md-3">
                            <x-adminlte-input name="settings[smtp_port]" label="SMTP Port" type="number" min="1" max="65535"
                                value="{{ old('settings.smtp_port', $settings['smtp_port'] ?? '587') }}">
                                <small class="form-text text-muted">1 – 65535</small>
                            </x-adminlte-input>
                        </div>
                        <div class="col-md-3">
                            <x-adminlte-select name="settings[smtp_encryption]" label="Encryption">
                                <option value="tls" @selected(($settings['smtp_encryption'] ?? 'tls') === 'tls')>TLS</option>
                                <option value="ssl" @selected(($settings['smtp_encryption'] ?? '') === 'ssl')>SSL</option>
                                <option value="none" @selected(($settings['smtp_encryption'] ?? '') === 'none')>None</option>
                            </x-adminlte-select>
                            <small class="form-text text-muted">tls / ssl / none</small>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <x-adminlte-input name="settings[smtp_username]" label="SMTP Username"
                                value="{{ old('settings.smtp_username', $settings['smtp_username'] ?? '') }}" />
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="smtp_password" class="form-label">SMTP Password</label>
                                <div class="input-group">
                                    <input type="password" name="settings[smtp_password]" id="smtp_password"
                                        value="" placeholder="Leave blank to keep current" class="form-control @error('settings.smtp_password') is-invalid @enderror" autocomplete="new-password">
                                    <button type="button" class="btn btn-outline-secondary encrypted-reveal-btn" data-target="smtp_password" aria-pressed="false" aria-label="Reveal masked value for SMTP Password">Reveal</button>
                                </div>
                                @error('settings.smtp_password')
                                    <span class="invalid-feedback d-block" role="alert">{{ $message }}</span>
                                @enderror
                                <small class="form-text text-muted">Encrypted — leave blank to keep current</small>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <x-adminlte-input name="settings[mail_from_address]" label="From Address" type="email"
                                value="{{ old('settings.mail_from_address', $settings['mail_from_address'] ?? '') }}">
                                <small class="form-text text-muted">Valid email address</small>
                            </x-adminlte-input>
                        </div>
                        <div class="col-md-6">
                            <x-adminlte-input name="settings[mail_from_name]" label="From Name"
                                value="{{ old('settings.mail_from_name', $settings['mail_from_name'] ?? '') }}" />
                        </div>
                    </div>

                    {{-- Test send. The input/button live in this card but belong to
                         #test-email-form (rendered after the settings form — forms
                         cannot nest), so they never post with Save and never count
                         towards dirty tracking, which only watches settings[*]. --}}
                    <hr class="my-3">
                    <div class="row align-items-start" id="test-email-row">
                        <div class="col-md-6">
                            <label for="test-email-input" class="form-label">Send Test Email</label>
                            <div class="input-group">
                                <input type="email" id="test-email-input" name="test_email" form="test-email-form"
                                    class="form-control @error('test_email') is-invalid @enderror"
                                    value="{{ old('test_email', $settings['mail_from_address'] ?? optional(auth()->user())->email ?? '') }}"
                                    placeholder="you@example.com" autocomplete="off">
                                <button type="submit" form="test-email-form" id="test-email-btn" class="btn btn-outline-primary">
                                    <i class="bi bi-send me-1" aria-hidden="true"></i> Send Test Email
                                </button>
                            </div>
                            @error('test_email')
                                <span class="invalid-feedback d-block" role="alert">{{ $message }}</span>
                            @enderror
                            <small class="form-text text-muted">Sends with the <strong>saved</strong> SMTP settings — save this tab first if you just changed them.</small>
                        </div>
                        <div class="col-md-6">
                            <div id="test-email-result" class="alert d-none mt-4 mb-0" role="alert" aria-live="polite"></div>
                        </div>
                    </div>
                </x-adminlte-card>

                {{-- Ticket email policy — department mailboxes are now the sole inbound path.
                     Global Incoming Mail host/user has been removed; each department owns
                     its mailbox in Support > Departments. --}}
                <x-adminlte-card icon="bi bi-inbox" title="Ticket Email Policy">
                    <div class="alert alert-info d-flex align-items-start gap-2 mb-3" role="alert" style="border-left: 3px solid var(--color-info);">
                        <i class="bi bi-info-circle flex-shrink-0 mt-1" aria-hidden="true"></i>
                        <div class="small" style="line-height: var(--leading-normal);">
                            Inbound mail is now <strong>per-department only</strong>. Configure each mailbox in
                            <a href="{{ route('admin.ticket-departments.index') }}">Support &rsaquo; Departments</a> — every enabled department with a mailbox is polled every 5 minutes.
                            No global mailbox is used.
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <x-adminlte-select name="settings[imap_auto_create_customers]" label="Register Unknown Senders">
                                <option value="yes" @selected(($settings['imap_auto_create_customers'] ?? 'no') === 'yes')>Yes</option>
                                <option value="no" @selected(($settings['imap_auto_create_customers'] ?? 'no') === 'no')>No</option>
                            </x-adminlte-select>
                            <small class="form-text text-muted">No = create guest ticket (no new customer account) — recommended</small>
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-select name="settings[imap_default_department]" label="Default Department">
                                <option value="" @selected(($settings['imap_default_department'] ?? '') === '')>First enabled department</option>
                                @foreach (\App\Services\TicketService::departments() as $slug => $label)
                                    <option value="{{ $slug }}" @selected(($settings['imap_default_department'] ?? '') === $slug)>{{ $label }}</option>
                                @endforeach
                            </x-adminlte-select>
                            <small class="form-text text-muted">Fallback when a mailbox has no department — blank = is_default / first enabled</small>
                            @php
                                $imapDefaultDeptSlug = $settings['imap_default_department'] ?? '';
                            @endphp
                            @if ($imapDefaultDeptSlug !== '' && ! array_key_exists($imapDefaultDeptSlug, \App\Services\TicketService::departments()))
                                <small class="form-text text-warning">
                                    <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                                    The stored default department is disabled — mail falls back to the default/first enabled department instead.
                                </small>
                            @endif
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[imap_max_new_tickets_per_hour]"
                                              label="New Ticket Limit (per sender, per hour)"
                                              type="number"
                                              min="1"
                                              max="1000"
                                              :value="$settings['imap_max_new_tickets_per_hour'] ?? 20"/>
                            <small class="form-text text-muted">Replies are never limited — this caps how many new tickets one address can open. Mail over the limit is left unread for a human.</small>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12">
                            <small class="form-text text-muted">
                                <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                                Whether a desk accepts new tickets by email is set per department in
                                <a href="{{ route('admin.ticket-departments.index') }}">Support &rsaquo; Departments</a>.
                            </small>
                        </div>
                    </div>
                </x-adminlte-card>
                @php $lu = $lastUpdated['email'] ?? $lastUpdated['all'] ?? null; @endphp
                <small class="text-muted d-block mb-2 last-updated" data-section="email">@if($lu)Last updated: {{ \Illuminate\Support\Carbon::parse($lu->created_at)->format('Y-m-d H:i:s') }} — <span title="{{ $lu->description }}">{{ \Illuminate\Support\Str::limit($lu->description, 120) }}</span>@else Last updated: never @endif</small>
            </div>

            {{-- Security --}}
            <div class="tab-pane fade @if ($activeTab === 'security') show active @endif" id="pane-security" role="tabpanel" aria-labelledby="tab-security">
                <x-adminlte-card icon="bi bi-shield-lock" title="Security Settings">
                    <div class="row">
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[session_timeout]" label="Session Timeout (minutes)" type="number" min="1"
                                value="{{ old('settings.session_timeout', $settings['session_timeout'] ?? '120') }}">
                                <small class="form-text text-muted">Min 1 minute</small>
                            </x-adminlte-input>
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[max_login_attempts]" label="Max Login Attempts" type="number" min="1"
                                value="{{ old('settings.max_login_attempts', $settings['max_login_attempts'] ?? '5') }}">
                                <small class="form-text text-muted">Min 1</small>
                            </x-adminlte-input>
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[lockout_duration]" label="Lockout Duration (minutes)" type="number" min="0"
                                value="{{ old('settings.lockout_duration', $settings['lockout_duration'] ?? '15') }}">
                                <small class="form-text text-muted">Min 0 minutes</small>
                            </x-adminlte-input>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <x-adminlte-select name="settings[force_2fa]" label="Force 2FA">
                                <option value="yes" @selected(($settings['force_2fa'] ?? 'no') === 'yes')>Yes</option>
                                <option value="no" @selected(($settings['force_2fa'] ?? 'no') === 'no')>No</option>
                            </x-adminlte-select>
                            <small class="form-text text-muted">Yes / No</small>
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[password_min_length]" label="Min Password Length" type="number" min="1"
                                value="{{ old('settings.password_min_length', $settings['password_min_length'] ?? '8') }}">
                                <small class="form-text text-muted">Min 1 character</small>
                            </x-adminlte-input>
                        </div>
                    </div>
                </x-adminlte-card>
                <x-adminlte-card icon="bi bi-shield-check" title="Login & Registration Hardening">
                    <div class="row g-3 hardening-grid align-items-stretch">
                        <div class="col-md-3 d-flex flex-column">
                            <x-adminlte-select name="settings[security_honeypot_enabled]" label="Honeypot Bot Trap">
                                <option value="yes" @selected(($settings['security_honeypot_enabled'] ?? 'yes') === 'yes')>Yes</option>
                                <option value="no" @selected(($settings['security_honeypot_enabled'] ?? 'yes') === 'no')>No</option>
                            </x-adminlte-select>
                            <small class="form-text text-muted mt-1">Hidden field that blocks bots on register</small>
                        </div>
                        <div class="col-md-3 d-flex flex-column">
                            <x-adminlte-select name="settings[security_headers_enabled]" label="Security Headers">
                                <option value="yes" @selected(($settings['security_headers_enabled'] ?? 'yes') === 'yes')>Yes</option>
                                <option value="no" @selected(($settings['security_headers_enabled'] ?? 'yes') === 'no')>No</option>
                            </x-adminlte-select>
                            <small class="form-text text-muted mt-1">HSTS, CSP, X-Frame and related headers</small>
                        </div>
                        <div class="col-md-3 d-flex flex-column">
                            <x-adminlte-select name="settings[security_strong_password_enabled]" label="Strong Password Policy">
                                <option value="yes" @selected(($settings['security_strong_password_enabled'] ?? 'yes') === 'yes')>Yes</option>
                                <option value="no" @selected(($settings['security_strong_password_enabled'] ?? 'yes') === 'no')>No</option>
                            </x-adminlte-select>
                            <small class="form-text text-muted mt-1">12+ chars, mixed case, symbols, breach check</small>
                        </div>
                        <div class="col-md-3 d-flex flex-column">
                            <x-adminlte-select name="settings[security_math_captcha_enabled]" label="Math Captcha">
                                <option value="yes" @selected(($settings['security_math_captcha_enabled'] ?? 'no') === 'yes')>Yes</option>
                                <option value="no" @selected(($settings['security_math_captcha_enabled'] ?? 'no') === 'no')>No</option>
                            </x-adminlte-select>
                            <small class="form-text text-muted mt-1">Simple addition/subtraction on login & register</small>
                        </div>
                    </div>
                </x-adminlte-card>
                @php $lu = $lastUpdated['security'] ?? $lastUpdated['all'] ?? null; @endphp
                <small class="text-muted d-block mb-2 last-updated" data-section="security">@if($lu)Last updated: {{ \Illuminate\Support\Carbon::parse($lu->created_at)->format('Y-m-d H:i:s') }} — <span title="{{ $lu->description }}">{{ \Illuminate\Support\Str::limit($lu->description, 120) }}</span>@else Last updated: never @endif</small>
            </div>

            {{-- Notifications --}}
            <div class="tab-pane fade @if ($activeTab === 'notification') show active @endif" id="pane-notification" role="tabpanel" aria-labelledby="tab-notification">
                <x-adminlte-card icon="bi bi-bell" title="Notification Settings">
                    <div class="row">
                        <div class="col-md-4">
                            <x-adminlte-select name="settings[notify_overdue_invoices]" label="Overdue invoice notifications">
                                <option value="yes" @selected(($settings['notify_overdue_invoices'] ?? 'yes') === 'yes')>Yes</option>
                                <option value="no" @selected(($settings['notify_overdue_invoices'] ?? 'yes') === 'no')>No</option>
                            </x-adminlte-select>
                            <small class="form-text text-muted">Yes / No</small>
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-select name="settings[notify_domain_expiry]" label="Domain expiry notifications">
                                <option value="yes" @selected(($settings['notify_domain_expiry'] ?? 'yes') === 'yes')>Yes</option>
                                <option value="no" @selected(($settings['notify_domain_expiry'] ?? 'yes') === 'no')>No</option>
                            </x-adminlte-select>
                            <small class="form-text text-muted">Yes / No</small>
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-select name="settings[notify_new_tickets]" label="New ticket notifications">
                                <option value="yes" @selected(($settings['notify_new_tickets'] ?? 'yes') === 'yes')>Yes</option>
                                <option value="no" @selected(($settings['notify_new_tickets'] ?? 'yes') === 'no')>No</option>
                            </x-adminlte-select>
                            <small class="form-text text-muted">Yes / No</small>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[domain_expiry_warning_days]" label="Domain expiry warning (days)" type="number" min="0" max="365"
                                value="{{ old('settings.domain_expiry_warning_days', $settings['domain_expiry_warning_days'] ?? '30') }}">
                                <small class="form-text text-muted">0 – 365 days</small>
                            </x-adminlte-input>
                        </div>
                    </div>
                </x-adminlte-card>
                @php $lu = $lastUpdated['notification'] ?? $lastUpdated['all'] ?? null; @endphp
                <small class="text-muted d-block mb-2 last-updated" data-section="notification">@if($lu)Last updated: {{ \Illuminate\Support\Carbon::parse($lu->created_at)->format('Y-m-d H:i:s') }} — <span title="{{ $lu->description }}">{{ \Illuminate\Support\Str::limit($lu->description, 120) }}</span>@else Last updated: never @endif</small>
            </div>

            {{-- Domain --}}
            <div class="tab-pane fade @if ($activeTab === 'domain') show active @endif" id="pane-domain" role="tabpanel" aria-labelledby="tab-domain">
                <x-adminlte-card icon="bi bi-globe" title="Domain Settings">
                    <div class="row">
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[domain_default_registrar]" label="Default Registrar"
                                value="{{ old('settings.domain_default_registrar', $settings['domain_default_registrar'] ?? '') }}">
                                <small class="form-text text-muted">(leave blank to keep current; to clear, contact admin)</small>
                            </x-adminlte-input>
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[domain_pricing_tier]" label="Pricing Tier"
                                value="{{ old('settings.domain_pricing_tier', $settings['domain_pricing_tier'] ?? 'standard') }}" />
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[domain_renewal_reminder_days]" label="Renewal Reminder (days)" type="number" min="0" max="365"
                                value="{{ old('settings.domain_renewal_reminder_days', $settings['domain_renewal_reminder_days'] ?? '30') }}">
                                <small class="form-text text-muted">0 – 365 days</small>
                            </x-adminlte-input>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[domain_transfer_lock_days]" label="Transfer Lock (days)" type="number" min="0"
                                value="{{ old('settings.domain_transfer_lock_days', $settings['domain_transfer_lock_days'] ?? '60') }}">
                                <small class="form-text text-muted">Min 0 days</small>
                            </x-adminlte-input>
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[domain_nameserver1]" label="Nameserver 1"
                                value="{{ old('settings.domain_nameserver1', $settings['domain_nameserver1'] ?? '') }}">
                                <small class="form-text text-muted">(leave blank to keep current; to clear, contact admin)</small>
                            </x-adminlte-input>
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[domain_nameserver2]" label="Nameserver 2"
                                value="{{ old('settings.domain_nameserver2', $settings['domain_nameserver2'] ?? '') }}">
                                <small class="form-text text-muted">(leave blank to keep current; to clear, contact admin)</small>
                            </x-adminlte-input>
                        </div>
                    </div>
                    <details class="mt-3">
                        <summary class="small text-muted" style="cursor:pointer;">Advanced — Domain automation &amp; DNS</summary>
                        <div class="row mt-2">
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[domain_auto_registration]" label="Auto Registration">
                                    <option value="yes" @selected(($settings['domain_auto_registration'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['domain_auto_registration'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[domain_transfer_enabled]" label="Transfer Enabled">
                                    <option value="yes" @selected(($settings['domain_transfer_enabled'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['domain_transfer_enabled'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[domain_transfer_lock]" label="Transfer Lock">
                                    <option value="yes" @selected(($settings['domain_transfer_lock'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['domain_transfer_lock'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[domain_dns_enabled]" label="DNS Enabled">
                                    <option value="yes" @selected(($settings['domain_dns_enabled'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['domain_dns_enabled'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <x-adminlte-input name="settings[domain_nameserver3]" label="Nameserver 3"
                                    value="{{ old('settings.domain_nameserver3', $settings['domain_nameserver3'] ?? '') }}">
                                    <small class="form-text text-muted">(leave blank to keep current; to clear, contact admin)</small>
                                </x-adminlte-input>
                            </div>
                            <div class="col-md-4">
                                <x-adminlte-input name="settings[domain_nameserver4]" label="Nameserver 4"
                                    value="{{ old('settings.domain_nameserver4', $settings['domain_nameserver4'] ?? '') }}">
                                    <small class="form-text text-muted">(leave blank to keep current; to clear, contact admin)</small>
                                </x-adminlte-input>
                            </div>
                            <div class="col-md-4">
                                <x-adminlte-input name="settings[domain_dns_provider]" label="DNS Provider"
                                    value="{{ old('settings.domain_dns_provider', $settings['domain_dns_provider'] ?? '') }}">
                                    <small class="form-text text-muted">(leave blank to keep current; to clear, contact admin)</small>
                                </x-adminlte-input>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <x-adminlte-select name="settings[domain_whois_privacy]" label="WHOIS Privacy">
                                    <option value="yes" @selected(($settings['domain_whois_privacy'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['domain_whois_privacy'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                        </div>
                    </details>
                </x-adminlte-card>
                @php $lu = $lastUpdated['domain'] ?? $lastUpdated['all'] ?? null; @endphp
                <small class="text-muted d-block mb-2 last-updated" data-section="domain">@if($lu)Last updated: {{ \Illuminate\Support\Carbon::parse($lu->created_at)->format('Y-m-d H:i:s') }} — <span title="{{ $lu->description }}">{{ \Illuminate\Support\Str::limit($lu->description, 120) }}</span>@else Last updated: never @endif</small>
            </div>

            {{-- Integration --}}
            <div class="tab-pane fade @if ($activeTab === 'integration') show active @endif" id="pane-integration" role="tabpanel" aria-labelledby="tab-integration">
                <x-adminlte-card icon="bi bi-plug" title="Integration Settings">
                    <div class="row">
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[cpanel_host]" label="cPanel Host"
                                value="{{ old('settings.cpanel_host', $settings['cpanel_host'] ?? '') }}">
                                <small class="form-text text-muted">(leave blank to keep current; to clear, contact admin)</small>
                            </x-adminlte-input>
                        </div>
                        <div class="col-md-2">
                            <x-adminlte-input name="settings[cpanel_port]" label="cPanel Port" type="number" min="1" max="65535"
                                value="{{ old('settings.cpanel_port', $settings['cpanel_port'] ?? '2083') }}">
                                <small class="form-text text-muted">1 – 65535</small>
                            </x-adminlte-input>
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[plesk_host]" label="Plesk Host"
                                value="{{ old('settings.plesk_host', $settings['plesk_host'] ?? '') }}">
                                <small class="form-text text-muted">(leave blank to keep current; to clear, contact admin)</small>
                            </x-adminlte-input>
                        </div>
                        <div class="col-md-2">
                            <x-adminlte-input name="settings[plesk_port]" label="Plesk Port" type="number" min="1" max="65535"
                                value="{{ old('settings.plesk_port', $settings['plesk_port'] ?? '8443') }}">
                                <small class="form-text text-muted">1 – 65535</small>
                            </x-adminlte-input>
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
                    <details class="mt-3">
                        <summary class="small text-muted" style="cursor:pointer;">Advanced — Integration toggles &amp; secrets</summary>
                        <div class="row mt-2">
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[cpanel_enabled]" label="cPanel Enabled">
                                    <option value="yes" @selected(($settings['cpanel_enabled'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['cpanel_enabled'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="cpanel_api_token" class="form-label">cPanel API Token</label>
                                    <div class="input-group">
                                        <input type="password" name="settings[cpanel_api_token]" id="cpanel_api_token"
                                            value="" placeholder="Leave blank to keep current" class="form-control @error('settings.cpanel_api_token') is-invalid @enderror" autocomplete="new-password">
                                        <button type="button" class="btn btn-outline-secondary encrypted-reveal-btn" data-target="cpanel_api_token" aria-pressed="false" aria-label="Reveal masked value for cPanel API Token">Reveal</button>
                                    </div>
                                    @error('settings.cpanel_api_token')
                                        <span class="invalid-feedback d-block" role="alert">{{ $message }}</span>
                                    @enderror
                                    <small class="form-text text-muted">Encrypted — leave blank to keep current</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[plesk_enabled]" label="Plesk Enabled">
                                    <option value="yes" @selected(($settings['plesk_enabled'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['plesk_enabled'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="plesk_password" class="form-label">Plesk Password</label>
                                    <div class="input-group">
                                        <input type="password" name="settings[plesk_password]" id="plesk_password"
                                            value="" placeholder="Leave blank to keep current" class="form-control @error('settings.plesk_password') is-invalid @enderror" autocomplete="new-password">
                                        <button type="button" class="btn btn-outline-secondary encrypted-reveal-btn" data-target="plesk_password" aria-pressed="false" aria-label="Reveal masked value for Plesk Password">Reveal</button>
                                    </div>
                                    @error('settings.plesk_password')
                                        <span class="invalid-feedback d-block" role="alert">{{ $message }}</span>
                                    @enderror
                                    <small class="form-text text-muted">Encrypted — leave blank to keep current</small>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[resellerclub_enabled]" label="ResellerClub Enabled">
                                    <option value="yes" @selected(($settings['resellerclub_enabled'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['resellerclub_enabled'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="resellerclub_api_key" class="form-label">ResellerClub API Key</label>
                                    <div class="input-group">
                                        <input type="password" name="settings[resellerclub_api_key]" id="resellerclub_api_key"
                                            value="" placeholder="Leave blank to keep current" class="form-control @error('settings.resellerclub_api_key') is-invalid @enderror" autocomplete="new-password">
                                        <button type="button" class="btn btn-outline-secondary encrypted-reveal-btn" data-target="resellerclub_api_key" aria-pressed="false" aria-label="Reveal masked value for ResellerClub API Key">Reveal</button>
                                    </div>
                                    @error('settings.resellerclub_api_key')
                                        <span class="invalid-feedback d-block" role="alert">{{ $message }}</span>
                                    @enderror
                                    <small class="form-text text-muted">Encrypted — leave blank to keep current</small>
                                </div>
                            </div>
                        </div>
                    </details>
                </x-adminlte-card>
                @php $lu = $lastUpdated['integration'] ?? $lastUpdated['all'] ?? null; @endphp
                <small class="text-muted d-block mb-2 last-updated" data-section="integration">@if($lu)Last updated: {{ \Illuminate\Support\Carbon::parse($lu->created_at)->format('Y-m-d H:i:s') }} — <span title="{{ $lu->description }}">{{ \Illuminate\Support\Str::limit($lu->description, 120) }}</span>@else Last updated: never @endif</small>
            </div>

            {{-- Hosting --}}
            <div class="tab-pane fade @if ($activeTab === 'hosting') show active @endif" id="pane-hosting" role="tabpanel" aria-labelledby="tab-hosting">
                <x-adminlte-card icon="bi bi-hdd" title="Hosting Settings">
                    <div class="row">
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[hosting_default_panel]" label="Default Control Panel"
                                value="{{ old('settings.hosting_default_panel', $settings['hosting_default_panel'] ?? 'cpanel') }}" />
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[hosting_default_server_group]" label="Default Server Group"
                                value="{{ old('settings.hosting_default_server_group', $settings['hosting_default_server_group'] ?? '') }}">
                                <small class="form-text text-muted">(leave blank to keep current; to clear, contact admin)</small>
                            </x-adminlte-input>
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[hosting_provision_retries]" label="Provision Retries" type="number" min="0"
                                value="{{ old('settings.hosting_provision_retries', $settings['hosting_provision_retries'] ?? '3') }}">
                                <small class="form-text text-muted">Min 0</small>
                            </x-adminlte-input>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[hosting_suspend_after_days]" label="Suspend After (days)" type="number" min="0"
                                value="{{ old('settings.hosting_suspend_after_days', $settings['hosting_suspend_after_days'] ?? '7') }}">
                                <small class="form-text text-muted">Min 0 days</small>
                            </x-adminlte-input>
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[hosting_terminate_after_days]" label="Terminate After (days)" type="number" min="0"
                                value="{{ old('settings.hosting_terminate_after_days', $settings['hosting_terminate_after_days'] ?? '30') }}">
                                <small class="form-text text-muted">Min 0 days</small>
                            </x-adminlte-input>
                        </div>
                    </div>
                    <details class="mt-3">
                        <summary class="small text-muted" style="cursor:pointer;">Advanced — Hosting automation</summary>
                        <div class="row mt-2">
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[hosting_auto_provision]" label="Auto Provision">
                                    <option value="yes" @selected(($settings['hosting_auto_provision'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['hosting_auto_provision'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[hosting_suspend_on_overdue]" label="Suspend On Overdue">
                                    <option value="yes" @selected(($settings['hosting_suspend_on_overdue'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['hosting_suspend_on_overdue'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[hosting_unsuspend_on_payment]" label="Unsuspend On Payment">
                                    <option value="yes" @selected(($settings['hosting_unsuspend_on_payment'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['hosting_unsuspend_on_payment'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[hosting_allow_account_creation]" label="Allow Account Creation">
                                    <option value="yes" @selected(($settings['hosting_allow_account_creation'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['hosting_allow_account_creation'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-3">
                                <x-adminlte-input name="settings[hosting_max_accounts_per_server]" label="Max Accounts / Server" type="number" min="0"
                                    value="{{ old('settings.hosting_max_accounts_per_server', $settings['hosting_max_accounts_per_server'] ?? '0') }}">
                                    <small class="form-text text-muted">Min 0 (0 = unlimited)</small>
                                </x-adminlte-input>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-input name="settings[hosting_documentation_url]" label="Documentation URL"
                                    value="{{ old('settings.hosting_documentation_url', $settings['hosting_documentation_url'] ?? '') }}" />
                                <small class="form-text text-muted">Max 500 chars</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-input name="settings[hosting_terms_url]" label="Terms URL"
                                    value="{{ old('settings.hosting_terms_url', $settings['hosting_terms_url'] ?? '') }}" />
                                <small class="form-text text-muted">Max 500 chars</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[hosting_welcome_email_enabled]" label="Welcome Email">
                                    <option value="yes" @selected(($settings['hosting_welcome_email_enabled'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['hosting_welcome_email_enabled'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <x-adminlte-select name="settings[hosting_backup_enabled]" label="Backup Enabled">
                                    <option value="yes" @selected(($settings['hosting_backup_enabled'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['hosting_backup_enabled'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                        </div>
                    </details>
                </x-adminlte-card>
                @php $lu = $lastUpdated['hosting'] ?? $lastUpdated['all'] ?? null; @endphp
                <small class="text-muted d-block mb-2 last-updated" data-section="hosting">@if($lu)Last updated: {{ \Illuminate\Support\Carbon::parse($lu->created_at)->format('Y-m-d H:i:s') }} — <span title="{{ $lu->description }}">{{ \Illuminate\Support\Str::limit($lu->description, 120) }}</span>@else Last updated: never @endif</small>
            </div>

            {{-- IPAM --}}
            <div class="tab-pane fade @if ($activeTab === 'ipam') show active @endif" id="pane-ipam" role="tabpanel" aria-labelledby="tab-ipam">
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
                            <x-adminlte-input name="settings[ipam_reservation_hold_days]" label="Reservation Hold (days)" type="number" min="0"
                                value="{{ old('settings.ipam_reservation_hold_days', $settings['ipam_reservation_hold_days'] ?? '14') }}">
                                <small class="form-text text-muted">Min 0 days</small>
                            </x-adminlte-input>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[ipam_scan_interval_minutes]" label="Scan Interval (minutes)" type="number" min="1"
                                value="{{ old('settings.ipam_scan_interval_minutes', $settings['ipam_scan_interval_minutes'] ?? '60') }}">
                                <small class="form-text text-muted">Min 1 minute</small>
                            </x-adminlte-input>
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[ipam_dns_reverse_zone]" label="DNS Reverse Zone"
                                value="{{ old('settings.ipam_dns_reverse_zone', $settings['ipam_dns_reverse_zone'] ?? '') }}" />
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[ipam_unused_release_days]" label="Release Unused After (days)" type="number" min="0"
                                value="{{ old('settings.ipam_unused_release_days', $settings['ipam_unused_release_days'] ?? '90') }}">
                                <small class="form-text text-muted">Min 0 days</small>
                            </x-adminlte-input>
                        </div>
                    </div>
                    <details class="mt-3">
                        <summary class="small text-muted" style="cursor:pointer;">Advanced — IPAM features</summary>
                        <div class="row mt-2">
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[ipam_enabled]" label="IPAM Enabled">
                                    <option value="yes" @selected(($settings['ipam_enabled'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['ipam_enabled'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[ipam_auto_allocate]" label="Auto Allocate">
                                    <option value="yes" @selected(($settings['ipam_auto_allocate'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['ipam_auto_allocate'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[ipam_allow_public_ipv6]" label="Allow Public IPv6">
                                    <option value="yes" @selected(($settings['ipam_allow_public_ipv6'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['ipam_allow_public_ipv6'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[ipam_auto_release_unused]" label="Auto Release Unused">
                                    <option value="yes" @selected(($settings['ipam_auto_release_unused'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['ipam_auto_release_unused'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-3">
                                <x-adminlte-input name="settings[ipam_low_capacity_warning_percent]" label="Low Capacity Warning (%)" type="number" min="0" max="100"
                                    value="{{ old('settings.ipam_low_capacity_warning_percent', $settings['ipam_low_capacity_warning_percent'] ?? '20') }}">
                                    <small class="form-text text-muted">0 – 100 %</small>
                                </x-adminlte-input>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[ipam_validate_networks]" label="Validate Networks">
                                    <option value="yes" @selected(($settings['ipam_validate_networks'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['ipam_validate_networks'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[ipam_vlan_tracking]" label="VLAN Tracking">
                                    <option value="yes" @selected(($settings['ipam_vlan_tracking'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['ipam_vlan_tracking'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-input name="settings[ipam_audit_retention_days]" label="Audit Retention (days)" type="number" min="0"
                                    value="{{ old('settings.ipam_audit_retention_days', $settings['ipam_audit_retention_days'] ?? '365') }}">
                                    <small class="form-text text-muted">Min 0 days</small>
                                </x-adminlte-input>
                            </div>
                        </div>
                    </details>
                </x-adminlte-card>
                @php $lu = $lastUpdated['ipam'] ?? $lastUpdated['all'] ?? null; @endphp
                <small class="text-muted d-block mb-2 last-updated" data-section="ipam">@if($lu)Last updated: {{ \Illuminate\Support\Carbon::parse($lu->created_at)->format('Y-m-d H:i:s') }} — <span title="{{ $lu->description }}">{{ \Illuminate\Support\Str::limit($lu->description, 120) }}</span>@else Last updated: never @endif</small>
            </div>

            {{-- Inventory --}}
            <div class="tab-pane fade @if ($activeTab === 'inventory') show active @endif" id="pane-inventory" role="tabpanel" aria-labelledby="tab-inventory">
                <x-adminlte-card icon="bi bi-box-seam" title="Inventory Settings">
                    <div class="row">
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[inventory_stock_unit]" label="Stock Unit"
                                value="{{ old('settings.inventory_stock_unit', $settings['inventory_stock_unit'] ?? 'units') }}" />
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[inventory_low_stock_threshold]" label="Low Stock Threshold" type="number" min="0"
                                value="{{ old('settings.inventory_low_stock_threshold', $settings['inventory_low_stock_threshold'] ?? '5') }}">
                                <small class="form-text text-muted">Min 0</small>
                            </x-adminlte-input>
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[inventory_restock_min_quantity]" label="Restock Min Quantity" type="number" min="0"
                                value="{{ old('settings.inventory_restock_min_quantity', $settings['inventory_restock_min_quantity'] ?? '10') }}">
                                <small class="form-text text-muted">Min 0</small>
                            </x-adminlte-input>
                        </div>
                    </div>
                    <details class="mt-3">
                        <summary class="small text-muted" style="cursor:pointer;">Advanced — Inventory tracking</summary>
                        <div class="row mt-2">
                            <div class="col-md-4">
                                <x-adminlte-select name="settings[inventory_track_stock]" label="Track Stock">
                                    <option value="yes" @selected(($settings['inventory_track_stock'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['inventory_track_stock'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-4">
                                <x-adminlte-select name="settings[inventory_auto_restock]" label="Auto Restock">
                                    <option value="yes" @selected(($settings['inventory_auto_restock'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['inventory_auto_restock'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-4">
                                <x-adminlte-select name="settings[inventory_notify_low_stock]" label="Notify Low Stock">
                                    <option value="yes" @selected(($settings['inventory_notify_low_stock'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['inventory_notify_low_stock'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                        </div>
                    </details>
                </x-adminlte-card>
                @php $lu = $lastUpdated['inventory'] ?? $lastUpdated['all'] ?? null; @endphp
                <small class="text-muted d-block mb-2 last-updated" data-section="inventory">@if($lu)Last updated: {{ \Illuminate\Support\Carbon::parse($lu->created_at)->format('Y-m-d H:i:s') }} — <span title="{{ $lu->description }}">{{ \Illuminate\Support\Str::limit($lu->description, 120) }}</span>@else Last updated: never @endif</small>
            </div>

            {{-- Catalog --}}
            <div class="tab-pane fade @if ($activeTab === 'catalog') show active @endif" id="pane-catalog" role="tabpanel" aria-labelledby="tab-catalog">
                <x-adminlte-card icon="bi bi-shop" title="Catalog Settings">
                    <div class="row">
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[catalog_default_sort]" label="Default Sort Order"
                                value="{{ old('settings.catalog_default_sort', $settings['catalog_default_sort'] ?? 'sort_order') }}" />
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[catalog_products_per_page]" label="Products Per Page" type="number" min="1" max="100"
                                value="{{ old('settings.catalog_products_per_page', $settings['catalog_products_per_page'] ?? '12') }}">
                                <small class="form-text text-muted">1 – 100</small>
                            </x-adminlte-input>
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[catalog_price_precision]" label="Price Precision" type="number" min="0" max="4"
                                value="{{ old('settings.catalog_price_precision', $settings['catalog_price_precision'] ?? '2') }}">
                                <small class="form-text text-muted">0 – 4</small>
                            </x-adminlte-input>
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
                    <details class="mt-3">
                        <summary class="small text-muted" style="cursor:pointer;">Advanced — Catalog display</summary>
                        <div class="row mt-2">
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[catalog_show_inactive]" label="Show Inactive">
                                    <option value="yes" @selected(($settings['catalog_show_inactive'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['catalog_show_inactive'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[catalog_require_domain_for_hosting]" label="Require Domain For Hosting">
                                    <option value="yes" @selected(($settings['catalog_require_domain_for_hosting'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['catalog_require_domain_for_hosting'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[catalog_display_prices_with_tax]" label="Display Prices With Tax">
                                    <option value="yes" @selected(($settings['catalog_display_prices_with_tax'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['catalog_display_prices_with_tax'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[catalog_show_out_of_stock]" label="Show Out Of Stock">
                                    <option value="yes" @selected(($settings['catalog_show_out_of_stock'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['catalog_show_out_of_stock'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[catalog_allow_preorders]" label="Allow Preorders">
                                    <option value="yes" @selected(($settings['catalog_allow_preorders'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['catalog_allow_preorders'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[catalog_hide_addons]" label="Hide Addons">
                                    <option value="yes" @selected(($settings['catalog_hide_addons'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['catalog_hide_addons'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[catalog_show_reviews]" label="Show Reviews">
                                    <option value="yes" @selected(($settings['catalog_show_reviews'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['catalog_show_reviews'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-input name="settings[catalog_bundle_discount_default]" label="Bundle Discount (%)" type="number" min="0" max="100" step="0.01"
                                    value="{{ old('settings.catalog_bundle_discount_default', $settings['catalog_bundle_discount_default'] ?? '0') }}">
                                    <small class="form-text text-muted">0 – 100 %</small>
                                </x-adminlte-input>
                            </div>
                        </div>
                    </details>
                </x-adminlte-card>
                @php $lu = $lastUpdated['catalog'] ?? $lastUpdated['all'] ?? null; @endphp
                <small class="text-muted d-block mb-2 last-updated" data-section="catalog">@if($lu)Last updated: {{ \Illuminate\Support\Carbon::parse($lu->created_at)->format('Y-m-d H:i:s') }} — <span title="{{ $lu->description }}">{{ \Illuminate\Support\Str::limit($lu->description, 120) }}</span>@else Last updated: never @endif</small>
            </div>

            {{-- Product --}}
            <div class="tab-pane fade @if ($activeTab === 'product') show active @endif" id="pane-product" role="tabpanel" aria-labelledby="tab-product">
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
                            <x-adminlte-input name="settings[product_trial_days]" label="Trial Days" type="number" min="0" max="365"
                                value="{{ old('settings.product_trial_days', $settings['product_trial_days'] ?? '0') }}">
                                <small class="form-text text-muted">0 – 365 days</small>
                            </x-adminlte-input>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[product_license_key_prefix]" label="License Key Prefix"
                                value="{{ old('settings.product_license_key_prefix', $settings['product_license_key_prefix'] ?? '') }}" />
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[product_reseller_markup_percent]" label="Reseller Markup (%)" type="number" min="0" max="1000"
                                value="{{ old('settings.product_reseller_markup_percent', $settings['product_reseller_markup_percent'] ?? '0') }}">
                                <small class="form-text text-muted">0 – 1000 %</small>
                            </x-adminlte-input>
                        </div>
                    </div>
                    <details class="mt-3">
                        <summary class="small text-muted" style="cursor:pointer;">Advanced — Product features</summary>
                        <div class="row mt-2">
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[product_require_domain]" label="Require Domain">
                                    <option value="yes" @selected(($settings['product_require_domain'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['product_require_domain'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[product_enable_upgrades]" label="Enable Upgrades">
                                    <option value="yes" @selected(($settings['product_enable_upgrades'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['product_enable_upgrades'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[product_enable_downgrades]" label="Enable Downgrades">
                                    <option value="yes" @selected(($settings['product_enable_downgrades'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['product_enable_downgrades'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[product_allow_custom_pricing]" label="Allow Custom Pricing">
                                    <option value="yes" @selected(($settings['product_allow_custom_pricing'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['product_allow_custom_pricing'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[product_trial_enabled]" label="Trial Enabled">
                                    <option value="yes" @selected(($settings['product_trial_enabled'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['product_trial_enabled'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[product_prorated_charges]" label="Prorated Charges">
                                    <option value="yes" @selected(($settings['product_prorated_charges'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['product_prorated_charges'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[product_catalog_sync_enabled]" label="Catalog Sync">
                                    <option value="yes" @selected(($settings['product_catalog_sync_enabled'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['product_catalog_sync_enabled'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[product_approval_required]" label="Approval Required">
                                    <option value="yes" @selected(($settings['product_approval_required'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['product_approval_required'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[product_show_in_order_form]" label="Show In Order Form">
                                    <option value="yes" @selected(($settings['product_show_in_order_form'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['product_show_in_order_form'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[product_gst_applicable]" label="GST Applicable">
                                    <option value="yes" @selected(($settings['product_gst_applicable'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['product_gst_applicable'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[product_version_management]" label="Version Management">
                                    <option value="yes" @selected(($settings['product_version_management'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['product_version_management'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                        </div>
                    </details>
                </x-adminlte-card>
                @php $lu = $lastUpdated['product'] ?? $lastUpdated['all'] ?? null; @endphp
                <small class="text-muted d-block mb-2 last-updated" data-section="product">@if($lu)Last updated: {{ \Illuminate\Support\Carbon::parse($lu->created_at)->format('Y-m-d H:i:s') }} — <span title="{{ $lu->description }}">{{ \Illuminate\Support\Str::limit($lu->description, 120) }}</span>@else Last updated: never @endif</small>
            </div>

            {{-- Analytics --}}
            <div class="tab-pane fade @if ($activeTab === 'analytics') show active @endif" id="pane-analytics" role="tabpanel" aria-labelledby="tab-analytics">
                <x-adminlte-card icon="bi bi-graph-up" title="Analytics Settings">
                    <div class="row">
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[analytics_tracking_code]" label="Tracking Code"
                                value="{{ old('settings.analytics_tracking_code', $settings['analytics_tracking_code'] ?? '') }}" />
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[analytics_retention_days]" label="Data Retention (days)" type="number" min="0"
                                value="{{ old('settings.analytics_retention_days', $settings['analytics_retention_days'] ?? '180') }}">
                                <small class="form-text text-muted">Min 0 days</small>
                            </x-adminlte-input>
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[analytics_report_email]" label="Report Email" type="email"
                                value="{{ old('settings.analytics_report_email', $settings['analytics_report_email'] ?? '') }}">
                                <small class="form-text text-muted">Valid email address</small>
                            </x-adminlte-input>
                        </div>
                    </div>
                    <details class="mt-3">
                        <summary class="small text-muted" style="cursor:pointer;">Advanced — Analytics toggles</summary>
                        <div class="row mt-2">
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[analytics_enabled]" label="Analytics Enabled">
                                    <option value="yes" @selected(($settings['analytics_enabled'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['analytics_enabled'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[analytics_track_admin]" label="Track Admin">
                                    <option value="yes" @selected(($settings['analytics_track_admin'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['analytics_track_admin'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[analytics_export_enabled]" label="Export Enabled">
                                    <option value="yes" @selected(($settings['analytics_export_enabled'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['analytics_export_enabled'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[analytics_anonymize_ip]" label="Anonymize IP">
                                    <option value="yes" @selected(($settings['analytics_anonymize_ip'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['analytics_anonymize_ip'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[analytics_event_tracking]" label="Event Tracking">
                                    <option value="yes" @selected(($settings['analytics_event_tracking'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['analytics_event_tracking'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[analytics_privacy_consent]" label="Privacy Consent">
                                    <option value="yes" @selected(($settings['analytics_privacy_consent'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['analytics_privacy_consent'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[analytics_daily_report]" label="Daily Report">
                                    <option value="yes" @selected(($settings['analytics_daily_report'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['analytics_daily_report'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[analytics_weekly_report]" label="Weekly Report">
                                    <option value="yes" @selected(($settings['analytics_weekly_report'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['analytics_weekly_report'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <x-adminlte-input name="settings[analytics_dashboard_widgets]" label="Dashboard Widgets"
                                    value="{{ old('settings.analytics_dashboard_widgets', $settings['analytics_dashboard_widgets'] ?? '') }}" />
                                <small class="form-text text-muted">Comma-separated widget IDs</small>
                            </div>
                        </div>
                    </details>
                </x-adminlte-card>
                @php $lu = $lastUpdated['analytics'] ?? $lastUpdated['all'] ?? null; @endphp
                <small class="text-muted d-block mb-2 last-updated" data-section="analytics">@if($lu)Last updated: {{ \Illuminate\Support\Carbon::parse($lu->created_at)->format('Y-m-d H:i:s') }} — <span title="{{ $lu->description }}">{{ \Illuminate\Support\Str::limit($lu->description, 120) }}</span>@else Last updated: never @endif</small>
            </div>

            {{-- Automation --}}
            <div class="tab-pane fade @if ($activeTab === 'automation') show active @endif" id="pane-automation" role="tabpanel" aria-labelledby="tab-automation">
                <x-adminlte-card icon="bi bi-robot" title="Automation Settings">
                    <div class="row">
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[automation_default_workflow]" label="Default Workflow"
                                value="{{ old('settings.automation_default_workflow', $settings['automation_default_workflow'] ?? '') }}" />
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[automation_auto_close_ticket_days]" label="Auto-close Tickets After (days)" type="number" min="0"
                                value="{{ old('settings.automation_auto_close_ticket_days', $settings['automation_auto_close_ticket_days'] ?? '5') }}">
                                <small class="form-text text-muted">Min 0 days</small>
                            </x-adminlte-input>
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[automation_invoice_reminder_days]" label="Invoice Reminder (days)" type="number" min="0"
                                value="{{ old('settings.automation_invoice_reminder_days', $settings['automation_invoice_reminder_days'] ?? '3') }}">
                                <small class="form-text text-muted">Min 0 days</small>
                            </x-adminlte-input>
                        </div>
                    </div>
                    <details class="mt-3">
                        <summary class="small text-muted" style="cursor:pointer;">Advanced — Automation workflows</summary>
                        <div class="row mt-2">
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[automation_workflows_enabled]" label="Workflows Enabled">
                                    <option value="yes" @selected(($settings['automation_workflows_enabled'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['automation_workflows_enabled'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[automation_auto_close_tickets]" label="Auto Close Tickets">
                                    <option value="yes" @selected(($settings['automation_auto_close_tickets'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['automation_auto_close_tickets'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[automation_welcome_email]" label="Welcome Email">
                                    <option value="yes" @selected(($settings['automation_welcome_email'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['automation_welcome_email'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[automation_invoice_reminders]" label="Invoice Reminders">
                                    <option value="yes" @selected(($settings['automation_invoice_reminders'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['automation_invoice_reminders'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[automation_overdue_actions]" label="Overdue Actions">
                                    <option value="yes" @selected(($settings['automation_overdue_actions'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['automation_overdue_actions'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-input name="settings[automation_suspend_after_due_days]" label="Suspend After Due (days)" type="number" min="0"
                                    value="{{ old('settings.automation_suspend_after_due_days', $settings['automation_suspend_after_due_days'] ?? '7') }}">
                                    <small class="form-text text-muted">Min 0 days</small>
                                </x-adminlte-input>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-input name="settings[automation_terminate_after_due_days]" label="Terminate After Due (days)" type="number" min="0"
                                    value="{{ old('settings.automation_terminate_after_due_days', $settings['automation_terminate_after_due_days'] ?? '30') }}">
                                    <small class="form-text text-muted">Min 0 days</small>
                                </x-adminlte-input>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[automation_domain_expiry_notices]" label="Domain Expiry Notices">
                                    <option value="yes" @selected(($settings['automation_domain_expiry_notices'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['automation_domain_expiry_notices'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-3">
                                <x-adminlte-input name="settings[automation_domain_expiry_reminder_days]" label="Domain Expiry Reminder (days)" type="number" min="0" max="365"
                                    value="{{ old('settings.automation_domain_expiry_reminder_days', $settings['automation_domain_expiry_reminder_days'] ?? '30') }}">
                                    <small class="form-text text-muted">0 – 365 days</small>
                                </x-adminlte-input>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[automation_renewal_invoices]" label="Renewal Invoices">
                                    <option value="yes" @selected(($settings['automation_renewal_invoices'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['automation_renewal_invoices'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                        </div>
                    </details>
                </x-adminlte-card>
                @php $lu = $lastUpdated['automation'] ?? $lastUpdated['all'] ?? null; @endphp
                <small class="text-muted d-block mb-2 last-updated" data-section="automation">@if($lu)Last updated: {{ \Illuminate\Support\Carbon::parse($lu->created_at)->format('Y-m-d H:i:s') }} — <span title="{{ $lu->description }}">{{ \Illuminate\Support\Str::limit($lu->description, 120) }}</span>@else Last updated: never @endif</small>
            </div>

            {{-- Cron --}}
            <div class="tab-pane fade @if ($activeTab === 'cron') show active @endif" id="pane-cron" role="tabpanel" aria-labelledby="tab-cron">
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
                            <x-adminlte-input name="settings[cron_log_cleanup_days]" label="Log Cleanup After (days)" type="number" min="0"
                                value="{{ old('settings.cron_log_cleanup_days', $settings['cron_log_cleanup_days'] ?? '30') }}">
                                <small class="form-text text-muted">Min 0 days</small>
                            </x-adminlte-input>
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[cron_notify_email]" label="Failure Notify Email" type="email"
                                value="{{ old('settings.cron_notify_email', $settings['cron_notify_email'] ?? '') }}">
                                <small class="form-text text-muted">Valid email address</small>
                            </x-adminlte-input>
                        </div>
                    </div>
                    <details class="mt-3">
                        <summary class="small text-muted" style="cursor:pointer;">Advanced — Cron scheduler</summary>
                        <div class="row mt-2">
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[cron_scheduler_enabled]" label="Scheduler Enabled">
                                    <option value="yes" @selected(($settings['cron_scheduler_enabled'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['cron_scheduler_enabled'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[cron_heartbeat_enabled]" label="Heartbeat Enabled">
                                    <option value="yes" @selected(($settings['cron_heartbeat_enabled'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['cron_heartbeat_enabled'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-input name="settings[cron_pricing_sync]" label="Pricing Sync"
                                    value="{{ old('settings.cron_pricing_sync', $settings['cron_pricing_sync'] ?? 'daily') }}" />
                                <small class="form-text text-muted">e.g., daily / hourly</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-input name="settings[cron_report_generation]" label="Report Generation"
                                    value="{{ old('settings.cron_report_generation', $settings['cron_report_generation'] ?? 'daily') }}" />
                                <small class="form-text text-muted">e.g., daily</small>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-3">
                                <x-adminlte-input name="settings[cron_lock_timeout_minutes]" label="Lock Timeout (minutes)" type="number" min="1"
                                    value="{{ old('settings.cron_lock_timeout_minutes', $settings['cron_lock_timeout_minutes'] ?? '60') }}">
                                    <small class="form-text text-muted">Min 1 minute</small>
                                </x-adminlte-input>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-input name="settings[cron_max_runtime_minutes]" label="Max Runtime (minutes)" type="number" min="1"
                                    value="{{ old('settings.cron_max_runtime_minutes', $settings['cron_max_runtime_minutes'] ?? '30') }}">
                                    <small class="form-text text-muted">Min 1 minute</small>
                                </x-adminlte-input>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[cron_notify_on_failure]" label="Notify On Failure">
                                    <option value="yes" @selected(($settings['cron_notify_on_failure'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['cron_notify_on_failure'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                        </div>
                    </details>
                </x-adminlte-card>
                @php $lu = $lastUpdated['cron'] ?? $lastUpdated['all'] ?? null; @endphp
                <small class="text-muted d-block mb-2 last-updated" data-section="cron">@if($lu)Last updated: {{ \Illuminate\Support\Carbon::parse($lu->created_at)->format('Y-m-d H:i:s') }} — <span title="{{ $lu->description }}">{{ \Illuminate\Support\Str::limit($lu->description, 120) }}</span>@else Last updated: never @endif</small>
            </div>

            {{-- Role --}}
            <div class="tab-pane fade @if ($activeTab === 'role') show active @endif" id="pane-role" role="tabpanel" aria-labelledby="tab-role">
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
                    <details class="mt-3">
                        <summary class="small text-muted" style="cursor:pointer;">Advanced — Role flags</summary>
                        <div class="row mt-2">
                            <div class="col-md-4">
                                <x-adminlte-select name="settings[role_allow_assignment]" label="Allow Assignment">
                                    <option value="yes" @selected(($settings['role_allow_assignment'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['role_allow_assignment'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-4">
                                <x-adminlte-select name="settings[role_show_permissions]" label="Show Permissions">
                                    <option value="yes" @selected(($settings['role_show_permissions'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['role_show_permissions'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-4">
                                <x-adminlte-select name="settings[role_protect_system_roles]" label="Protect System Roles">
                                    <option value="yes" @selected(($settings['role_protect_system_roles'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['role_protect_system_roles'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                        </div>
                    </details>
                </x-adminlte-card>
                @php $lu = $lastUpdated['role'] ?? $lastUpdated['all'] ?? null; @endphp
                <small class="text-muted d-block mb-2 last-updated" data-section="role">@if($lu)Last updated: {{ \Illuminate\Support\Carbon::parse($lu->created_at)->format('Y-m-d H:i:s') }} — <span title="{{ $lu->description }}">{{ \Illuminate\Support\Str::limit($lu->description, 120) }}</span>@else Last updated: never @endif</small>
            </div>

            {{-- User --}}
            <div class="tab-pane fade @if ($activeTab === 'user') show active @endif" id="pane-user" role="tabpanel" aria-labelledby="tab-user">
                <x-adminlte-card icon="bi bi-people" title="User Settings">
                    <div class="row">
                        <div class="col-md-4">
                            @php $userTzCurrent = old('settings.user_default_timezone', $settings['user_default_timezone'] ?? 'Asia/Kolkata'); @endphp
                            <x-adminlte-select name="settings[user_default_timezone]" label="Default Timezone">
                                @foreach ($timezonesGrouped as $tzRegion => $tzZoneList)
                                    <optgroup label="{{ $tzRegion }}">
                                        @foreach ($tzZoneList as $tzZone)
                                            <option value="{{ $tzZone }}" @selected($tzZone === $userTzCurrent)>{{ $tzZone }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </x-adminlte-select>
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[user_session_timeout_minutes]" label="Session Timeout (minutes)" type="number" min="1"
                                value="{{ old('settings.user_session_timeout_minutes', $settings['user_session_timeout_minutes'] ?? '120') }}">
                                <small class="form-text text-muted">Min 1 minute</small>
                            </x-adminlte-input>
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="settings[user_max_login_attempts]" label="Max Login Attempts" type="number" min="1"
                                value="{{ old('settings.user_max_login_attempts', $settings['user_max_login_attempts'] ?? '5') }}">
                                <small class="form-text text-muted">Min 1</small>
                            </x-adminlte-input>
                        </div>
                    </div>
                    <details class="mt-3">
                        <summary class="small text-muted" style="cursor:pointer;">Advanced — User account</summary>
                        <div class="row mt-2">
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[user_email_verification]" label="Email Verification">
                                    <option value="yes" @selected(($settings['user_email_verification'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['user_email_verification'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[user_allow_social_login]" label="Allow Social Login">
                                    <option value="yes" @selected(($settings['user_allow_social_login'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['user_allow_social_login'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[user_profile_editable]" label="Profile Editable">
                                    <option value="yes" @selected(($settings['user_profile_editable'] ?? 'yes') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['user_profile_editable'] ?? 'yes') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[user_allow_self_delete]" label="Allow Self Delete">
                                    <option value="yes" @selected(($settings['user_allow_self_delete'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['user_allow_self_delete'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-3">
                                <x-adminlte-input name="settings[user_password_expiry_days]" label="Password Expiry (days)" type="number" min="0"
                                    value="{{ old('settings.user_password_expiry_days', $settings['user_password_expiry_days'] ?? '0') }}">
                                    <small class="form-text text-muted">Min 0 (0 = never)</small>
                                </x-adminlte-input>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-select name="settings[user_two_factor_enforced]" label="2FA Enforced">
                                    <option value="yes" @selected(($settings['user_two_factor_enforced'] ?? 'no') === 'yes')>Yes</option>
                                    <option value="no" @selected(($settings['user_two_factor_enforced'] ?? 'no') === 'no')>No</option>
                                </x-adminlte-select>
                                <small class="form-text text-muted">Yes / No</small>
                            </div>
                            <div class="col-md-3">
                                <x-adminlte-input name="settings[user_inactive_lock_days]" label="Inactive Lock (days)" type="number" min="0"
                                    value="{{ old('settings.user_inactive_lock_days', $settings['user_inactive_lock_days'] ?? '0') }}">
                                    <small class="form-text text-muted">Min 0 days</small>
                                </x-adminlte-input>
                            </div>
                        </div>
                    </details>
                </x-adminlte-card>
                @php $lu = $lastUpdated['user'] ?? $lastUpdated['all'] ?? null; @endphp
                <small class="text-muted d-block mb-2 last-updated" data-section="user">@if($lu)Last updated: {{ \Illuminate\Support\Carbon::parse($lu->created_at)->format('Y-m-d H:i:s') }} — <span title="{{ $lu->description }}">{{ \Illuminate\Support\Str::limit($lu->description, 120) }}</span>@else Last updated: never @endif</small>
            </div>
                </div>{{-- /.tab-content --}}
            </div>{{-- /.col content --}}
        </div>{{-- /#settings-layout row --}}
    </form>

    {{-- Target for the Email tab's Send Test Email control (form= attribute).
         Kept outside #settings-form because HTML forbids nested forms; posting it
         normally is the no-JS fallback (redirect + flash), while the script below
         upgrades it to an inline fetch so unsaved edits survive the test. --}}
    <form method="POST" action="{{ route('admin.settings.test-email') }}" id="test-email-form" class="d-none">
        @csrf
    </form>

    @push('css')
        <style>
            /* Gap between cards within a tab pane */
            .tab-content .card + .card,
            .tab-content .card + details,
            .tab-content details + .card {
                margin-top: 1rem;
            }

            /* Sidebar layout */
            .settings-sidebar {
                border-right: 1px solid var(--bs-border-color, #dee2e6);
            }
            .settings-nav .nav-link {
                padding: 0.35rem 0.75rem;
                font-size: 0.875rem;
                color: var(--bs-body-color);
                border-radius: 0.375rem;
            }
            .settings-nav .nav-link.active {
                background-color: var(--bs-primary);
                color: #fff;
            }
            .settings-nav .nav-link:hover:not(.active) {
                background-color: var(--bs-tertiary-bg, #f8f9fa);
            }
            .settings-nav-group-label {
                display: block;
                font-size: 0.68rem;
                font-weight: 600;
                letter-spacing: 0.07em;
                text-transform: uppercase;
                color: var(--bs-secondary-color, #6c757d);
                padding: 0.5rem 0.75rem 0.15rem;
            }
            .settings-nav-group:not(:first-child) {
                margin-top: 0.5rem;
            }
            /* Keyboard focus indicator on active pane */
            .tab-pane:focus-visible {
                outline: 2px solid rgba(13, 110, 253, 0.5);
                outline-offset: 2px;
            }
            /* Responsive: stack sidebar above content on small screens */
            @media (max-width: 991.98px) {
                .settings-sidebar {
                    border-right: none;
                    border-bottom: 1px solid var(--bs-border-color, #dee2e6);
                    margin-bottom: 1rem;
                    max-height: none !important;
                    overflow-y: visible !important;
                    position: static !important;
                }
                #settings-tabs-nav {
                    flex-direction: row !important;
                    flex-wrap: wrap;
                    gap: 0.25rem;
                }
                .settings-nav-group-label {
                    display: none;
                }
                .col-lg-9.ps-lg-4 {
                    padding-left: 0 !important;
                }
            }
        </style>
    @endpush

    @push('js')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var activeTabInput = document.getElementById('active-tab-input');
                var storageKey = 'settings-active-tab';
                var hasErrors = {{ $errors->any() ? 'true' : 'false' }};
                var syncTab = function (name) {
                    if (activeTabInput) activeTabInput.value = name;
                    try { sessionStorage.setItem(storageKey, name); } catch (e) {}
                    var url = new URL(window.location.href);
                    url.searchParams.set('tab', name);
                    history.replaceState(null, '', url);
                };
                document.querySelectorAll('[data-bs-toggle="tab"]').forEach(function (tab) {
                    tab.addEventListener('shown.bs.tab', function () {
                        var target = tab.getAttribute('data-bs-target') || '';
                        var name = target.replace('#pane-', '');
                        if (name) syncTab(name);
                    });
                });
                if (!hasErrors) {
                    var params = new URLSearchParams(window.location.search);
                    var tabParam = params.get('tab');
                    if (tabParam) {
                        if (activeTabInput) activeTabInput.value = tabParam;
                        try { sessionStorage.setItem(storageKey, tabParam); } catch (e) {}
                    } else {
                        var stored = null;
                        try { stored = sessionStorage.getItem(storageKey); } catch (e) {}
                        if (stored) {
                            var btn = document.getElementById('tab-' + stored);
                            if (btn) {
                                if (window.bootstrap && window.bootstrap.Tab) {
                                    window.bootstrap.Tab.getOrCreateInstance(btn).show();
                                } else {
                                    btn.click();
                                }
                                if (activeTabInput) activeTabInput.value = stored;
                                var url2 = new URL(window.location.href);
                                url2.searchParams.set('tab', stored);
                                history.replaceState(null, '', url2);
                            }
                        }
                    }
                }

                // -- Keyboard navigation between tabs --
                // Owned end-to-end here because neither bundled library fully implements
                // the WAI-ARIA tabs pattern for this markup:
                //  - Bootstrap 5.3 only auto-instantiates Tab (which carries its keydown
                //    handler) on the .active button (EVENT_LOAD_DATA_API), so arrows
                //    pressed on inactive buttons are invisible to it;
                //  - AdminLTE adds a global .nav arrow handler that MOVES FOCUS ONLY,
                //    never activating the tab.
                // A window-capture keydown (capture runs window -> document -> ...) with
                // a strict "#settings-tabs-nav role=tab" guard therefore pre-empts both:
                // ArrowLeft/ArrowRight cycle (wrapping), Home/End jump first/last, the
                // focused button is activated via the standard data-api click path, and
                // focus then moves to the newly active pane (tabindex="-1") so screen
                // readers announce the context. Arrows pressed while focus sits on the
                // pane container itself (never inside an input) keep cycling. The pane
                // focus is debounced (600ms intent window, timer reset per switch) so
                // rapid presses land focus on the FINAL pane.
                var kbTabsNav = document.getElementById('settings-tabs-nav');
                if (kbTabsNav) {
                    var kbTabButtons = Array.prototype.slice.call(kbTabsNav.querySelectorAll('[role="tab"]'));
                    var kbLastKeyAt = 0;
                    var kbPaneFocusTimer = null;

                    var kbSyncTabState = function (activeBtn) {
                        kbTabButtons.forEach(function (b) {
                            var isActive = b === activeBtn;
                            b.setAttribute('tabindex', isActive ? '0' : '-1');
                            b.setAttribute('aria-selected', isActive ? 'true' : 'false');
                        });
                    };

                    var kbFocusActivePane = function (btn) {
                        var pane = document.getElementById((btn.getAttribute('aria-controls') || '').replace(/^#/, ''));
                        if (!pane) return;
                        pane.setAttribute('tabindex', '-1');
                        pane.focus();
                    };

                    var kbActivate = function (btn) {
                        if (!btn) return;
                        kbSyncTabState(btn);
                        btn.focus();
                        // Data-api click is the reliable activation path here (the bundle
                        // does not expose window.bootstrap; click constructs the Tab
                        // instance on demand and honours ?tab=/storage/error flows).
                        btn.click();
                    };

                    // Capture phase on window pre-empts AdminLTE's document-level .nav
                    // handler (focus-only) and Bootstrap's per-instance handlers.
                    window.addEventListener('keydown', function (e) {
                        var current = document.activeElement;
                        if (!current || current.getAttribute('role') !== 'tab' || !kbTabsNav.contains(current)) return;
                        var idx = kbTabButtons.indexOf(current);
                        if (idx === -1) return;
                        var target = null;
                        if (e.key === 'ArrowDown') target = kbTabButtons[(idx + 1) % kbTabButtons.length];
                        else if (e.key === 'ArrowUp') target = kbTabButtons[(idx - 1 + kbTabButtons.length) % kbTabButtons.length];
                        else if (e.key === 'Home') target = kbTabButtons[0];
                        else if (e.key === 'End') target = kbTabButtons[kbTabButtons.length - 1];
                        if (!target) return;
                        e.preventDefault();
                        e.stopPropagation();
                        kbLastKeyAt = Date.now();
                        kbActivate(target);
                    }, true);

                    // Sync state whatever triggered the switch (click, search auto-switch,
                    // error-summary link, restore) and honour the keyboard promise: focus
                    // lands on the newly active pane shortly after the fade completes.
                    kbTabsNav.addEventListener('shown.bs.tab', function (e) {
                        var btn = e.target;
                        if (!(btn && btn.getAttribute && btn.getAttribute('role') === 'tab')) return;
                        kbSyncTabState(btn);
                        if (Date.now() - kbLastKeyAt < 600) {
                            if (kbPaneFocusTimer) window.clearTimeout(kbPaneFocusTimer);
                            kbPaneFocusTimer = window.setTimeout(function () {
                                kbPaneFocusTimer = null;
                                kbFocusActivePane(btn);
                            }, 180);
                        }
                    });
                }

                // -- Live client-side search (debounced 120ms, client-only) --
                var searchInput = document.getElementById('settings-search');
                var searchCount = document.getElementById('search-count');
                var searchClear = document.getElementById('settings-search-clear');
                var searchNoMatches = document.getElementById('search-no-matches');
                var tabsNav = document.getElementById('settings-tabs-nav');
                if (searchInput && tabsNav) {
                    var escapeHtml = function (s) {
                        return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                    };
                    var escapeRegExp = function (s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); };
                    var fieldNodes = document.querySelectorAll('[name^="settings["]');
                    var fields = [];
                    fieldNodes.forEach(function (inp) {
                        var name = inp.getAttribute('name') || '';
                        var m = name.match(/^settings\[([^\]]+)\]$/);
                        var rawKey = m ? m[1] : name;
                        var wrapper = inp.closest('[class*="col-"]') || inp.closest('.form-group') || inp.parentElement;
                        if (!wrapper) return;
                        var labelEl = wrapper.querySelector('label');
                        if (!labelEl) {
                            var alt = wrapper.parentElement ? wrapper.parentElement.querySelector('label[for="' + (inp.id || '') + '"]') : null;
                            if (alt) labelEl = alt;
                        }
                        var labelText = labelEl ? (labelEl.textContent || '').trim() : '';
                        if (labelEl && !labelEl.dataset.originalLabel) {
                            labelEl.dataset.originalLabel = labelText;
                        }
                        var pane = wrapper.closest('.tab-pane');
                        var paneId = pane ? pane.id : '';
                        // Fix 5: skip fields not inside a tab pane — they can't be counted or shown
                        if (!paneId) return;
                        fields.push({ input: inp, wrapper: wrapper, labelEl: labelEl, rawKey: rawKey, labelText: labelText, paneId: paneId });
                    });
                    var tabPanes = document.querySelectorAll('.tab-pane');
                    // Fix 1: track nav items and group labels separately
                    var navItems = tabsNav.querySelectorAll('.nav-item:not(.settings-nav-group)');
                    var navGroups = tabsNav.querySelectorAll('.settings-nav-group');
                    var debounceTimer = null;

                    // Fix 3: hide Clear button until input has text
                    if (searchClear) searchClear.classList.add('d-none');

                    var clearHighlights = function () {
                        fields.forEach(function (f) {
                            if (f.labelEl && f.labelEl.dataset.originalLabel !== undefined) {
                                f.labelEl.textContent = f.labelEl.dataset.originalLabel;
                            }
                        });
                    };

                    // Fix 4: escape the query the same way as the label before building the regex,
                    // so special chars like & < > " match correctly in the HTML-escaped label string.
                    var applyHighlight = function (f, qRaw) {
                        if (!f.labelEl || !f.labelEl.dataset.originalLabel) return;
                        var orig = f.labelEl.dataset.originalLabel;
                        var escapedQ = escapeHtml(qRaw);
                        var regex = new RegExp('(' + escapeRegExp(escapedQ) + ')', 'gi');
                        f.labelEl.innerHTML = escapeHtml(orig).replace(regex, '<mark>$1</mark>');
                    };

                    // Fix 1: restore group label visibility on reset
                    var resetSearch = function () {
                        clearHighlights();
                        fields.forEach(function (f) { f.wrapper.style.display = ''; });
                        document.querySelectorAll('.tab-pane .card').forEach(function (c) { c.style.display = ''; });
                        document.querySelectorAll('.tab-pane details').forEach(function (d) { d.style.display = ''; d.open = false; });
                        navItems.forEach(function (li) { li.style.display = ''; });
                        navGroups.forEach(function (li) { li.style.display = ''; });
                        if (searchCount) { searchCount.textContent = ''; searchCount.classList.add('d-none'); }
                        if (searchNoMatches) searchNoMatches.classList.add('d-none');
                    };

                    var doSearch = function () {
                        var qRaw = searchInput.value.trim();
                        var q = qRaw.toLowerCase();

                        // Fix 3: show/hide Clear button based on whether input has text
                        if (searchClear) {
                            if (q === '') searchClear.classList.add('d-none');
                            else searchClear.classList.remove('d-none');
                        }

                        if (q === '') {
                            resetSearch();
                            return;
                        }
                        var totalMatches = 0;
                        var perPaneCount = {};
                        tabPanes.forEach(function (p) { perPaneCount[p.id] = 0; });
                        clearHighlights();
                        fields.forEach(function (f) {
                            var labelLower = (f.labelText || '').toLowerCase();
                            var keyLower = (f.rawKey || '').toLowerCase();
                            var isMatch = labelLower.indexOf(q) !== -1 || keyLower.indexOf(q) !== -1;
                            if (isMatch) {
                                f.wrapper.style.display = '';
                                applyHighlight(f, qRaw);
                                totalMatches++;
                                perPaneCount[f.paneId] = (perPaneCount[f.paneId] || 0) + 1;
                                var det = f.wrapper.closest('details');
                                if (det) { det.style.display = ''; det.open = true; }
                            } else {
                                f.wrapper.style.display = 'none';
                            }
                        });
                        document.querySelectorAll('.tab-pane').forEach(function (pane) {
                            var cards = pane.querySelectorAll('.card');
                            cards.forEach(function (card) {
                                var visibleInCard = 0;
                                fields.forEach(function (f) {
                                    if (f.wrapper.closest('.card') === card && f.wrapper.style.display !== 'none') visibleInCard++;
                                });
                                card.style.display = visibleInCard === 0 ? 'none' : '';
                            });
                            pane.querySelectorAll('details').forEach(function (det) {
                                var visibleInDetails = 0;
                                fields.forEach(function (f) {
                                    if (f.wrapper.closest('details') === det && f.wrapper.style.display !== 'none') visibleInDetails++;
                                });
                                if (visibleInDetails === 0 && det.querySelectorAll('[name^="settings["]').length > 0) {
                                    det.style.display = 'none';
                                } else {
                                    det.style.display = '';
                                }
                            });
                        });
                        var firstMatchPaneId = null;
                        tabPanes.forEach(function (pane) {
                            var count = perPaneCount[pane.id] || 0;
                            var tabId = pane.id.replace('pane-', 'tab-');
                            var btn = document.getElementById(tabId);
                            var li = btn ? btn.closest('.nav-item') : null;
                            if (li) li.style.display = count === 0 ? 'none' : '';
                            if (count > 0 && firstMatchPaneId === null) firstMatchPaneId = pane.id;
                        });
                        // Fix 1: hide group headings whose every child nav item is now hidden
                        navGroups.forEach(function (groupLi) {
                            var next = groupLi.nextElementSibling;
                            var hasVisible = false;
                            while (next && !next.classList.contains('settings-nav-group')) {
                                if (next.style.display !== 'none') { hasVisible = true; break; }
                                next = next.nextElementSibling;
                            }
                            groupLi.style.display = hasVisible ? '' : 'none';
                        });
                        if (searchCount) {
                            searchCount.textContent = totalMatches + (totalMatches === 1 ? ' match' : ' matches');
                            searchCount.classList.remove('d-none');
                        }
                        if (searchNoMatches) {
                            if (totalMatches === 0) searchNoMatches.classList.remove('d-none');
                            else searchNoMatches.classList.add('d-none');
                        }
                        // Fix 2: only switch tab if the current active tab has no matches
                        if (totalMatches > 0 && firstMatchPaneId) {
                            var activePane = document.querySelector('.tab-pane.show.active');
                            var activeId = activePane ? activePane.id : '';
                            var activeHasMatches = activeId && (perPaneCount[activeId] || 0) > 0;
                            if (!activeHasMatches) {
                                var firstBtn = document.getElementById(firstMatchPaneId.replace('pane-', 'tab-'));
                                if (firstBtn) {
                                    if (window.bootstrap && window.bootstrap.Tab) {
                                        window.bootstrap.Tab.getOrCreateInstance(firstBtn).show();
                                    } else {
                                        firstBtn.click();
                                    }
                                }
                            }
                        }
                    };

                    var scheduleSearch = function () {
                        clearTimeout(debounceTimer);
                        debounceTimer = setTimeout(doSearch, 120);
                    };

                    searchInput.addEventListener('input', scheduleSearch);
                    if (searchClear) {
                        searchClear.addEventListener('click', function () {
                            searchInput.value = '';
                            clearTimeout(debounceTimer);
                            resetSearch();
                            searchClear.classList.add('d-none');
                            searchInput.focus();
                        });
                    }
                }

                // -- Encrypted-field UX: reveal toggle shows masked dots only (client-only, no plaintext) --
                // 4 encrypted keys keep value="" placeholder "Leave blank to keep current"; Reveal toggles ••••••••
                // JS never puts plaintext into DOM; blank submit keeps old value via controller unset guard.
                // old() not repopulated: raw inputs use value="" without resolvedValue(old()), so validation error stays blank.
                var ENCRYPTED_MASK = '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022';
                var encryptedRevealBtns = document.querySelectorAll('.encrypted-reveal-btn');
                encryptedRevealBtns.forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var targetId = btn.getAttribute('data-target');
                        var input = document.getElementById(targetId);
                        if (!input) return;
                        var isMasked = input.getAttribute('data-masked') === 'true';
                        if (isMasked) {
                            input.value = '';
                            input.removeAttribute('data-masked');
                            input.readOnly = false;
                            btn.textContent = 'Reveal';
                            btn.setAttribute('aria-pressed', 'false');
                        } else {
                            input.value = ENCRYPTED_MASK;
                            input.setAttribute('data-masked', 'true');
                            input.readOnly = true;
                            btn.textContent = 'Hide';
                            btn.setAttribute('aria-pressed', 'true');
                        }
                    });
                });
                // -- Error summary tab linking: click switches tab, opens details, focuses field --
                var errorSummary = document.getElementById('settings-error-summary');
                if (errorSummary) {
                    errorSummary.querySelectorAll('.error-summary-link').forEach(function (link) {
                        link.addEventListener('click', function (e) {
                            e.preventDefault();
                            var tab = link.getAttribute('data-tab');
                            var field = link.getAttribute('data-field');
                            var rawKey = link.getAttribute('data-raw-key');
                            var tabBtn = document.getElementById('tab-' + tab);
                            if (tabBtn) {
                                if (window.bootstrap && window.bootstrap.Tab) {
                                    window.bootstrap.Tab.getOrCreateInstance(tabBtn).show();
                                } else {
                                    tabBtn.click();
                                }
                            }
                            var fieldName = 'settings[' + rawKey + ']';
                            var targetInput = document.querySelector('[name="' + fieldName + '"]') || document.getElementById(rawKey);
                            if (!targetInput && field) {
                                var dotId = field.replace('settings.', '');
                                targetInput = document.getElementById(dotId);
                            }
                            if (targetInput) {
                                var det = targetInput.closest('details');
                                if (det) det.open = true;
                                setTimeout(function () {
                                    targetInput.focus();
                                    if (targetInput.scrollIntoView) {
                                        targetInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                    }
                                    targetInput.classList.add('is-invalid');
                                }, 160);
                            }
                            if (tab && activeTabInput) activeTabInput.value = tab;
                            try { history.replaceState(null, '', '#pane-' + tab); } catch (err) {}
                        });
                    });
                    (function autoOpenFirstError() {
                        var firstLink = errorSummary.querySelector('.error-summary-link');
                        if (!firstLink) return;
                        var rawKey = firstLink.getAttribute('data-raw-key');
                        var fieldName = 'settings[' + rawKey + ']';
                        var inp = document.querySelector('[name="' + fieldName + '"]') || document.getElementById(rawKey);
                        if (inp) {
                            var d = inp.closest('details');
                            if (d) d.open = true;
                        }
                    })();
                }

                // -- Single Save All submit wiring --
                // Per-tab Save buttons were removed (user request): the one
                // #save-all-btn posts the whole form natively. The submit
                // handler only clears masked dots before serialization (blank
                // keeps the stored secret — controller unsets '') and syncs the
                // hidden active_tab to the visible pane so ?tab= survives the
                // redirect. Server-side keyToSection scoping remains as
                // defense-in-depth for scoped payloads.
                var settingsForm = document.getElementById('settings-form');
                if (settingsForm) {
                    settingsForm.addEventListener('submit', function () {
                        if (activeTabInput) {
                            var activePane = document.querySelector('.tab-pane.show.active');
                            var activeId = activePane ? activePane.id.replace('pane-', '') : activeTabInput.value;
                            if (activeId) activeTabInput.value = activeId;
                        }
                        encryptedRevealBtns.forEach(function (btn) {
                            var input = document.getElementById(btn.getAttribute('data-target'));
                            if (input && input.value === ENCRYPTED_MASK) {
                                input.value = '';
                                input.removeAttribute('data-masked');
                                input.readOnly = false;
                            }
                        });
                        // Native submit proceeds with all 178 keys + save_all=1.
                    });
                }
                // -- Dirty tracking and beforeunload guard --
                // Snapshot initialValues = new FormData(form) on load; mark tab badge • Dirty and enable Save;
                // beforeunload "You have unsaved changes" if dirty; after POST success (302 + flash) reset snapshot for saved keys only (per-tab resets that tab, Save All resets all), not fire after success; revert clears dirty.
                var dirtyForm = settingsForm || document.getElementById('settings-form');
                var initialValues = dirtyForm ? new FormData(dirtyForm) : null;
                var isSubmittingDirty = false;
                var hasSuccessFlash = !!document.getElementById('settings-save-toast') || !!document.getElementById('settings-success-alert');
                // Inject • Dirty badges into each tab button (hidden by default)
                if (tabsNav) {
                    tabsNav.querySelectorAll('.nav-link').forEach(function(btn){
                        if (!btn.querySelector('.dirty-badge')) {
                            var badge = document.createElement('span');
                            badge.className = 'dirty-badge badge text-bg-warning ms-1 d-none';
                            badge.textContent = '• Dirty';
                            badge.setAttribute('data-dirty-badge', btn.id || '');
                            badge.setAttribute('aria-label', 'Unsaved changes');
                            btn.appendChild(badge);
                        }
                    });
                }
                var getDirtyFieldValue = function(inp){
                    if (!inp) return '';
                    if (inp.type === 'checkbox') return inp.checked ? inp.value : '';
                    if (inp.type === 'radio') return inp.checked ? inp.value : '';
                    var v = inp.value;
                    if (v === ENCRYPTED_MASK) return '';
                    return v == null ? '' : String(v);
                };
                var isPaneDirty = function(pane){
                    if (!pane || !initialValues) return false;
                    var inputs = pane.querySelectorAll('[name^="settings["]');
                    for (var i=0;i<inputs.length;i++){
                        var inp = inputs[i];
                        if (inp.disabled) continue;
                        var name = inp.getAttribute('name');
                        if (!name) continue;
                        var cur = getDirtyFieldValue(inp);
                        var init = initialValues.get(name);
                        if (init === null || init === undefined) init = '';
                        else init = String(init);
                        if (cur !== init) return true;
                    }
                    return false;
                };
                var hasAnyDirty = function(){
                    if (!dirtyForm || !initialValues) return false;
                    var panes = document.querySelectorAll('.tab-pane');
                    for (var p=0;p<panes.length;p++){
                        if (isPaneDirty(panes[p])) return true;
                    }
                    return false;
                };
                var updateDirtyUI = function(){
                    if (!dirtyForm || !initialValues) return;
                    var anyDirty = false;
                    document.querySelectorAll('.tab-pane').forEach(function(pane){
                        var tabId = pane.id.replace('pane-','');
                        var tabBtn = document.getElementById('tab-' + tabId);
                        var badge = tabBtn ? tabBtn.querySelector('.dirty-badge') : null;
                        var dirty = isPaneDirty(pane);
                        if (dirty) anyDirty = true;
                        if (badge) {
                            if (dirty) badge.classList.remove('d-none');
                            else badge.classList.add('d-none');
                        }
                    });
                    var saveAll = document.getElementById('save-all-btn');
                    if (saveAll) {
                        saveAll.disabled = !anyDirty;
                        if (anyDirty) saveAll.classList.remove('disabled');
                        else saveAll.classList.add('disabled');
                    }
                };
                // After POST success (302 + flash) reset snapshot for saved keys only (per-tab resets that tab, Save All resets all), not fire after success
                if (hasSuccessFlash && dirtyForm && initialValues) {
                    var toastBodyEl = document.getElementById('settings-save-toast-body');
                    var successMsg = (toastBodyEl ? toastBodyEl.textContent : '') + ' ' + ((document.getElementById('settings-success-alert') ? document.getElementById('settings-success-alert').textContent : '') || '');
                    var isSaveAllSuccess = successMsg.indexOf('All settings saved') !== -1;
                    if (isSaveAllSuccess) {
                        initialValues = new FormData(dirtyForm);
                    } else {
                        var savedTab = null;
                        try { savedTab = new URLSearchParams(window.location.search).get('tab'); } catch(e) {}
                        if (savedTab) {
                            var savedPane = document.getElementById('pane-' + savedTab);
                            if (savedPane) {
                                var curFd = new FormData(dirtyForm);
                                savedPane.querySelectorAll('[name^="settings["]').forEach(function(inp){
                                    var name = inp.getAttribute('name');
                                    if (!name) return;
                                    var cur = curFd.get(name);
                                    if (cur === null) cur = getDirtyFieldValue(inp);
                                    if (cur === ENCRYPTED_MASK) cur = '';
                                    initialValues.set(name, cur == null ? '' : String(cur));
                                });
                            }
                        } else {
                            // fallback: if we cannot determine tab, treat as Save All to avoid stale dirty
                            initialValues = new FormData(dirtyForm);
                        }
                    }
                }
                // Initial UI state (badges hidden, saves disabled if clean)
                updateDirtyUI();
                // On input change mark tab badge • Dirty and enable Save; revert clears dirty
                if (dirtyForm) {
                    var dirtyInputs = dirtyForm.querySelectorAll('[name^="settings["]');
                    dirtyInputs.forEach(function(inp){
                        inp.addEventListener('input', function(){ updateDirtyUI(); });
                        inp.addEventListener('change', function(){ updateDirtyUI(); });
                    });
                    // Also handle encrypted reveal inputs (their value change should not mark dirty when masked)
                    // beforeunload show "You have unsaved changes" if any dirty
                    window.addEventListener('beforeunload', function(e){
                        if (isSubmittingDirty) return;
                        if (hasAnyDirty()) {
                            e.preventDefault();
                            e.returnValue = 'You have unsaved changes';
                            return 'You have unsaved changes';
                        }
                    });
                    // Mark submitting so beforeunload does NOT fire after successful save navigation
                    dirtyForm.addEventListener('submit', function(){
                        isSubmittingDirty = true;
                    });
                    // Set the flag synchronously on Save All click (beforeunload
                    // fires before the submit handler can).
                    document.querySelectorAll('#save-all-btn').forEach(function(btn){
                        btn.addEventListener('click', function(){
                            isSubmittingDirty = true;
                            setTimeout(function(){ isSubmittingDirty = false; }, 2500);
                        });
                    });
                    // Re-check after tab switch (hidden inputs become visible)
                    document.querySelectorAll('[data-bs-toggle="tab"]').forEach(function(tab){
                        tab.addEventListener('shown.bs.tab', function(){ updateDirtyUI(); });
                    });
                }

                // -- Send Test Email (Email tab) --
                // Intercepted rather than left as a plain submit: navigating away
                // would discard unsaved edits on the other 17 tabs and trip the
                // beforeunload guard, so the result is rendered inline instead.
                var testEmailForm = document.getElementById('test-email-form');
                var testEmailBtn = document.getElementById('test-email-btn');
                var testEmailInput = document.getElementById('test-email-input');
                var testEmailResult = document.getElementById('test-email-result');
                if (testEmailForm && testEmailBtn && testEmailInput && testEmailResult && window.fetch) {
                    var showTestEmailResult = function (ok, message) {
                        testEmailResult.className = 'alert mt-4 mb-0 ' + (ok ? 'alert-success' : 'alert-danger');
                        testEmailResult.textContent = message;
                    };
                    testEmailForm.addEventListener('submit', function (e) {
                        e.preventDefault();
                        var address = testEmailInput.value.trim();
                        if (!address) {
                            showTestEmailResult(false, 'Enter an email address to send the test to.');
                            testEmailInput.focus();
                            return;
                        }
                        var tokenInput = testEmailForm.querySelector('input[name="_token"]');
                        var originalLabel = testEmailBtn.innerHTML;
                        testEmailBtn.disabled = true;
                        testEmailBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Sending…';
                        testEmailResult.className = 'alert alert-info mt-4 mb-0';
                        testEmailResult.textContent = 'Sending test email…';

                        var payload = new FormData();
                        payload.append('test_email', address);
                        if (tokenInput) payload.append('_token', tokenInput.value);

                        fetch(testEmailForm.action, {
                            method: 'POST',
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            credentials: 'same-origin',
                            body: payload
                        }).then(function (response) {
                            return response.json().catch(function () { return {}; }).then(function (data) {
                                return { status: response.status, data: data || {} };
                            });
                        }).then(function (result) {
                            var data = result.data;
                            if (result.status === 422) {
                                var firstError = (data.errors && data.errors.test_email) ? data.errors.test_email[0] : (data.message || 'That email address is not valid.');
                                showTestEmailResult(false, firstError);
                                return;
                            }
                            if (result.status < 200 || result.status >= 300) {
                                showTestEmailResult(false, data.message || ('Test email failed (HTTP ' + result.status + ').'));
                                return;
                            }
                            showTestEmailResult(!!data.ok, data.message || (data.ok ? 'Test email sent.' : 'Test email failed.'));
                        }).catch(function (err) {
                            showTestEmailResult(false, 'Test email request failed: ' + ((err && err.message) ? err.message : 'network error'));
                        }).then(function () {
                            testEmailBtn.disabled = false;
                            testEmailBtn.innerHTML = originalLabel;
                        });
                    });
                }

                // Success toast per tab ("X saved" vs "All settings saved" from controller via AppSettings::keyToSection defense)
                var toastEl = document.getElementById('settings-save-toast');
                if (toastEl && window.bootstrap && window.bootstrap.Toast) {
                    var t = new window.bootstrap.Toast(toastEl, {delay: 3500});
                    t.show();
                    setTimeout(function(){
                        var alertEl = document.getElementById('settings-success-alert');
                        if(alertEl) alertEl.style.display='none';
                    }, 3600);
                    // silent after success — ensure guard not fired on immediate navigation after toast
                    isSubmittingDirty = false;
                    updateDirtyUI();
                }
            });


        </script>
    @endpush
@stop
