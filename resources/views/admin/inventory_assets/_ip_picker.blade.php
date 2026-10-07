{{--
    Reusable IP Manager picker for the server / switch asset forms.

    Expects:
      $linkedIps       Collection<int, IpAddress>  currently linked addresses
      $ipTrackingTypes array<int, string>          asset types that show the picker

    IPs are never inventory assets; the picker only links existing
    ip_addresses rows via ip_addresses.inventory_asset_id. The block is hidden
    unless #asset_type is one of $ipTrackingTypes, but the chips stay in the DOM
    while hidden so a submission never silently drops links.
--}}
<div class="row" data-ip-picker data-ip-types="{{ implode(',', $ipTrackingTypes) }}" data-ip-search-url="{{ route('admin.ip-addresses.search') }}">
    <div class="col-12">
        <div class="mb-3">
            <label class="form-label" for="ip-picker-search">IP Addresses</label>
            <div class="border rounded p-2">
                <div class="d-flex flex-wrap gap-2 mb-2" data-ip-chips>
                    @foreach ($linkedIps as $ip)
                        <span class="badge text-bg-light border d-inline-flex align-items-center gap-1" data-ip-chip data-ip-id="{{ $ip->id }}">
                            <span>{{ $ip->ip_address.($ip->subnet ? ' — '.$ip->subnet->name : '').($ip->subnet?->vlan ? ' · '.(trim((string) $ip->subnet->vlan->name) !== '' ? $ip->subnet->vlan->name : 'VLAN '.$ip->subnet->vlan->vlan_id).' ('.$ip->subnet->vlan->vlan_id.')' : '') }}</span>
                            <button type="button" class="btn btn-sm btn-link text-danger p-1 lh-1" data-ip-chip-remove aria-label="Remove IP {{ $ip->ip_address }}"><i class="bi bi-x-lg"></i></button>
                            <input type="hidden" name="ip_address_ids[]" value="{{ $ip->id }}">
                        </span>
                    @endforeach
                </div>
                <div class="position-relative">
                    <input type="text" id="ip-picker-search" class="form-control form-control-sm" data-ip-search-input placeholder="Search IP, subnet, PTR…" autocomplete="off">
                    <div class="dropdown-menu border shadow rounded p-1" data-ip-search-dropdown role="listbox" aria-label="IP suggestions" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:1050; max-height:240px; overflow-y:auto;"></div>
                </div>
            </div>
            <div class="form-text text-muted" data-ip-search-status role="status" aria-live="polite"></div>
            <div class="form-text text-muted">Optional — link IPs managed in IP Manager.</div>
            <input type="hidden" name="ip_picker_present" value="1">
        </div>
    </div>
</div>

@push('js')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var containers = document.querySelectorAll('[data-ip-picker]');
            if (!containers.length) return;

            containers.forEach(function (container) {
                var input = container.querySelector('[data-ip-search-input]');
                var dropdown = container.querySelector('[data-ip-search-dropdown]');
                var chips = container.querySelector('[data-ip-chips]');
                var status = container.querySelector('[data-ip-search-status]');
                var searchUrl = container.getAttribute('data-ip-search-url');
                var typeSelect = document.querySelector('select[name="asset_type"]') || document.getElementById('asset_type');
                var allowedTypes = (container.getAttribute('data-ip-types') || '')
                    .split(',')
                    .map(function (value) { return value.trim(); })
                    .filter(Boolean);

                if (!input || !dropdown || !chips || !searchUrl) return;

                function syncVisibility() {
                    var value = typeSelect ? typeSelect.value : '';
                    var show = !typeSelect || allowedTypes.indexOf(value) !== -1;
                    container.style.display = show ? '' : 'none';
                }
                if (typeSelect) typeSelect.addEventListener('change', syncVisibility);
                syncVisibility();

                input.setAttribute('autocomplete', 'off');
                input.setAttribute('aria-autocomplete', 'list');
                input.setAttribute('aria-expanded', 'false');
                input.setAttribute('role', 'combobox');
                if (!dropdown.id) dropdown.id = 'ip-picker-listbox';
                input.setAttribute('aria-controls', dropdown.id);

                var debounceTimer = null;
                var abortController = null;
                var activeIndex = -1;
                var currentItems = [];

                function closeDropdown() {
                    dropdown.style.display = 'none';
                    dropdown.innerHTML = '';
                    input.setAttribute('aria-expanded', 'false');
                    activeIndex = -1;
                    currentItems = [];
                }

                function render(items) {
                    currentItems = items.slice(0, 8);
                    dropdown.innerHTML = '';
                    if (!currentItems.length) { closeDropdown(); return; }
                    currentItems.forEach(function (item, idx) {
                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'dropdown-item d-flex flex-column align-items-start py-2' + (idx === activeIndex ? ' active' : '');
                        btn.setAttribute('role', 'option');
                        btn.setAttribute('id', dropdown.id + '-opt-' + idx);
                        btn.setAttribute('aria-selected', idx === activeIndex ? 'true' : 'false');
                        if (idx === activeIndex) btn.setAttribute('aria-current', 'true');

                        var label = document.createElement('span');
                        label.className = 'fw-medium small text-truncate w-100';
                        label.textContent = item.label;
                        btn.appendChild(label);

                        if (item.meta) {
                            var meta = document.createElement('span');
                            meta.className = 'text-muted small text-truncate w-100';
                            meta.textContent = item.meta;
                            btn.appendChild(meta);
                        }

                        btn.addEventListener('mousedown', function (e) {
                            e.preventDefault();
                            addChip(item);
                        });
                        btn.addEventListener('click', function (e) {
                            e.preventDefault();
                            addChip(item);
                        });
                        dropdown.appendChild(btn);
                    });
                    dropdown.style.display = 'block';
                    input.setAttribute('aria-expanded', 'true');
                }

                function updateActive(newIndex) {
                    var buttons = dropdown.querySelectorAll('[role="option"]');
                    buttons.forEach(function (b, i) {
                        b.classList.toggle('active', i === newIndex);
                        b.setAttribute('aria-selected', i === newIndex ? 'true' : 'false');
                        if (i === newIndex) b.setAttribute('aria-current', 'true'); else b.removeAttribute('aria-current');
                    });
                    activeIndex = newIndex;
                    if (activeIndex >= 0 && buttons[activeIndex]) {
                        buttons[activeIndex].scrollIntoView({ block: 'nearest' });
                    }
                }

                function addChip(item) {
                    if (chips.querySelector('[data-ip-id="' + item.id + '"]')) {
                        input.value = '';
                        closeDropdown();
                        input.focus();
                        return;
                    }

                    var chip = document.createElement('span');
                    chip.className = 'badge text-bg-light border d-inline-flex align-items-center gap-1';
                    chip.setAttribute('data-ip-chip', '');
                    chip.setAttribute('data-ip-id', item.id);

                    var label = document.createElement('span');
                    label.textContent = item.label;
                    chip.appendChild(label);

                    var remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'btn btn-sm btn-link text-danger p-0 lh-1';
                    remove.setAttribute('data-ip-chip-remove', '');
                    remove.setAttribute('aria-label', 'Remove IP ' + item.label);
                    remove.innerHTML = '<i class="bi bi-x-lg"></i>';
                    chip.appendChild(remove);

                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'ip_address_ids[]';
                    hidden.value = item.id;
                    chip.appendChild(hidden);

                    chips.appendChild(chip);
                    input.value = '';
                    closeDropdown();
                    input.focus();
                }

                chips.addEventListener('click', function (e) {
                    var button = e.target.closest('[data-ip-chip-remove]');
                    if (!button) return;
                    e.preventDefault();
                    var chip = button.closest('[data-ip-chip]');
                    if (chip) chip.remove();
                });

                function fetchAndRender(query) {
                    if (abortController) abortController.abort();
                    abortController = new AbortController();
                    if (status) status.textContent = '';
                    var url = new URL(searchUrl, window.location.origin);
                    url.searchParams.set('q', query);
                    fetch(url.toString(), {
                        headers: { 'Accept': 'application/json' },
                        signal: abortController.signal
                    }).then(function (resp) {
                        if (!resp.ok) throw new Error('bad status');
                        return resp.json();
                    }).then(function (data) {
                        var items = (data && Array.isArray(data.results)) ? data.results : [];
                        if (status) status.textContent = items.length ? '' : 'No matching IP addresses.';
                        render(items);
                    }).catch(function (err) {
                        if (err && err.name === 'AbortError') return;
                        if (status) status.textContent = 'Search failed — try again.';
                        closeDropdown();
                    });
                }

                function debouncedFetch() {
                    var token = input.value.trim();
                    if (debounceTimer) clearTimeout(debounceTimer);
                    if (token.length < 1) { if (status) status.textContent = ''; closeDropdown(); return; }
                    debounceTimer = setTimeout(function () { fetchAndRender(token); }, 250);
                }

                input.addEventListener('input', debouncedFetch);

                input.addEventListener('keydown', function (e) {
                    var isOpen = dropdown.style.display !== 'none' && currentItems.length > 0;
                    if (!isOpen) {
                        if (e.key === 'ArrowDown') {
                            e.preventDefault();
                            debouncedFetch();
                        }
                        return;
                    }
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        var next = activeIndex + 1;
                        if (next >= currentItems.length) next = 0;
                        updateActive(next);
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        var prev = activeIndex - 1;
                        if (prev < 0) prev = currentItems.length - 1;
                        updateActive(prev);
                    } else if (e.key === 'Enter') {
                        e.preventDefault();
                        if (activeIndex >= 0 && activeIndex < currentItems.length) {
                            addChip(currentItems[activeIndex]);
                        } else {
                            closeDropdown();
                        }
                    } else if (e.key === 'Escape') {
                        e.preventDefault();
                        closeDropdown();
                    }
                });

                document.addEventListener('click', function (e) {
                    if (!container.contains(e.target)) closeDropdown();
                });
            });
        });
    </script>
@endpush
