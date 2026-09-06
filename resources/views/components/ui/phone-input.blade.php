@props([
    'name' => 'phone',
    'label' => 'Phone Number',
    'value' => null,
    'required' => false,
    'placeholder' => '98007 44827',
    'id' => null,
])

@php
    $id = $id ?? $name.'_'.uniqid();
    $rawValue = old($name, $value);
    $rawValue = $rawValue ?? '';
    // Parse existing phone like "+91 98765 43210" or "+1 2025550123"
    $selectedDial = '+91';
    $numberPart = '';
    if (preg_match('/^\s*(\+\d{1,4})\s*(.*)\s*$/', trim((string) $rawValue), $m)) {
        $selectedDial = $m[1];
        $numberPart = trim($m[2]);
    } elseif (trim((string) $rawValue) !== '') {
        // no code, treat whole as number and keep default dial
        $numberPart = trim((string) $rawValue);
        // if number already starts with 0, keep as is
    }
    // Allow old split fields to override
    $oldDial = old($name.'_code');
    $oldNumber = old($name.'_number');
    if ($oldDial) $selectedDial = $oldDial;
    if ($oldNumber !== null) $numberPart = $oldNumber;

    $countries = [
        ['code' => 'IN', 'name' => 'India', 'native' => 'भारत', 'dial' => '+91', 'flag' => '🇮🇳'],
        ['code' => 'US', 'name' => 'United States', 'native' => '', 'dial' => '+1', 'flag' => '🇺🇸'],
        ['code' => 'GB', 'name' => 'United Kingdom', 'native' => '', 'dial' => '+44', 'flag' => '🇬🇧'],
        ['code' => 'AF', 'name' => 'Afghanistan', 'native' => 'افغانستان', 'dial' => '+93', 'flag' => '🇦🇫'],
        ['code' => 'AL', 'name' => 'Albania', 'native' => 'Shqipëri', 'dial' => '+355', 'flag' => '🇦🇱'],
        ['code' => 'DZ', 'name' => 'Algeria', 'native' => 'الجزائر', 'dial' => '+213', 'flag' => '🇩🇿'],
        ['code' => 'AU', 'name' => 'Australia', 'native' => '', 'dial' => '+61', 'flag' => '🇦🇺'],
        ['code' => 'BD', 'name' => 'Bangladesh', 'native' => 'বাংলাদেশ', 'dial' => '+880', 'flag' => '🇧🇩'],
        ['code' => 'BH', 'name' => 'Bahrain', 'native' => 'البحرين', 'dial' => '+973', 'flag' => '🇧🇭'],
        ['code' => 'CA', 'name' => 'Canada', 'native' => '', 'dial' => '+1', 'flag' => '🇨🇦'],
        ['code' => 'CN', 'name' => 'China', 'native' => '中国', 'dial' => '+86', 'flag' => '🇨🇳'],
        ['code' => 'FR', 'name' => 'France', 'native' => '', 'dial' => '+33', 'flag' => '🇫🇷'],
        ['code' => 'DE', 'name' => 'Germany', 'native' => 'Deutschland', 'dial' => '+49', 'flag' => '🇩🇪'],
        ['code' => 'HK', 'name' => 'Hong Kong', 'native' => '香港', 'dial' => '+852', 'flag' => '🇭🇰'],
        ['code' => 'ID', 'name' => 'Indonesia', 'native' => '', 'dial' => '+62', 'flag' => '🇮🇩'],
        ['code' => 'JP', 'name' => 'Japan', 'native' => '日本', 'dial' => '+81', 'flag' => '🇯🇵'],
        ['code' => 'MY', 'name' => 'Malaysia', 'native' => '', 'dial' => '+60', 'flag' => '🇲🇾'],
        ['code' => 'NP', 'name' => 'Nepal', 'native' => 'नेपाल', 'dial' => '+977', 'flag' => '🇳🇵'],
        ['code' => 'PK', 'name' => 'Pakistan', 'native' => 'پاکستان', 'dial' => '+92', 'flag' => '🇵🇰'],
        ['code' => 'PH', 'name' => 'Philippines', 'native' => '', 'dial' => '+63', 'flag' => '🇵🇭'],
        ['code' => 'RU', 'name' => 'Russia', 'native' => 'Россия', 'dial' => '+7', 'flag' => '🇷🇺'],
        ['code' => 'SA', 'name' => 'Saudi Arabia', 'native' => 'المملكة العربية السعودية', 'dial' => '+966', 'flag' => '🇸🇦'],
        ['code' => 'SG', 'name' => 'Singapore', 'native' => '', 'dial' => '+65', 'flag' => '🇸🇬'],
        ['code' => 'LK', 'name' => 'Sri Lanka', 'native' => 'ශ්‍රී ලංකාව', 'dial' => '+94', 'flag' => '🇱🇰'],
        ['code' => 'AE', 'name' => 'United Arab Emirates', 'native' => 'الإمارات', 'dial' => '+971', 'flag' => '🇦🇪'],
        ['code' => 'NZ', 'name' => 'New Zealand', 'native' => '', 'dial' => '+64', 'flag' => '🇳🇿'],
        ['code' => 'ZA', 'name' => 'South Africa', 'native' => '', 'dial' => '+27', 'flag' => '🇿🇦'],
        ['code' => 'BR', 'name' => 'Brazil', 'native' => 'Brasil', 'dial' => '+55', 'flag' => '🇧🇷'],
        ['code' => 'NG', 'name' => 'Nigeria', 'native' => '', 'dial' => '+234', 'flag' => '🇳🇬'],
        ['code' => 'TR', 'name' => 'Turkey', 'native' => 'Türkiye', 'dial' => '+90', 'flag' => '🇹🇷'],
        ['code' => 'IT', 'name' => 'Italy', 'native' => 'Italia', 'dial' => '+39', 'flag' => '🇮🇹'],
        ['code' => 'ES', 'name' => 'Spain', 'native' => 'España', 'dial' => '+34', 'flag' => '🇪🇸'],
        ['code' => 'TH', 'name' => 'Thailand', 'native' => 'ประเทศไทย', 'dial' => '+66', 'flag' => '🇹🇭'],
        ['code' => 'VN', 'name' => 'Vietnam', 'native' => 'Việt Nam', 'dial' => '+84', 'flag' => '🇻🇳'],
        ['code' => 'KW', 'name' => 'Kuwait', 'native' => 'الكويت', 'dial' => '+965', 'flag' => '🇰🇼'],
        ['code' => 'QA', 'name' => 'Qatar', 'native' => 'قطر', 'dial' => '+974', 'flag' => '🇶🇦'],
        ['code' => 'OM', 'name' => 'Oman', 'native' => 'عُمان', 'dial' => '+968', 'flag' => '🇴🇲'],
        ['code' => 'KE', 'name' => 'Kenya', 'native' => '', 'dial' => '+254', 'flag' => '🇰🇪'],
    ];
    // Ensure selected dial exists in list, otherwise add it as custom
    $dials = array_column($countries, 'dial');
    if (!in_array($selectedDial, $dials, true)) {
        $countries[] = ['code' => 'OT', 'name' => 'Other', 'native' => '', 'dial' => $selectedDial, 'flag' => '🏳️'];
    }
    $error = $errors->first($name);
@endphp

<div class="mb-3" id="phone-field-{{ $id }}">
    <label for="{{ $id }}_number" class="form-label">
        {{ $label }}
        @if($required)<span class="text-danger">*</span>@endif
    </label>
    <div class="input-group phone-input-group @if($error) has-validation @endif" style="flex-wrap: nowrap;">
        <select id="{{ $id }}_code" name="{{ $name }}_code" class="form-select phone-code-select" style="max-width: 140px; flex: 0 0 140px; background-color: #fff; cursor: pointer; border-top-right-radius: 0; border-bottom-right-radius: 0;" aria-label="Country code">
            @foreach($countries as $c)
                <option value="{{ $c['dial'] }}" @selected($selectedDial === $c['dial']) data-flag="{{ $c['flag'] }}">
                    {{ $c['flag'] }} {{ $c['name'] }}@if($c['native']) ({{ $c['native'] }})@endif {{ $c['dial'] }}
                </option>
            @endforeach
        </select>
        <input type="tel" inputmode="numeric" autocomplete="tel" id="{{ $id }}_number" name="{{ $name }}_number" class="form-control @if($error) is-invalid @endif" value="{{ $numberPart }}" placeholder="{{ $placeholder }}" style="border-top-left-radius: 0; border-bottom-left-radius: 0; margin-left: -1px;" @if($required) required @endif>
        @if($error)<div class="invalid-feedback d-block">{{ $error }}</div>@endif
    </div>
    {{-- hidden combined value submitted as {{ $name }} for backend compatibility --}}
    <input type="hidden" name="{{ $name }}" id="{{ $id }}_hidden" value="{{ trim($selectedDial.' '.$numberPart) }}">
    <div class="form-text" style="font-size: 0.8em;">Select country flag and enter number without leading 0.</div>
</div>

@once
<script>
document.addEventListener('DOMContentLoaded', function() {
    function initPhoneFields() {
        document.querySelectorAll('[id^="phone-field-"]').forEach(function(wrapper){
            if (wrapper.dataset.inited) return;
            wrapper.dataset.inited = '1';
            const codeSel = wrapper.querySelector('select');
            const numInput = wrapper.querySelector('input[type="tel"]');
            const hidden = wrapper.querySelector('input[type="hidden"]');
            const form = wrapper.closest('form');
            if (!codeSel || !numInput || !hidden) return;
            function sync() {
                const code = (codeSel.value || '').trim();
                const num = (numInput.value || '').trim().replace(/\s+/g, ' ');
                hidden.value = num ? (code + ' ' + num) : code;
            }
            codeSel.addEventListener('change', sync);
            numInput.addEventListener('input', sync);
            if (form) form.addEventListener('submit', sync);
            sync();
        });
    }
    initPhoneFields();
    const obs = new MutationObserver(initPhoneFields);
    obs.observe(document.body, {childList: true, subtree: true});
});
</script>
@endonce

<style>
.phone-code-select {
    font-size: 0.9rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.phone-code-select option {
    font-size: 0.9rem;
}
.phone-input-group:focus-within {
    box-shadow: 0 0 0 0.25rem rgba(13,110,253,.25);
    border-radius: 0.375rem;
}
.phone-input-group:focus-within .form-select,
.phone-input-group:focus-within .form-control {
    border-color: #86b7fe;
    box-shadow: none;
}
</style>
