@extends('adminlte::page')

@section('title', 'Module — ' . $module->name . ' Config')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">{{ $module->name }} — Configuration</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item">System</li>
                <li class="breadcrumb-item"><a href="{{ route('admin.modules.index') }}">Modules</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $module->name }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif
    @if (session('error'))
        <x-adminlte-alert theme="danger" dismissible>{{ session('error') }}</x-adminlte-alert>
    @endif

    @php
        $fields = $schema['fields'] ?? [];
        $prevSection = null;
    @endphp

    <x-adminlte-card icon="bi bi-sliders" title="{{ $module->name }} Configuration">
        @if (empty($fields))
            <p class="text-muted mb-0">This module has no configuration options.</p>
        @else
            <form method="POST" action="{{ route('admin.modules.config.update', $module) }}">
                @csrf
                @method('PUT')

                @foreach ($fields as $field)
                    @php
                        $key = $field['key'] ?? null;
                        $type = $field['type'] ?? 'text';
                        $label = $field['label'] ?? ucfirst((string) $key);
                        $required = !empty($field['required']) && $type !== 'checkbox';
                        $secret = $type === 'password' || !empty($field['encrypted']);
                        $current = old("config.{$key}", $config[$key] ?? $field['default'] ?? '');
                        $help = $field['help'] ?? null;
                        $section = $field['section'] ?? null;
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
                    @endphp

                    @if ($key === null)
                        @continue
                    @endif

                    @if ($section !== null && $section !== $prevSection)
                        <h6 class="text-uppercase text-muted fw-semibold border-bottom pb-2 mt-3 mb-3" style="font-size:.75rem; letter-spacing:.06em;">{{ $section }}</h6>
                        @php $prevSection = $section; @endphp
                    @endif

                    <div class="field-group" @if ($showIfKey !== null) data-show-if-key="{{ $showIfKey }}" data-show-if-value="{{ $showIfValue }}" @endif>
                    @if($type === 'textarea')
                            <x-adminlte-textarea name="config[{{ $key }}]" id="config-{{ $key }}" label="{{ $label }}" rows="3" :required="$required">{{ $secret ? '' : $current }}</x-adminlte-textarea>

                        @elseif($type === 'select')
                            <x-adminlte-select name="config[{{ $key }}]" id="config-{{ $key }}" label="{{ $label }}" :required="$required">
                                @foreach (($field['options'] ?? []) as $optionValue => $optionLabel)
                                    <option value="{{ $optionValue }}" @selected((string) $current === (string) $optionValue)>{{ $optionLabel }}</option>
                                @endforeach
                            </x-adminlte-select>

                        @elseif($type === 'checkbox')
                            <div class="mb-2 form-check">
                                <input type="checkbox" class="form-check-input" id="config-{{ $key }}"
                                       name="config[{{ $key }}]" value="1"
                                       @checked(!empty($current))>
                                <label class="form-check-label" for="config-{{ $key }}">{{ $label }}</label>
                            </div>

                        @elseif($type === 'password')
                            <x-adminlte-input name="config[{{ $key }}]" id="config-{{ $key }}" label="{{ $label }}" type="password"
                                value="" placeholder="Leave blank to keep current" />

                        @else
                            <x-adminlte-input name="config[{{ $key }}]" id="config-{{ $key }}" label="{{ $label }}"
                                type="{{ $type === 'number' ? 'number' : 'text' }}"
                                value="{{ $secret ? '' : $current }}"
                                placeholder="{{ $secret ? 'Leave blank to keep current' : '' }}"
                                :required="$required" />
                    @endif
                    @if (!empty($help))
                        <div class="form-text small text-muted @if($type !== 'checkbox') mt-n2 mb-3 @else mb-3 @endif">{{ $help }}</div>
                    @endif
                    </div>
                @endforeach

                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1" aria-hidden="true"></i> Save Configuration
                    </button>
                    <a href="{{ route('admin.modules.index') }}" class="btn btn-outline-secondary">Back to Modules</a>
                </div>
            </form>

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
                        if (el.type === 'checkbox') return el.checked ? el.value : '0';
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
        @endif
    </x-adminlte-card>
@stop
