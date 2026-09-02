{{-- Full-page web SSH terminal for a hosting account (ssh-console module).
     Receives $hostingAccount, $sshConfig, $effectiveHost, $effectivePort,
     $effectiveUsername. Credentials are never rendered — the page streams an
     interactive phpseclib3 shell into xterm.js via NDJSON frames. --}}
@extends('adminlte::page')

@section('title', 'SSH Terminal — #'.$hostingAccount->id)

@section('content_header')
    <div class="row">
        <div class="col-sm-8">
            <h1 class="m-0">SSH Terminal</h1>
            <p class="text-muted mb-0 small">
                <i class="bi bi-terminal me-1"></i>
                {{ $hostingAccount->username }}
                @if($hostingAccount->domain) <span class="mx-1">·</span> {{ $hostingAccount->domain }} @endif
                <span class="mx-1">·</span> #{{ $hostingAccount->id }}
            </p>
        </div>
        <div class="col-sm-4">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.hosting.index') }}">Products/Services</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.hosting.show', $hostingAccount) }}">#{{ $hostingAccount->id }}</a></li>
                <li class="breadcrumb-item active">SSH</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
@php
    $hasHost = $effectiveHost !== null && trim((string) $effectiveHost) !== '';
    $isFromIp = $sshConfig?->host === null && $hasHost;
    $fullAddress = ($effectiveUsername !== null && trim((string) $effectiveUsername) !== '' ? $effectiveUsername.'@' : '').$effectiveHost.':'.$effectivePort;
@endphp

<div class="row g-3">
    <div class="col-12 col-xl-4">
        <x-adminlte-card title="Connection" icon="bi bi-terminal">
            @if (! $hasHost)
                <x-adminlte-alert theme="danger">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    No SSH host available. Configure an SSH host or assign an IP to this account, then reload.
                </x-adminlte-alert>
            @endif

            <table class="table table-sm table-borderless mb-3">
                <tbody>
                    <tr>
                        <th class="text-muted w-25">Target</th>
                        <td><code id="ssh-target">{{ $fullAddress }}</code></td>
                    </tr>
                    <tr>
                        <th class="text-muted">Host</th>
                        <td><code>{{ $effectiveHost ?? '—' }}</code> @if($isFromIp)<span class="text-muted small">(from assigned IP)</span>@endif</td>
                    </tr>
                    <tr>
                        <th class="text-muted">Port</th>
                        <td>{{ $effectivePort }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted">Username</th>
                        <td>@if($effectiveUsername)<code>{{ $effectiveUsername }}</code>@else <span class="text-muted">— configure in Edit SSH settings</span> @endif</td>
                    </tr>
                </tbody>
            </table>

            <div class="d-flex flex-wrap gap-2 mb-2">
                <button type="button" class="btn btn-primary btn-sm" id="ssh-connect" @if(! $hasHost || ! $effectiveUsername) disabled @endif>
                    <i class="bi bi-play-fill me-1"></i> Connect
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm" id="ssh-disconnect" disabled>
                    <i class="bi bi-x-circle me-1"></i> Close session
                </button>
                <a href="{{ route('admin.hosting.show', $hostingAccount) }}" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-pencil me-1"></i> Edit SSH settings
                </a>
            </div>

            <div class="form-check form-switch small text-muted">
                <input class="form-check-input" type="checkbox" id="ssh-autoreconnect">
                <label class="form-check-label" for="ssh-autoreconnect">Reconnect automatically on disconnect (max 3 attempts)</label>
            </div>

            <div class="text-muted small mt-2">
                The shell runs as the configured user on the VPS. Every session is audit-logged with your user and IP; keystrokes and output are never stored.
            </div>
        </x-adminlte-card>
    </div>

    <div class="col-12 col-xl-8">
        <div class="card" id="ssh-terminal-card">
            <div class="card-header d-flex justify-content-between align-items-center py-1 px-2">
                <span class="small fw-bold text-nowrap me-2"><i class="bi bi-terminal me-1"></i> Console</span>
                <div class="d-flex align-items-center gap-1 flex-wrap">
                    <button class="btn btn-sm btn-outline-secondary py-0 px-1" id="term-search-btn" title="Search (Ctrl+Shift+F)">
                        <i class="bi bi-search"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-secondary py-0 px-1" id="term-zoom-out" title="Zoom out (Ctrl+-)">
                        <span style="font-size:.7rem;font-weight:700;">A-</span>
                    </button>
                    <button class="btn btn-sm btn-outline-secondary py-0 px-1" id="term-zoom-in" title="Zoom in (Ctrl++)">
                        <span style="font-size:.7rem;font-weight:700;">A+</span>
                    </button>
                    <select class="form-select form-select-sm py-0" id="term-theme" style="width:auto;font-size:.75rem;" title="Terminal theme">
                        <option value="dark">Dark</option>
                        <option value="dracula">Dracula</option>
                        <option value="nord">Nord</option>
                        <option value="light">Light</option>
                    </select>
                    <button class="btn btn-sm btn-outline-secondary py-0 px-1" id="term-fullscreen" title="Fullscreen (F11)">
                        <i class="bi bi-fullscreen" id="term-fullscreen-icon"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-secondary py-0 px-1" id="term-transcript" title="Download transcript">
                        <i class="bi bi-download"></i>
                    </button>
                    <span class="badge text-bg-secondary ms-1" id="ssh-status">idle</span>
                </div>
            </div>
            <div id="term-search-bar" class="px-2 py-1 border-bottom d-none">
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control" id="term-search-input" placeholder="Search terminal…" autocomplete="off" spellcheck="false">
                    <span class="input-group-text text-muted" id="term-search-count" style="min-width:60px;font-size:.75rem;"></span>
                    <button class="btn btn-outline-secondary" id="term-search-prev" title="Previous (Shift+Enter)"><i class="bi bi-chevron-up"></i></button>
                    <button class="btn btn-outline-secondary" id="term-search-next" title="Next (Enter)"><i class="bi bi-chevron-down"></i></button>
                    <button class="btn btn-outline-secondary" id="term-search-close" title="Close (Escape)"><i class="bi bi-x-lg"></i></button>
                </div>
            </div>
            <div class="card-body p-0">
                <div id="terminal" style="background:#11151c;padding:2px 4px;height:70vh;"></div>
            </div>
        </div>
    </div>
</div>

{{-- Bootstrap toast container --}}
<div id="ssh-toast-container" class="toast-container position-fixed top-0 end-0 p-3" style="z-index:1080"></div>

@push('css')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@xterm/xterm@5.5.0/css/xterm.min.css">
<style>
/* Fullscreen via Fullscreen API */
#ssh-terminal-card:fullscreen,
#ssh-terminal-card:-webkit-full-screen {
    position:fixed;inset:0;z-index:9999;margin:0;border-radius:0;width:100vw;height:100vh;
}
#ssh-terminal-card:fullscreen #terminal,
#ssh-terminal-card:-webkit-full-screen #terminal {
    height:calc(100vh - 42px) !important;
}
/* CSS-only fullscreen fallback */
#ssh-terminal-card.ssh-fs-compat {
    position:fixed;inset:0;z-index:9999;margin:0;border-radius:0 !important;width:100vw;height:100vh;
}
#ssh-terminal-card.ssh-fs-compat #terminal {
    height:calc(100vh - 42px) !important;
}
/* Bell flash */
#terminal.ssh-bell { outline:2px solid #ff7b72; }
</style>
@endpush

@push('js')
<script src="https://cdn.jsdelivr.net/npm/@xterm/xterm@5.5.0/lib/xterm.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@xterm/addon-fit@0.10.0/lib/addon-fit.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@xterm/addon-webgl@0.18.0/lib/addon-webgl.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@xterm/addon-canvas@0.7.0/lib/addon-canvas.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@xterm/addon-search@0.5.0/lib/addon-search.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@xterm/addon-web-links@0.11.0/lib/addon-web-links.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@xterm/addon-unicode11@0.8.0/lib/addon-unicode11.min.js"></script>
<script>
(() => {
    'use strict';

    const hasHost    = @json((bool) $hasHost);
    const csrfToken  = @json(csrf_token());
    const openUrl    = @json(route('admin.ssh-console.open', $hostingAccount));
    const streamUrlTemplate = @json(route('admin.ssh-console.stream', [$hostingAccount, '__TOKEN__']));
    const inputUrlTemplate  = @json(route('admin.ssh-console.input',  [$hostingAccount, '__TOKEN__']));
    const resizeUrlTemplate = @json(route('admin.ssh-console.resize', [$hostingAccount, '__TOKEN__']));
    const closeUrlTemplate  = @json(route('admin.ssh-console.close',  [$hostingAccount, '__TOKEN__']));

    const statusBadge  = document.getElementById('ssh-status');
    const connectBtn   = document.getElementById('ssh-connect');
    const disconnectBtn= document.getElementById('ssh-disconnect');
    const autoReconnect= document.getElementById('ssh-autoreconnect');
    const terminalEl   = document.getElementById('terminal');
    const termCard     = document.getElementById('ssh-terminal-card');
    const searchBar    = document.getElementById('term-search-bar');
    const searchInput  = document.getElementById('term-search-input');
    const searchCount  = document.getElementById('term-search-count');
    const themeSelect  = document.getElementById('term-theme');

    // ── Themes ───────────────────────────────────────────────────────────────
    const THEMES = {
        dark: {
            background:'#11151c', foreground:'#e6edf3', cursor:'#e6edf3', cursorAccent:'#11151c',
            selectionBackground:'rgba(120,160,220,.3)',
            black:'#1c2129', red:'#ff7b72', green:'#3fb950', yellow:'#d29922',
            blue:'#58a6ff', magenta:'#d2a8ff', cyan:'#76e3ea', white:'#cdd9e5',
            brightBlack:'#636e7b', brightRed:'#ffa198', brightGreen:'#56d364',
            brightYellow:'#e3b341', brightBlue:'#79c0ff', brightMagenta:'#e2c4ff',
            brightCyan:'#b3f0ff', brightWhite:'#ffffff',
        },
        dracula: {
            background:'#282a36', foreground:'#f8f8f2', cursor:'#f8f8f2', cursorAccent:'#282a36',
            selectionBackground:'rgba(68,71,90,.5)',
            black:'#21222c', red:'#ff5555', green:'#50fa7b', yellow:'#f1fa8c',
            blue:'#6272a4', magenta:'#ff79c6', cyan:'#8be9fd', white:'#f8f8f2',
            brightBlack:'#6272a4', brightRed:'#ff6e6e', brightGreen:'#69ff94',
            brightYellow:'#ffffa5', brightBlue:'#d6acff', brightMagenta:'#ff92df',
            brightCyan:'#a4ffff', brightWhite:'#ffffff',
        },
        nord: {
            background:'#2e3440', foreground:'#d8dee9', cursor:'#d8dee9', cursorAccent:'#2e3440',
            selectionBackground:'rgba(67,76,94,.5)',
            black:'#3b4252', red:'#bf616a', green:'#a3be8c', yellow:'#ebcb8b',
            blue:'#81a1c1', magenta:'#b48ead', cyan:'#88c0d0', white:'#e5e9f0',
            brightBlack:'#4c566a', brightRed:'#bf616a', brightGreen:'#a3be8c',
            brightYellow:'#ebcb8b', brightBlue:'#81a1c1', brightMagenta:'#b48ead',
            brightCyan:'#8fbcbb', brightWhite:'#eceff4',
        },
        light: {
            background:'#ffffff', foreground:'#1f2328', cursor:'#1f2328', cursorAccent:'#ffffff',
            selectionBackground:'rgba(0,0,0,.12)',
            black:'#24292f', red:'#cf222e', green:'#116329', yellow:'#4d2d00',
            blue:'#0969da', magenta:'#8250df', cyan:'#1b7c83', white:'#6e7781',
            brightBlack:'#57606a', brightRed:'#a40e26', brightGreen:'#1a7f37',
            brightYellow:'#633c01', brightBlue:'#218bff', brightMagenta:'#a475f9',
            brightCyan:'#3192aa', brightWhite:'#8c959f',
        },
    };

    const savedTheme    = localStorage.getItem('ssh-term-theme') || 'dark';
    let   currentFsSize = parseInt(localStorage.getItem('ssh-term-fontsize') || '14', 10);
    currentFsSize = Math.max(8, Math.min(32, currentFsSize));

    if (themeSelect && THEMES[savedTheme]) themeSelect.value = savedTheme;

    function currentThemeKey() {
        return (themeSelect && THEMES[themeSelect.value]) ? themeSelect.value : 'dark';
    }

    // ── Early exit ────────────────────────────────────────────────────────────
    if (!hasHost || typeof window.Terminal === 'undefined') {
        setStatus('unavailable', 'secondary');
        if (typeof window.Terminal === 'undefined' && terminalEl) {
            terminalEl.innerHTML = '<div class="alert alert-warning m-2">Terminal library failed to load (CDN blocked?). Check network access to cdn.jsdelivr.net.</div>';
        }
        return;
    }

    // ── Terminal init ─────────────────────────────────────────────────────────
    const term = new window.Terminal({
        cursorBlink   : true,
        cursorStyle   : 'block',
        fontFamily    : '"SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace',
        fontSize      : currentFsSize,
        lineHeight    : 1.2,
        scrollback    : 10000,
        allowProposedApi: true,
        theme         : THEMES[currentThemeKey()],
    });

    const fitAddon = new window.FitAddon.FitAddon();
    term.loadAddon(fitAddon);
    term.open(terminalEl);

    // GPU renderer — WebGL → Canvas → CPU
    (() => {
        const WGL = window.WebglAddon && window.WebglAddon.WebglAddon;
        const CNV = window.CanvasAddon && window.CanvasAddon.CanvasAddon;
        if (WGL) {
            try {
                const wgl = new WGL();
                wgl.onContextLoss(() => { try { wgl.dispose(); } catch (_) {} });
                term.loadAddon(wgl);
                return;
            } catch (_) {}
        }
        if (CNV) { try { term.loadAddon(new CNV()); } catch (_) {} }
    })();

    // Unicode11 — correct wide/emoji column widths
    if (window.Unicode11Addon && window.Unicode11Addon.Unicode11Addon) {
        try {
            term.loadAddon(new window.Unicode11Addon.Unicode11Addon());
            term.unicode.activeVersion = '11';
        } catch (_) {}
    }

    // Clickable URLs
    if (window.WebLinksAddon && window.WebLinksAddon.WebLinksAddon) {
        try { term.loadAddon(new window.WebLinksAddon.WebLinksAddon()); } catch (_) {}
    }

    // Search
    let searchAddon = null;
    if (window.SearchAddon && window.SearchAddon.SearchAddon) {
        try { searchAddon = new window.SearchAddon.SearchAddon(); term.loadAddon(searchAddon); } catch (_) {}
    }

    // Bell → visual flash
    term.onBell(() => {
        if (terminalEl) {
            terminalEl.classList.add('ssh-bell');
            setTimeout(() => terminalEl.classList.remove('ssh-bell'), 180);
        }
    });

    // ── State ────────────────────────────────────────────────────────────────
    let token          = null;
    let controller     = null;
    let running        = false;
    let reconnectAttempts = 0;
    let manualClose    = false;
    let outputLog      = [];

    // ── Helpers ───────────────────────────────────────────────────────────────
    function setStatus(text, theme) {
        statusBadge.textContent = text;
        statusBadge.className = 'badge ms-1 bg-' + (theme || 'secondary');
    }

    function headers() {
        return {
            'Content-Type'    : 'application/json',
            'X-CSRF-TOKEN'    : csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
            'Accept'          : 'application/json',
        };
    }

    function urlFor(tpl) { return tpl.replace('__TOKEN__', encodeURIComponent(token)); }

    function showToast(message, variant) {
        const v = variant || 'dark';
        const container = document.getElementById('ssh-toast-container');
        if (!container) { term.write('\r\n' + message + '\r\n'); return; }
        if (window.bootstrap && window.bootstrap.Toast) {
            const el = document.createElement('div');
            el.className = 'toast align-items-center text-bg-' + v + ' border-0';
            el.setAttribute('role', 'alert');
            el.setAttribute('aria-live', 'assertive');
            el.setAttribute('aria-atomic', 'true');
            el.innerHTML = '<div class="d-flex"><div class="toast-body">' + String(message).replace(/</g,'&lt;') + '</div>'
                         + '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>';
            container.appendChild(el);
            const bsToast = new window.bootstrap.Toast(el, { delay: 3500, autohide: true });
            el.addEventListener('hidden.bs.toast', () => el.remove());
            bsToast.show();
        } else {
            const el = document.createElement('div');
            el.style.cssText = 'background:#11151c;color:#e6edf3;border:1px solid rgba(255,255,255,.12);padding:.4rem .75rem;border-radius:.35rem;margin-top:.4rem;font-size:.85rem;';
            el.textContent = message;
            container.appendChild(el);
            setTimeout(() => el.remove(), 3500);
        }
    }

    // ── Theme ─────────────────────────────────────────────────────────────────
    function applyTheme(key) {
        const t = THEMES[key] || THEMES.dark;
        term.options.theme = t;
        if (terminalEl) terminalEl.style.background = t.background;
        localStorage.setItem('ssh-term-theme', key);
    }

    if (themeSelect) {
        themeSelect.addEventListener('change', () => applyTheme(themeSelect.value));
        applyTheme(currentThemeKey());
    }

    // ── Zoom ──────────────────────────────────────────────────────────────────
    function setFontSize(sz) {
        currentFsSize = Math.max(8, Math.min(32, sz));
        term.options.fontSize = currentFsSize;
        localStorage.setItem('ssh-term-fontsize', currentFsSize);
        scheduleFitAndPush();
    }

    document.getElementById('term-zoom-in') .addEventListener('click', () => setFontSize(currentFsSize + 1));
    document.getElementById('term-zoom-out').addEventListener('click', () => setFontSize(currentFsSize - 1));

    // ── Fullscreen ────────────────────────────────────────────────────────────
    const fsBtn  = document.getElementById('term-fullscreen');
    const fsIcon = document.getElementById('term-fullscreen-icon');

    function syncFsHeight() {
        const isFs = !!document.fullscreenElement || termCard.classList.contains('ssh-fs-compat');
        if (!terminalEl) return;
        if (isFs) {
            const hdrH  = (termCard.querySelector('.card-header') || {}).offsetHeight || 42;
            const srchH = searchBar && !searchBar.classList.contains('d-none') ? searchBar.offsetHeight : 0;
            terminalEl.style.height = (window.innerHeight - hdrH - srchH) + 'px';
        } else {
            terminalEl.style.height = '70vh';
        }
    }

    function toggleFullscreen() {
        if (document.fullscreenElement) {
            document.exitFullscreen().catch(() => {});
        } else if (termCard.requestFullscreen) {
            termCard.requestFullscreen().catch(() => {
                termCard.classList.toggle('ssh-fs-compat');
                syncFsHeight(); scheduleFitAndPush();
            });
        } else {
            termCard.classList.toggle('ssh-fs-compat');
            syncFsHeight(); scheduleFitAndPush();
        }
    }

    document.addEventListener('fullscreenchange', () => {
        const isFs = !!document.fullscreenElement;
        fsIcon.className = isFs ? 'bi bi-fullscreen-exit' : 'bi bi-fullscreen';
        syncFsHeight(); scheduleFitAndPush();
    });

    if (fsBtn) fsBtn.addEventListener('click', toggleFullscreen);

    // ── Search bar ────────────────────────────────────────────────────────────
    const SEARCH_DECO = {
        matchBackground       : '#4a3300',
        matchBorder           : '#e3b341',
        matchOverviewRuler    : '#e3b341',
        activeMatchBackground : '#665c00',
        activeMatchBorder     : '#f1fa8c',
        activeMatchColorOverviewRuler: '#f1fa8c',
    };

    function openSearch() {
        if (!searchBar) return;
        searchBar.classList.remove('d-none');
        syncFsHeight(); scheduleFitAndPush();
        if (searchInput) { searchInput.focus(); searchInput.select(); }
    }

    function closeSearch() {
        if (!searchBar) return;
        searchBar.classList.add('d-none');
        if (searchAddon) { try { searchAddon.clearDecorations(); } catch (_) {} }
        if (searchCount) searchCount.textContent = '';
        syncFsHeight(); scheduleFitAndPush();
        term.focus();
    }

    function doSearch(dir) {
        if (!searchAddon || !searchInput) return;
        const q = searchInput.value;
        if (!q) { if (searchCount) searchCount.textContent = ''; return; }
        let found;
        try {
            const opts = { regex: false, caseSensitive: false, wholeWord: false, decorations: SEARCH_DECO };
            found = dir === 'prev' ? searchAddon.findPrevious(q, opts) : searchAddon.findNext(q, opts);
        } catch (_) {
            try { found = dir === 'prev' ? searchAddon.findPrevious(q) : searchAddon.findNext(q); } catch (__) {}
        }
        if (searchCount) searchCount.textContent = found ? '' : 'not found';
    }

    document.getElementById('term-search-btn') .addEventListener('click', () => searchBar.classList.contains('d-none') ? openSearch() : closeSearch());
    document.getElementById('term-search-next') .addEventListener('click', () => doSearch('next'));
    document.getElementById('term-search-prev') .addEventListener('click', () => doSearch('prev'));
    document.getElementById('term-search-close').addEventListener('click', closeSearch);

    if (searchInput) {
        searchInput.addEventListener('input', () => doSearch('next'));
        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter')  { e.preventDefault(); doSearch(e.shiftKey ? 'prev' : 'next'); }
            if (e.key === 'Escape') { e.preventDefault(); closeSearch(); }
        });
    }

    // ── Transcript download ───────────────────────────────────────────────────
    function stripAnsi(s) {
        return s.replace(/\x1b(?:[@-Z\\-_]|\[[0-9?;]*[A-Za-z])/g, '').replace(/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/g, '');
    }

    document.getElementById('term-transcript').addEventListener('click', () => {
        const text = stripAnsi(outputLog.join(''));
        if (!text.trim()) { showToast('No output to download yet.', 'secondary'); return; }
        const blob = new Blob([text], { type: 'text/plain' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'ssh-transcript-' + new Date().toISOString().slice(0, 19).replace(/:/g, '-') + '.txt';
        a.click();
        setTimeout(() => URL.revokeObjectURL(a.href), 5000);
    });

    // ── Global keyboard shortcuts ─────────────────────────────────────────────
    document.addEventListener('keydown', (e) => {
        if (e.ctrlKey && !e.shiftKey && !e.altKey && !e.metaKey) {
            if (e.key === '=' || e.key === '+') { e.preventDefault(); setFontSize(currentFsSize + 1); return; }
            if (e.key === '-' || e.key === '_') { e.preventDefault(); setFontSize(currentFsSize - 1); return; }
            if (e.key === '0')                  { e.preventDefault(); setFontSize(14); return; }
        }
        if (e.ctrlKey && e.shiftKey && (e.key === 'f' || e.key === 'F')) {
            e.preventDefault();
            if (searchBar) searchBar.classList.contains('d-none') ? openSearch() : closeSearch();
        }
    });

    // ── Input relay ───────────────────────────────────────────────────────────
    let pendingInput = '';
    let inputTimer   = null;
    let flushBusy    = false;

    function flushInput() {
        inputTimer = null;
        // Serialise POSTs: if one is already in-flight, the data stays in
        // pendingInput and will be sent when the in-flight fetch settles.
        // Without this guard two concurrent POSTs can be processed by
        // different PHP-FPM workers out of order, causing dropped/swapped chars.
        if (token === null || pendingInput === '' || flushBusy) return;
        const data = pendingInput;
        pendingInput = '';
        flushBusy = true;
        const bytes = new TextEncoder().encode(data);
        let b64 = '';
        for (let i = 0; i < bytes.length; i += 0x8000) {
            b64 += btoa(String.fromCharCode.apply(null, bytes.subarray(i, Math.min(i + 0x8000, bytes.length))));
        }
        fetch(urlFor(inputUrlTemplate), {
            method: 'POST', headers: headers(), credentials: 'same-origin',
            body: JSON.stringify({ data: b64 }),
        }).catch(() => {}).finally(() => {
            flushBusy = false;
            if (pendingInput !== '' && token !== null && running) flushInput();
        });
    }

    term.onData((data) => {
        if (!running) return;
        pendingInput += data;
        const isControl = data.includes('\r') || data.includes('\n') || data.startsWith('\x1b');
        if (isControl) {
            if (inputTimer !== null) { clearTimeout(inputTimer); inputTimer = null; }
            flushInput();
        } else if (inputTimer === null) {
            inputTimer = setTimeout(flushInput, 10);
        }
    });

    // ── PTY resize ────────────────────────────────────────────────────────────
    let resizeTimer = null;
    let fitTimer    = null;

    function pushResize() {
        resizeTimer = null;
        if (token === null || !running) return;
        fetch(urlFor(resizeUrlTemplate), {
            method: 'POST', headers: headers(), credentials: 'same-origin',
            body: JSON.stringify({ cols: term.cols, rows: term.rows }),
        }).catch(() => {});
    }

    function doFit() { try { fitAddon.fit(); } catch (_) {} }

    function scheduleFitAndPush() {
        if (fitTimer !== null) clearTimeout(fitTimer);
        fitTimer = setTimeout(() => {
            fitTimer = null;
            syncFsHeight();
            doFit();
            if (resizeTimer === null) resizeTimer = setTimeout(pushResize, 200);
            setTimeout(doFit, 320);
        }, 120);
    }

    window.addEventListener('resize', scheduleFitAndPush);

    ['collapsed.lte.pushmenu','expanded.lte.pushmenu','shown.lte.pushmenu','hidden.lte.pushmenu'].forEach((evt) => {
        document.addEventListener(evt, scheduleFitAndPush);
        window.addEventListener(evt, scheduleFitAndPush);
    });

    try {
        if (window.ResizeObserver) new ResizeObserver(scheduleFitAndPush).observe(document.body);
        document.querySelectorAll('[data-widget="pushmenu"],[data-lte-toggle="sidebar"]').forEach((el) => {
            el.addEventListener('click', () => { setTimeout(scheduleFitAndPush, 50); setTimeout(scheduleFitAndPush, 350); });
        });
    } catch (_) {}

    // ── Clipboard ─────────────────────────────────────────────────────────────
    try {
        term.onSelectionChange(() => {
            const sel = term.getSelection();
            if (sel && navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(sel).catch(() => {});
            }
        });
    } catch (_) {}

    term.attachCustomKeyEventHandler((ev) => {
        const key  = (ev.key || '').toLowerCase();
        const code = ev.code || '';
        const isCopy  = ev.ctrlKey && ev.shiftKey && (key === 'c' || code === 'KeyC');
        const isPaste = (ev.ctrlKey && ev.shiftKey && (key === 'v' || code === 'KeyV'))
                     || (ev.shiftKey && code === 'Insert' && !ev.ctrlKey);

        if (isCopy) {
            const sel = term.getSelection();
            if (sel && navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(sel).then(() => showToast('Copied to clipboard', 'success')).catch(() => {});
            }
            return false;
        }
        if (isPaste) {
            ev.preventDefault();
            if (navigator.clipboard && navigator.clipboard.readText) {
                navigator.clipboard.readText().then((text) => {
                    if (!text) return;
                    if (!running) { term.write(text); return; }
                    pendingInput += text;
                    if (inputTimer === null) inputTimer = setTimeout(flushInput, 10);
                }).catch(() => {});
            }
            return false;
        }

        // Intercept F1-F12 so the browser doesn't consume them (F5=reload,
        // F1=browser help, F11=fullscreen).  F11 wires to our fullscreen
        // toggle; every other F-key is passed to xterm so it sends the
        // correct ANSI escape sequence to the remote shell.
        if (ev.type === 'keydown' && /^F([1-9]|1[0-2])$/.test(ev.key) &&
                !ev.ctrlKey && !ev.altKey && !ev.metaKey) {
            ev.preventDefault();
            if (ev.key === 'F11') { toggleFullscreen(); return false; }
            return true; // xterm writes e.g. \x1b[21~ for F10
        }

        return true;
    });

    document.addEventListener('paste', (e) => {
        if (!terminalEl) return;
        const isFocused = terminalEl.contains(document.activeElement) || document.activeElement === document.body;
        if (!isFocused) return;
        const text = e.clipboardData ? e.clipboardData.getData('text') : '';
        if (!text) return;
        e.preventDefault();
        if (!running) { term.write(text); return; }
        pendingInput += text;
        if (inputTimer === null) inputTimer = setTimeout(flushInput, 10);
    });

    if (terminalEl) {
        terminalEl.addEventListener('contextmenu', () => {
            const sel = term.getSelection();
            if (sel && navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(sel).catch(() => {});
            }
        });
    }

    // ── Output ────────────────────────────────────────────────────────────────
    function writeB64(b64) {
        const bin   = atob(b64);
        const bytes = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
        term.write(bytes);
        outputLog.push(new TextDecoder().decode(bytes));
    }

    // ── Connect ───────────────────────────────────────────────────────────────
    async function connect() {
        if (running || token !== null) return;
        manualClose = false;
        outputLog   = [];
        term.reset();
        setStatus('connecting…', 'warning');
        connectBtn.disabled = true;

        let openRes;
        try {
            openRes = await fetch(openUrl, { method: 'POST', headers: headers(), credentials: 'same-origin' });
        } catch (e) { fail(new Error('Network error while opening the session.')); return; }

        if (!openRes.ok) { fail(new Error('Could not open a terminal session (HTTP ' + openRes.status + ').')); return; }

        token   = (await openRes.json()).token;
        running = true;
        setStatus('connected', 'success');
        disconnectBtn.disabled = false;

        try { fitAddon.fit(); } catch (_) {}

        controller = new AbortController();
        const streamBase = urlFor(streamUrlTemplate);
        const streamUrl  = streamBase + (streamBase.includes('?') ? '&' : '?') + 'cols=' + term.cols + '&rows=' + term.rows;

        try {
            const res = await fetch(streamUrl, { headers: headers(), credentials: 'same-origin', signal: controller.signal });
            if (!res.ok || !res.body) throw new Error('Stream failed (HTTP ' + res.status + ').');

            const reader  = res.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            for (;;) {
                const { done, value } = await reader.read();
                if (done) break;
                buffer += decoder.decode(value, { stream: true });

                let nl;
                while ((nl = buffer.indexOf('\n')) >= 0) {
                    const line = buffer.slice(0, nl).trim();
                    buffer = buffer.slice(nl + 1);
                    if (line === '') continue;

                    let frame;
                    try { frame = JSON.parse(line); } catch (_) { continue; }

                    if (frame.o) writeB64(frame.o);
                    if (frame.e) {
                        term.write('\r\n\x1b[31m' + frame.e.replace(/[\r\n]+/g, ' ') + '\x1b[0m\r\n');
                        reconnectAttempts = 99;
                    }
                }
            }
        } catch (e) {
            if (!controller.signal.aborted) {
                const detail = (e && e.message ? e.message : 'Connection lost.').replace(/[\r\n]+/g, ' ');
                term.write('\r\n\x1b[31m' + detail + '\x1b[0m\r\n');
            }
        } finally {
            cleanup();
            maybeReconnect();
        }
    }

    function cleanup() {
        running     = false;
        controller  = null;
        disconnectBtn.disabled = true;
        if (token !== null) {
            const closeUrl = urlFor(closeUrlTemplate);
            token = null;
            fetch(closeUrl, { method: 'POST', headers: headers(), credentials: 'same-origin', keepalive: true }).catch(() => {});
        }
        if (reconnectAttempts < 90) setStatus('closed', 'secondary');
    }

    function maybeReconnect() {
        if (autoReconnect.checked && reconnectAttempts < 3) {
            reconnectAttempts++;
            term.write('\r\n\x1b[33mReconnecting (' + reconnectAttempts + '/3)…\x1b[0m\r\n');
            setTimeout(connect, 1200 * reconnectAttempts);
        } else {
            const isError = reconnectAttempts >= 99;
            setStatus(isError ? 'error' : 'closed', isError ? 'danger' : 'secondary');
            connectBtn.disabled = false;
            if (manualClose) { manualClose = false; return; }
            showToast(isError ? 'Session ended due to an error.' : 'Disconnected from the server.', isError ? 'danger' : 'secondary');
        }
    }

    function fail(err) {
        term.write('\r\n\x1b[31m' + err.message + '\x1b[0m\r\n');
        setStatus('error', 'danger');
        connectBtn.disabled = false;
        showToast(err.message, 'danger');
    }

    connectBtn.addEventListener('click', () => { reconnectAttempts = 0; manualClose = false; connect(); });
    disconnectBtn.addEventListener('click', () => {
        manualClose = true;
        reconnectAttempts = 99;
        if (controller) controller.abort();
        cleanup();
        setStatus('closed', 'secondary');
        connectBtn.disabled = false;
        showToast('Session closed.', 'secondary');
    });

    setStatus('ready', 'info');
})();
</script>
@endpush
@endsection
