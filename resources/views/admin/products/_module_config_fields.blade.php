{{-- Module per-product config fields (shared by show + edit pages).

     Renders the schema fields of an active module with the product's saved
     config values. The caller supplies $schema and $cfg (already decrypted).
     Fields are rendered without a <form> wrapper so the include works inside
     the edit page's single update form: on the edit page the save button
     posts the fields via fetch (see the edit page JS), on the show page the
     fields sit inside a normal form. --}}
@php
    $prevSection = null;
@endphp
<div class="row g-3">
    @foreach ($schema['fields'] as $field)
        @php
            $key = $field['key'] ?? null;
        @endphp
        @if ($key === null)
            @continue
        @endif
        @php
            $type = $field['type'] ?? 'text';
            $label = $field['label'] ?? ucfirst((string) $key);
            $section = $field['section'] ?? null;
            $help = $field['help'] ?? null;
            $showIf = $field['show_if'] ?? null;
            $showIfKey = null;
            $showIfValue = null;
            if (is_array($showIf) && count($showIf) === 1 && ! isset($showIf['key'])) {
                $showIfKey = array_key_first($showIf);
                $showIfValue = $showIf[$showIfKey];
            } elseif (is_array($showIf) && isset($showIf['key'])) {
                $showIfKey = $showIf['key'];
                $showIfValue = $showIf['value'] ?? null;
            }
            $required = ! empty($field['required']) && $type !== 'checkbox';
            $isSecret = $type === 'password' || ! empty($field['encrypted']);
            $current = old('config.'.$key, $cfg[$key] ?? ($field['default'] ?? ''));
            // For encrypted/password fields never prefill the decrypted value into the HTML.
            $displayValue = $isSecret ? old('config.'.$key, '') : $current;
            $selectCurrent = (string) old('config.'.$key, $cfg[$key] ?? ($field['default'] ?? ''));
        @endphp

        @if ($section !== null && $section !== $prevSection)
            <div class="col-12 @if (!$loop->first) mt-2 @endif">
                <h6 class="text-uppercase text-muted fw-semibold border-bottom pb-2 mb-1" style="font-size:.75rem; letter-spacing:.06em;">{{ $section }}</h6>
            </div>
            @php $prevSection = $section; @endphp
        @endif

        @if (($field['type'] ?? 'text') === 'checkbox')
            <div class="col-md-6 field-group"
                 @if ($showIfKey !== null) data-show-if-key="{{ $showIfKey }}" data-show-if-value="{{ $showIfValue }}" @endif>
                <div class="form-check mt-2 mb-1">
                    <input class="form-check-input" type="checkbox"
                           name="config[{{ $key }}]" value="1"
                           id="config-{{ $key }}"
                           @checked(old('config.'.$key, ! empty($cfg[$key]) || (!array_key_exists($key, $cfg) && !empty($field['default']))))>
                    <label class="form-check-label" for="config-{{ $key }}">
                        {{ $label }}
                        @if (!empty($field['required'])) <span class="text-danger">*</span> @endif
                    </label>
                </div>
                @if (!empty($help))
                    <div class="form-text small text-muted">{{ $help }}</div>
                @endif
            </div>
        @elseif (($field['type'] ?? 'text') === 'select')
            <div class="col-md-6 field-group"
                 @if ($showIfKey !== null) data-show-if-key="{{ $showIfKey }}" data-show-if-value="{{ $showIfValue }}" @endif>
                <x-adminlte-select name="config[{{ $key }}]" id="config-{{ $key }}" :label="$label"
                                   :required="$required">
                    @foreach ($field['options'] ?? [] as $optionValue => $optionLabel)
                        <option value="{{ $optionValue }}" @selected($selectCurrent == (string) $optionValue)>{{ $optionLabel }}</option>
                    @endforeach
                </x-adminlte-select>
                @if (!empty($help))
                    <div class="form-text small text-muted mt-n2 mb-2">{{ $help }}</div>
                @endif
            </div>
        @elseif (($field['type'] ?? 'text') === 'textarea')
            <div class="col-md-6 field-group"
                 @if ($showIfKey !== null) data-show-if-key="{{ $showIfKey }}" data-show-if-value="{{ $showIfValue }}" @endif>
                <x-adminlte-textarea name="config[{{ $key }}]" id="config-{{ $key }}" :label="$label" rows="3"
                                     :required="$required">{{ $isSecret ? $displayValue : $current }}</x-adminlte-textarea>
                @if (!empty($help))
                    <div class="form-text small text-muted">{{ $help }}</div>
                @endif
            </div>
        @else
            <div class="col-md-6 field-group"
                 @if ($showIfKey !== null) data-show-if-key="{{ $showIfKey }}" data-show-if-value="{{ $showIfValue }}" @endif>
                @if ($isSecret)
                    <x-adminlte-input name="config[{{ $key }}]" id="config-{{ $key }}" :label="$label"
                                      value="{{ $displayValue }}"
                                      placeholder="Leave blank to keep current"
                                      :type="$type === 'password' ? 'password' : ($type === 'number' ? 'number' : 'text')" />
                @else
                    <x-adminlte-input name="config[{{ $key }}]" id="config-{{ $key }}" :label="$label"
                                      value="{{ $current }}"
                                      :required="$required"
                                      :type="$type === 'number' ? 'number' : 'text'" />
                @endif
                @if (!empty($help))
                    <div class="form-text small text-muted">{{ $help }}</div>
                @endif
            </div>
        @endif
    @endforeach
</div>

<script>
(function() {
    function initModuleConfigShowIf(root) {
        root = root || document;
        var groups = root.querySelectorAll('.field-group[data-show-if-key]');
        if (!groups.length) return;

        function getController(key) {
            return document.getElementById('config-' + key) || root.querySelector('[name="config[' + key + ']"]');
        }

        function controllerValue(el) {
            if (!el) return null;
            if (el.type === 'checkbox') {
                return el.checked ? el.value : '0';
            }
            return el.value;
        }

        function evaluate() {
            groups.forEach(function(g) {
                var k = g.getAttribute('data-show-if-key');
                var v = g.getAttribute('data-show-if-value');
                var ctrl = getController(k);
                if (!ctrl) return;
                var cur = controllerValue(ctrl);
                var show = String(cur) === String(v);
                g.classList.toggle('d-none', !show);
            });
        }

        var keys = Array.from(new Set(Array.from(groups).map(function(g){ return g.getAttribute('data-show-if-key'); })));
        keys.forEach(function(k){
            var ctrl = getController(k);
            if (ctrl) {
                ctrl.addEventListener('change', evaluate);
                ctrl.addEventListener('input', evaluate);
            }
        });

        evaluate();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function(){ initModuleConfigShowIf(document); });
    } else {
        initModuleConfigShowIf(document);
    }
})();
</script>
