{{--
    Per-product template restriction card, shared by Hyper-V and Proxmox VE.

    Hyper-V identifies templates by name, Proxmox VE by VMID — the difference is
    absorbed by normalising the caller's list into {value, label, meta} entries,
    so the markup, the mode radios and the save flow exist once.

    Expects:
      $prefix      'hyperv' | 'proxmox'  (drives every id/class + the JS hook)
      $title       card heading, e.g. "Hyper-V templates (this product)"
      $options     list<array{value: string, label: string, meta: string|null}>
      $allowed     list<string> currently allowed values
      $saveUrl     endpoint for the merge-save PUT
      $needsLink   true when the product has no module link yet
      $linkHint    message shown when $needsLink
      $emptyHint   message shown when nothing is curated anywhere
--}}
@php
    $options = $options ?? [];
    $allowed = $allowed ?? [];
    $needsLink = $needsLink ?? false;
    $isRestricted = ! empty($allowed);
@endphp

<div class="border rounded p-3 mb-3" id="{{ $prefix }}-templates-card" data-template-restriction data-prefix="{{ $prefix }}">
    <h6 class="mb-2"><i class="bi bi-hdd-stack me-1"></i> {{ $title }}</h6>

    @if ($needsLink)
        <p class="text-muted small mb-0">{{ $linkHint }}</p>
    @else
        <div class="mb-2">
            <div class="form-check">
                <input class="form-check-input" type="radio" name="{{ $prefix }}_template_mode" id="{{ $prefix }}-mode-all" value="all" {{ ! $isRestricted ? 'checked' : '' }}>
                <label class="form-check-label" for="{{ $prefix }}-mode-all">All curated templates</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="{{ $prefix }}_template_mode" id="{{ $prefix }}-mode-restrict" value="restrict" {{ $isRestricted ? 'checked' : '' }}>
                <label class="form-check-label" for="{{ $prefix }}-mode-restrict">Restrict to selected</label>
            </div>
        </div>

        @if (empty($options))
            <p class="text-muted small mb-0">{{ $emptyHint }}</p>
        @else
            <div id="{{ $prefix }}-templates-checkboxes" class="border rounded p-2 mb-2" style="max-height: 220px; overflow-y: auto;">
                @foreach ($options as $opt)
                    @php $isChecked = in_array($opt['value'], $allowed, true); @endphp
                    <div class="form-check">
                        <input class="form-check-input {{ $prefix }}-template-checkbox" type="checkbox" value="{{ $opt['value'] }}" id="{{ $prefix }}-tpl-{{ $loop->index }}" {{ $isChecked ? 'checked' : '' }}>
                        <label class="form-check-label" for="{{ $prefix }}-tpl-{{ $loop->index }}">
                            {{ $opt['label'] }}
                            <span class="text-muted small">({{ $opt['value'] }}{{ ! empty($opt['meta']) ? ' · '.$opt['meta'] : '' }})</span>
                        </label>
                    </div>
                @endforeach
            </div>
            <div id="{{ $prefix }}-templates-error" class="text-danger small mb-2" style="display:none;"></div>
        @endif

        <button type="button" class="btn btn-sm btn-primary" id="{{ $prefix }}-templates-save" data-url="{{ $saveUrl }}">
            <i class="bi bi-save me-1"></i> Save template restriction
        </button>
    @endif
</div>
