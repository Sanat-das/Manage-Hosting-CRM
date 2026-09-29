<!DOCTYPE html>
<html lang="en" @if (! empty($themeResolved)) data-theme="{{ $themeResolved }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ config('adminlte.title', config('app.name')) }} — Setup</title>
    {{-- Resolve the theme before first paint. Shares the app-wide keys
         (adminlte.colorMode in localStorage, mh_theme cookie) so a choice made
         here carries into the panel once setup finishes. --}}
    <script>
        (function () {
            try {
                var match = document.cookie.match(/(?:^|;\s*)mh_theme=([^;]+)/);
                var preference = localStorage.getItem('adminlte.colorMode')
                    || (match ? decodeURIComponent(match[1]) : 'auto');
                var resolved = preference === 'auto'
                    ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                    : preference;
                document.documentElement.setAttribute('data-theme', resolved);
                document.documentElement.setAttribute('data-preference', preference);
            } catch (e) {
                /* Storage blocked — the media query below still handles Auto. */
            }
        })();
    </script>
    <style>
        :root {
            --bg: #f6f7f9; --card: #ffffff; --text: #1b1b18; --muted: #6b7280;
            --border: #e5e7eb; --accent: #2563eb; --ok: #15803d; --err: #b91c1c; --input: #ffffff;
        }
        /* Dark palette, declared twice on purpose: once for the explicit choice
           the script stamps on <html>, once for the no-JS case where only
           prefers-color-scheme is available. The script always resolves to an
           explicit light|dark, so the two blocks never both apply. */
        :root[data-theme="dark"] {
            --bg: #0a0a0a; --card: #141414; --text: #ededec; --muted: #9ca3af;
            --border: #2d2d2d; --accent: #3b82f6; --ok: #4ade80; --err: #f87171; --input: #1c1c1c;
        }
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme]) {
                --bg: #0a0a0a; --card: #141414; --text: #ededec; --muted: #9ca3af;
                --border: #2d2d2d; --accent: #3b82f6; --ok: #4ade80; --err: #f87171; --input: #1c1c1c;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg); color: var(--text);
        }
        .shell { max-width: 640px; margin: 0 auto; padding: 48px 16px 32px; }
        .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; flex-wrap: wrap; row-gap: 10px; }
        .brand .theme-toggle { margin-left: auto; }
        .theme-toggle { display: inline-flex; gap: 2px; padding: 2px; border: 1px solid var(--border); border-radius: 9999px; }
        .theme-toggle button {
            border: 0; background: transparent; color: var(--muted); font: inherit; font-size: 12px;
            padding: 5px 10px; border-radius: 9999px; cursor: pointer; line-height: 1;
        }
        .theme-toggle button:hover { color: var(--text); }
        .theme-toggle button[aria-pressed="true"] { background: var(--accent); color: #fff; }
        .theme-toggle button:focus-visible { outline: 2px solid var(--accent); outline-offset: 1px; }
        .brand .mark {
            width: 40px; height: 40px; border-radius: 10px; background: var(--accent); color: #fff;
            display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 18px; flex: none;
        }
        .brand h1 { font-size: 20px; margin: 0; line-height: 1.2; }
        .brand p { margin: 2px 0 0; font-size: 13px; color: var(--muted); }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 28px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
        h2 { font-size: 15px; margin: 0 0 4px; }
        .section { margin-bottom: 26px; }
        .section:last-child { margin-bottom: 0; }
        .section-head { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 12px; }
        .section-head .hint { font-size: 12px; color: var(--muted); }
        .checks { list-style: none; margin: 0; padding: 0; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
        .checks li { display: flex; align-items: flex-start; gap: 10px; padding: 10px 14px; border-bottom: 1px solid var(--border); font-size: 13.5px; }
        .checks li:last-child { border-bottom: none; }
        .checks .dot { width: 18px; height: 18px; border-radius: 50%; flex: none; margin-top: 1px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; }
        .checks .dot.ok { background: rgba(21,128,61,.12); color: var(--ok); }
        .checks .dot.fail { background: rgba(185,28,28,.12); color: var(--err); }
        .checks .name { flex: none; font-weight: 600; min-width: 210px; }
        .checks .detail { color: var(--muted); word-break: break-word; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .field { display: block; margin-bottom: 12px; }
        .field.full { grid-column: 1 / -1; }
        .field label { display: block; font-size: 12.5px; font-weight: 600; margin-bottom: 6px; }
        .field input {
            width: 100%; padding: 9px 11px; border: 1px solid var(--border); border-radius: 8px;
            background: var(--input); color: var(--text); font-size: 14px;
        }
        .field input:focus { outline: 2px solid rgba(37,99,235,.35); border-color: var(--accent); }
        .field .err { color: var(--err); font-size: 12px; margin-top: 4px; }
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px; width: 100%;
            padding: 11px 16px; border: 0; border-radius: 9px; background: var(--accent); color: #fff;
            font-size: 14.5px; font-weight: 600; cursor: pointer; text-decoration: none;
        }
        .btn:hover { filter: brightness(1.06); }
        .btn:disabled { opacity: .55; cursor: not-allowed; }
        .alert { padding: 12px 14px; border-radius: 10px; font-size: 13.5px; margin-bottom: 18px; border: 1px solid; }
        .alert-error { background: rgba(185,28,28,.08); border-color: rgba(185,28,28,.25); color: var(--err); }
        .note { font-size: 12.5px; color: var(--muted); margin: 10px 0 0; }
        .footnote { text-align: center; font-size: 12px; color: var(--muted); margin-top: 20px; }
        @media (max-width: 560px) {
            .grid { grid-template-columns: 1fr; }
            .checks .name { min-width: 0; }
        }
    </style>
</head>
<body>
    <main class="shell">
        <header class="brand">
            <div class="mark">{{ strtoupper(substr(config('adminlte.title', config('app.name')), 0, 1)) }}</div>
            <div>
                <h1>{{ config('adminlte.title', config('app.name')) }}</h1>
                <p>First-run setup</p>
            </div>
            <div class="theme-toggle" role="group" aria-label="Colour scheme">
                <button type="button" data-install-theme="light" aria-pressed="false">Light</button>
                <button type="button" data-install-theme="dark" aria-pressed="false">Dark</button>
                <button type="button" data-install-theme="auto" aria-pressed="true">Auto</button>
            </div>
        </header>

        <div class="card">
            @if ($errors->has('installer'))
                <div class="alert alert-error">{{ $errors->first('installer') }}</div>
            @endif
            @yield('content')
        </div>

        <p class="footnote">Setup wizard — this page is disabled after installation completes.</p>
    </main>

    <script>
        (function () {
            var root = document.documentElement;
            var buttons = document.querySelectorAll('[data-install-theme]');

            function resolve(preference) {
                return preference === 'auto'
                    ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                    : preference;
            }

            function paint(preference) {
                root.setAttribute('data-preference', preference);
                root.setAttribute('data-theme', resolve(preference));
                buttons.forEach(function (button) {
                    button.setAttribute('aria-pressed',
                        button.dataset.installTheme === preference ? 'true' : 'false');
                });
            }

            paint(root.getAttribute('data-preference') || 'auto');

            buttons.forEach(function (button) {
                button.addEventListener('click', function () {
                    var preference = button.dataset.installTheme;
                    try {
                        localStorage.setItem('adminlte.colorMode', preference);
                        document.cookie = 'mh_theme=' + encodeURIComponent(preference)
                            + '; path=/; max-age=31536000; samesite=lax';
                    } catch (e) {
                        /* Storage blocked — the choice still applies to this page. */
                    }
                    paint(preference);
                });
            });

            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
                if (root.getAttribute('data-preference') === 'auto') paint('auto');
            });
        })();
    </script>
</body>
</html>
