{{--
    Product-level default template picker for a compute module (Proxmox VE,
    Virtualizor; Hyper-V has no product-level template field).

    Expects:
      $slug          module slug (drives ids + the save URL already built)
      $name          module display name
      $templateKey   config key the value is stored under (osid, template_vmid)
      $templateLabel human label for the select
      $options       list<array{id: string, label: string}> union across active servers
      $current       currently saved value ('' = server default)
      $saveUrl       merge-save PUT endpoint
--}}
@php
    $cardId = 'compute-default-'.preg_replace('/[^a-z0-9_-]/i', '-', (string) $slug);
@endphp
<div class="border rounded p-3 mb-3" id="{{ $cardId }}" data-compute-default>
    <h6 class="mb-2"><i class="bi bi-hdd-stack me-1"></i> {{ $name }} default template</h6>
    <p class="text-muted small mb-2">
        Used when a service starts without a template picked at build time. Only templates curated on active
        servers are listed; leaving it empty falls back to the server's own default.
    </p>

    @if (empty($options))
        <p class="text-muted small mb-2">
            No templates are curated on active {{ $name }} servers yet — curate them on the server's edit page first.
        </p>
    @endif

    <div class="row g-2 align-items-end">
        <div class="col-md-6">
            <label class="form-label small mb-1" for="{{ $cardId }}-select">{{ $templateLabel }}</label>
            <select id="{{ $cardId }}-select" class="form-select form-select-sm" data-compute-default-select>
                <option value="">— Server default —</option>
                @foreach ($options as $opt)
                    <option value="{{ $opt['id'] }}" @selected((string) $current === (string) $opt['id'])>
                        {{ $opt['label'] }} ({{ $opt['id'] }})
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-md-auto">
            <button type="button" class="btn btn-sm btn-primary"
                    data-compute-default-save data-url="{{ $saveUrl }}">
                <i class="bi bi-save me-1"></i> Save default
            </button>
        </div>
        <div class="col-12">
            <div class="text-danger small d-none" data-compute-default-error></div>
        </div>
    </div>
</div>

@once
<script>
document.addEventListener('DOMContentLoaded', function () {
    var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || null;

    document.querySelectorAll('[data-compute-default]').forEach(function (card) {
        var select = card.querySelector('[data-compute-default-select]');
        var button = card.querySelector('[data-compute-default-save]');
        var errorBox = card.querySelector('[data-compute-default-error]');
        if (!select || !button) return;

        button.addEventListener('click', function () {
            if (button.disabled) return;
            button.disabled = true;
            if (errorBox) { errorBox.classList.add('d-none'); errorBox.textContent = ''; }

            var headers = { 'Accept': 'application/json' };
            if (csrf) headers['X-CSRF-TOKEN'] = csrf;

            fetch(button.getAttribute('data-url'), {
                method: 'PUT',
                headers: headers,
                body: new URLSearchParams({ template: select.value }),
                credentials: 'same-origin'
            }).then(function (res) {
                if (res.ok) { window.location.reload(); return null; }
                return res.json().catch(function () { return null; }).then(function (data) {
                    var msg = 'Could not save the default template.';
                    if (data && data.errors && data.errors.template && data.errors.template[0]) {
                        msg = data.errors.template[0];
                    } else if (data && data.message) {
                        msg = data.message;
                    }
                    throw new Error(msg);
                });
            }).catch(function (err) {
                if (errorBox) {
                    errorBox.textContent = (err && err.message) ? err.message : 'Network error.';
                    errorBox.classList.remove('d-none');
                }
            }).then(function () {
                button.disabled = false;
            });
        });
    });
});
</script>
@endonce
