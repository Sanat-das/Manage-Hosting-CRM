@php
    use ColorlibHQ\AdminLte\Menu\MenuItemHelper;

    // Flatten the sidebar menu into a searchable list of leaf links.
    $flatten = function (array $items, array $trail = []) use (&$flatten) {
        $out = [];
        foreach ($items as $item) {
            if (MenuItemHelper::isHeader($item)) {
                continue;
            }
            $text = $item['text'] ?? null;
            if (MenuItemHelper::isSubmenu($item)) {
                $childTrail = $text ? array_merge($trail, [$text]) : $trail;
                $out = array_merge($out, $flatten($item['submenu'], $childTrail));

                continue;
            }
            $href = $item['href'] ?? ($item['url'] ?? '#');
            if (! $text || $href === '#' || $href === '') {
                continue;
            }
            $out[] = [
                'text' => $text,
                'href' => $href,
                'icon' => $item['icon'] ?? 'bi bi-arrow-right-short',
                'group' => implode(' › ', $trail),
            ];
        }

        return $out;
    };

    $paletteItems = $flatten(app('adminlte')->menu('sidebar'));
@endphp

<div id="adminlteCommandPalette" class="adminlte-cmdk" role="dialog" aria-modal="true" aria-label="{{ __('adminlte.search') }}"
     data-typeahead-url="{{ route('admin.search.typeahead') }}"
     data-search-url="{{ route('admin.search.index') }}" hidden>
    <div class="adminlte-cmdk__backdrop" data-cmdk-close></div>
    <div class="adminlte-cmdk__dialog card shadow-lg">
        <div class="input-group input-group-lg border-bottom">
            <span class="input-group-text bg-transparent border-0"><i class="bi bi-search" aria-hidden="true"></i></span>
            <input type="text" id="adminlteCommandPaletteInput" class="form-control border-0 shadow-none"
                   placeholder="{{ __('adminlte.search') }}…" autocomplete="off" aria-label="{{ __('adminlte.search') }}"
                   role="combobox" aria-expanded="true" aria-controls="adminlteCommandPaletteResults" aria-autocomplete="list">
            <span class="input-group-text bg-transparent border-0">
                <kbd class="small bg-body-secondary text-body-secondary border rounded px-1">Esc</kbd>
            </span>
        </div>
        <ul id="adminlteCommandPaletteResults" class="list-group list-group-flush adminlte-cmdk__results" role="listbox"></ul>
        {{-- Visible loading/error note for the async Records fetch. aria-hidden
             because the live region below already announces the same text. --}}
        <div class="adminlte-cmdk__status small d-none" data-cmdk-status data-state="idle" aria-hidden="true"></div>
        <div class="adminlte-cmdk__empty text-muted small p-3 text-center d-none">{{ __('adminlte.no_results') }}</div>
        {{-- Result-count announcer: updated by the palette script on every render. --}}
        <p class="visually-hidden mb-0" role="status" aria-live="polite" data-cmdk-announce></p>
    </div>
</div>

@once
{{-- Styles are emitted inline here (not via @push('css')) because this partial
     renders in the <body>, after the head's @stack('css') has already output. --}}
<style>
    .adminlte-cmdk { position: fixed; inset: 0; z-index: 2050; display: flex; justify-content: center;
        align-items: flex-start; padding: 12vh 1rem 1rem; }
    .adminlte-cmdk[hidden] { display: none; }
    .adminlte-cmdk__backdrop { position: absolute; inset: 0; background: rgba(0,0,0,.5); backdrop-filter: blur(2px); }
    .adminlte-cmdk__dialog { position: relative; width: min(640px, 94vw); max-height: 70vh; overflow: hidden;
        display: flex; flex-direction: column; border-radius: var(--radius-lg); }
    .adminlte-cmdk__dialog:focus-within { box-shadow: var(--shadow-lg), 0 0 0 3px color-mix(in srgb, var(--color-focus-ring) 35%, transparent); }

    /* Scroll containment: the row list is the only scrollable region, so a long
       record set can never push the input out of the dialog. */
    .adminlte-cmdk__results { overflow-y: auto; max-height: min(56vh, 460px); overscroll-behavior: contain; scrollbar-gutter: stable; }

    /* Grouped record rows — spacing/type/colour from resources/css/tokens.css. */
    .adminlte-cmdk__group { font-size: var(--text-xs); font-weight: var(--font-weight-semibold); letter-spacing: var(--tracking-wide);
        text-transform: uppercase; color: var(--color-text-faint); padding: var(--space-2) var(--space-4) var(--space-1); }
    .adminlte-cmdk__results .list-group-item { cursor: pointer; display: flex; align-items: center; gap: var(--space-2);
        padding: var(--space-2) var(--space-4); font-size: var(--text-sm); color: var(--color-text);
        transition: background-color var(--duration-base) var(--ease-default), color var(--duration-base) var(--ease-default); }
    .adminlte-cmdk__results .list-group-item > i { flex-shrink: 0; color: var(--color-text-faint); font-size: var(--text-base); }
    .adminlte-cmdk__results .list-group-item small { margin-left: auto; opacity: .75; font-size: var(--text-xs); }
    /* Active row: tinted surface + inset accent keeps the label AA in both themes
       (a solid primary fill would fail AA on the dark theme's light primary). */
    .adminlte-cmdk__results .list-group-item.active { background-color: color-mix(in srgb, var(--color-primary) 12%, transparent);
        border-color: transparent; color: var(--color-text); box-shadow: inset 3px 0 0 var(--color-primary); }
    .adminlte-cmdk__results .list-group-item.active > i { color: var(--color-primary); }
    .adminlte-cmdk__results .list-group-item.active small { opacity: .9; }

    /* States: loading and error notes are distinct from the empty note. */
    .adminlte-cmdk__status { padding: var(--space-2) var(--space-4); border-top: 1px solid var(--color-border); }
    .adminlte-cmdk__status[data-state="loading"] { color: var(--color-info); }
    .adminlte-cmdk__status[data-state="error"] { color: var(--color-danger); }
    .adminlte-cmdk__empty { padding: var(--space-4); color: var(--color-text-muted); }

    /* Unified search pill in the navbar */
    .adminlte-search-trigger { --bs-btn-border-color: var(--bs-border-color); line-height: 1.6; }
    .adminlte-search-trigger:hover { background: var(--bs-tertiary-bg); }
    .adminlte-search-trigger kbd { background: var(--bs-tertiary-bg); color: var(--bs-secondary-color);
        font-family: inherit; font-size: .75rem; }
</style>

@push('js')
<script>
(function () {
    const items = @json($paletteItems);
    const root = document.getElementById('adminlteCommandPalette');
    if (!root) return;
    const input = document.getElementById('adminlteCommandPaletteInput');
    const list = document.getElementById('adminlteCommandPaletteResults');
    const empty = root.querySelector('.adminlte-cmdk__empty');
    const status = root.querySelector('[data-cmdk-status]');
    const announce = root.querySelector('[data-cmdk-announce]');
    const typeaheadUrl = root.dataset.typeaheadUrl;
    const searchUrl = root.dataset.searchUrl;
    let active = 0, filtered = [], options = [];

    // `opener` is the element that opened the palette, so focus can return to
    // it on close. `recordsState` is the only async state of the palette:
    // idle | loading | error (it drives the status note and the announcement).
    let opener = null, recordsState = 'idle';

    // Records state. `recordGroups` belongs to `recordsQuery` only: rows fetched
    // for one term are never rendered against another, so a slow response can
    // not put stale rows under a newer query.
    let recordGroups = null, recordsQuery = null, typeaheadTimer = null, typeaheadAbort = null;

    const announceText = () => {
        if (recordsState === 'loading') return 'Searching…';
        if (recordsState === 'error') return 'Records unavailable';
        if (filtered.length === 0) return 'No results';
        return filtered.length + (filtered.length === 1 ? ' result' : ' results');
    };
    const paintStatus = () => {
        const note = recordsState === 'loading' ? 'Searching…'
            : (recordsState === 'error' ? 'Records unavailable — showing navigation only' : '');
        if (status) {
            status.classList.toggle('d-none', note === '');
            status.dataset.state = recordsState;
            status.textContent = note;
        }
        if (announce) announce.textContent = announceText();
    };

    const render = () => {
        list.innerHTML = '';
        options = [];
        empty.classList.toggle('d-none', filtered.length > 0);
        let section = null;
        filtered.forEach((it, i) => {
            if (it.section && it.section !== section) {
                section = it.section;
                const header = document.createElement('li');
                header.className = 'adminlte-cmdk__group small text-uppercase text-muted px-3 pt-2 pb-1';
                header.setAttribute('role', 'presentation');
                header.textContent = section;
                list.appendChild(header);
            }
            const li = document.createElement('li');
            li.className = 'list-group-item list-group-item-action' + (i === active ? ' active' : '');
            li.id = 'adminlteCmdkOption' + i;
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', i === active ? 'true' : 'false');
            const icon = document.createElement('i');
            icon.className = it.icon;
            icon.setAttribute('aria-hidden', 'true');
            const text = document.createElement('span');
            text.textContent = it.text;
            li.append(icon, text);
            const note = it.group || it.subtitle;
            if (note) {
                const small = document.createElement('small');
                small.textContent = note;
                li.append(small);
            }
            li.addEventListener('click', () => go(i));
            li.addEventListener('mousemove', () => { active = i; paint(); });
            list.appendChild(li);
            options.push(li);
        });
        paint();
        paintStatus();
    };
    const paint = () => {
        options.forEach((li, i) => {
            li.classList.toggle('active', i === active);
            li.setAttribute('aria-selected', i === active ? 'true' : 'false');
        });
        const el = options[active];
        if (el) {
            el.scrollIntoView({ block: 'nearest' });
            input.setAttribute('aria-activedescendant', el.id);
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    };

    // Navigation rows keep their historical shape; server records append after
    // them under a per-group header, and the whole set stays one flat list so
    // Arrow/Enter navigation and aria-activedescendant treat every row alike.
    const buildRows = (raw) => {
        const lower = raw.toLowerCase();
        const rows = (lower ? items.filter(it =>
            it.text.toLowerCase().includes(lower) || (it.group && it.group.toLowerCase().includes(lower))
        ) : items.slice()).map(it => ({
            text: it.text, href: it.href, icon: it.icon, group: it.group,
        }));

        if (recordGroups && recordsQuery === raw) {
            let rendered = 0;
            recordGroups.forEach(group => {
                (group.results || []).forEach(result => {
                    rendered++;
                    rows.push({
                        text: result.label,
                        href: result.url,
                        icon: group.icon || 'bi bi-arrow-right-short',
                        subtitle: result.subtitle || '',
                        section: group.label,
                    });
                });
            });
            if (rendered > 0 && searchUrl) {
                rows.push({
                    text: 'View all results',
                    href: searchUrl + '?q=' + encodeURIComponent(raw),
                    icon: 'bi bi-search',
                    subtitle: '',
                });
            }
        }

        return rows;
    };
    const filter = (q) => {
        const raw = (q || '').trim();
        filtered = buildRows(raw);
        active = 0; render();
    };
    const go = (i) => {
        const it = filtered[i];
        if (it && it.href) window.location.href = it.href;
    };

    const resetRecords = () => {
        if (typeaheadAbort) { typeaheadAbort.abort(); typeaheadAbort = null; }
        if (typeaheadTimer) { clearTimeout(typeaheadTimer); typeaheadTimer = null; }
        recordGroups = null; recordsQuery = null; recordsState = 'idle';
    };
    const fetchRecords = (term) => {
        if (!typeaheadUrl) return;
        if (typeaheadAbort) typeaheadAbort.abort();
        typeaheadAbort = new AbortController();
        recordsState = 'loading'; paintStatus();

        fetch(typeaheadUrl + '?q=' + encodeURIComponent(term), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: typeaheadAbort.signal,
        }).then(response => {
            if (!response.ok) throw new Error('typeahead ' + response.status);
            return response.json();
        }).then(data => {
            if (!isOpen() || input.value.trim() !== term) return;
            recordGroups = (data && Array.isArray(data.groups)) ? data.groups : [];
            recordsQuery = term;
            recordsState = 'idle';
            filter(input.value);
        }).catch(error => {
            if (error && error.name === 'AbortError') return;
            // Silent fallback: 403, a dropped connection or malformed JSON
            // simply leaves the palette navigation-only, exactly as it was —
            // the status note makes that fallback visible without a toast.
            recordGroups = null; recordsQuery = null;
            recordsState = 'error';
            paintStatus();
        });
    };

    const open = () => {
        root.hidden = false;
        input.value = ''; resetRecords(); filter('');
        setTimeout(() => input.focus(), 20);
    };
    const close = () => {
        root.hidden = true; resetRecords();
        const back = opener; opener = null;
        if (back && document.contains(back) && typeof back.focus === 'function') back.focus();
    };
    const isOpen = () => !root.hidden;

    document.querySelectorAll('[data-adminlte-search]').forEach(el =>
        el.addEventListener('click', e => { e.preventDefault(); opener = e.currentTarget; open(); }));
    root.querySelectorAll('[data-cmdk-close]').forEach(el => el.addEventListener('click', close));

    input.addEventListener('input', () => {
        const term = input.value.trim();
        filter(input.value);

        if (typeaheadAbort) { typeaheadAbort.abort(); typeaheadAbort = null; }
        if (typeaheadTimer) { clearTimeout(typeaheadTimer); typeaheadTimer = null; }

        // A single character cannot match a record, so it never costs a request.
        if (term.length < 2) {
            recordGroups = null; recordsQuery = null;
            recordsState = 'idle'; paintStatus();
            return;
        }

        typeaheadTimer = setTimeout(() => fetchRecords(term), 250);
    });

    document.addEventListener('keydown', (e) => {
        if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
            // The chat workspace owns Ctrl/Cmd+K on /admin/chat*; whichever
            // listener runs first claims the keystroke and the other bails, so
            // exactly one overlay opens in either script order.
            if (window.__mhChatShortcuts || e.defaultPrevented) return;
            e.preventDefault();
            if (!isOpen() && document.activeElement !== document.body) opener = document.activeElement;
            isOpen() ? close() : open(); return;
        }
        if (!isOpen()) return;
        if (e.key === 'Escape') { e.preventDefault(); close(); }
        else if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, filtered.length - 1); paint(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); paint(); }
        else if (e.key === 'Enter') { e.preventDefault(); go(active); }
    });
})();
</script>
@endpush
@endonce
