# Fix: Realtime survives the build via runtime config + CSP ws origin

## TL;DR

`import.meta.env.VITE_REVERB_APP_KEY` is inlined by Vite at **build time**. The committed bundle is built once, on a machine whose `.env` has **no `REVERB_*` keys**, so the guard `if (!key) return` folds to a constant `if (!undefined) return` and Rolldown dead-code-eliminates the entire realtime branch. The committed `public/build/` is therefore permanently polling-only — every customer install would need to rebuild from source to get realtime, which they cannot (the updater never runs `npm run build`). The fix renders the public Reverb config at **request time** from Laravel config into `window.__REVERB__`, and the JS reads that at runtime. The connection decision is now a genuine runtime branch the bundler cannot fold away. `REVERB_APP_SECRET` is never rendered. The CSP `connect-src` is built at runtime to include the configured `ws://`/`wss://` origin when present, and stays `'self'` otherwise.

---

## 1. The Vite build-time inlining — why nobody may reintroduce `import.meta.env` gating

### What Vite does

`import.meta.env.VITE_*` is not a runtime lookup. Vite replaces it **at build time** with the literal value from the building machine's environment (or `undefined` when absent). Example:

```js
// source: resources/js/client-chat.js:398
const key = import.meta.env.VITE_REVERB_APP_KEY;
if (!key) return; // <- after build, when .env has no REVERB keys:
                  //    const key = undefined; if (!undefined) return;
```

Because the condition is a constant `true`, the bundler (Rolldown/Vite) treats the `return` and everything after it — `new Echo(...)`, `echo.private(...)`, `.listen('.chat.message.new')`, `.listen('.chat.typing')`, `showTyping` — as **unreachable**, and drops them plus the now-unused `laravel-echo`/`pusher-js` imports. The emitted chunk is smaller and has no realtime code at all.

### Why runtime config is required given tracked `public/build`

- `public/build/` is **tracked in git** (21 assets in the manifest at `HEAD`).
- The in-app updater `app/Services/UpdateService.php` never runs `npm run build`; it only `git pull`s/overwrites files.
- The bundle is therefore built **once, by us**, and shipped verbatim. A build-time key means every install would need to rebuild from source to get realtime — impossible on a Windows/IIS production box that does not have Node/Vite.
- The key/host/port/scheme must be resolved **per request**, from the server's own `.env`/Laravel config, not from the builder's env.

### What would reintroduce the bug

Any of:

- `const key = import.meta.env.VITE_REVERB_APP_KEY; if (!key) return;` in `echo.js` or `client-chat.js`
- Reading `VITE_REVERB_*` as the **only** source for the Echo constructor

will silently re-eliminate the realtime branch on the next build performed without Reverb env. The correct pattern is: **PHP renders `window.__REVERB__` from `ReverbConfig::forClient()` (which reads `config('broadcasting.connections.reverb.*')`), the JS reads that first, and only falls back to `import.meta.env` as a dev convenience inside `resources/js/reverb-config.js`. The primary path is never a build-time constant.

---

## 2. The fix — files and mechanism

### PHP source of truth — `app/Support/ReverbConfig.php` (new)

```php
public static function forClient(): ?array
// returns ['key'=>string,'host'=>string,'port'=>mixed,'scheme'=>string] or null
// key presence decides "configured". Secret is never read or returned.

public static function cspConnectSrc(): string
// returns "'self'" when not configured or when host/port/scheme fail strict validation,
// else "'self' ws://host:port wss://host:port"
```

Reads `config('broadcasting.connections.reverb.key')` and `options.host/port/scheme`. Host is validated against `/^[a-zA-Z0-9.\-]+$/` or `FILTER_VALIDATE_IP`; port must be numeric 1–65535; scheme must be `http` or `https`. Malformed input **does not widen** the CSP.

### Layout — `resources/views/vendor/adminlte/partials/head.blade.php`

Head is the smallest correct injection point: it is included by **both** `master.blade.php` (admin + client portal) and `auth-master.blade.php` (login), so every page that can carry the widget or the admin chat gets the config without duplication.

```blade
@php $_reverb = \App\Support\ReverbConfig::forClient(); @endphp
@if ($_reverb !== null)
<script>window.__REVERB__ = @json($_reverb)</script>
@endif
@vite([... echo.js ...])
```

When `REVERB_APP_KEY` is empty, nothing is emitted — the element is **absent**, and the JS tests assert its absence. When present, the JSON contains only `key`, `host`, `port`, `scheme` — never the secret.

### JS runtime reader — `resources/js/reverb-config.js` (new)

```js
export function getReverbConfig() {
  // 1) window.__REVERB__ — primary, runtime, bundler cannot fold
  // 2) import.meta.env.VITE_* — dev fallback only
  // validates host (alnum+dot/hyphen or IP), port 1–65535 integer, scheme http/https
  // returns null on any malformed input -> caller degrades to polling, never throws
}
```

### `resources/js/echo.js` — admin realtime

Before:
```js
const env = import.meta.env;
const appKey = env.VITE_REVERB_APP_KEY;
if (!appKey) { realtime.reason = 'VITE_REVERB_APP_KEY is not set ...'; }
else { new Echo({key:appKey, wsHost:env.VITE_REVERB_HOST,...}) }
```

After:
```js
import { getReverbConfig } from './reverb-config.js';
const cfg = getReverbConfig();
if (!cfg) { realtime.reason = 'Reverb is not configured ...'; }
else { new Echo({key:cfg.key, wsHost:cfg.host, wsPort:cfg.port, wssPort:cfg.port, forceTLS:cfg.scheme==='https', ...}) }
```

No `import.meta.env` gate remains; the branch is a runtime `if (!cfg)` reading `window.__REVERB__`.

### `resources/js/client-chat.js` — client widget

Same transformation:

Before:
```js
const key = import.meta.env.VITE_REVERB_APP_KEY;
if (!key) return;
// ... Echo setup with import.meta.env.VITE_REVERB_HOST/PORT/SCHEME
```

After:
```js
import { getReverbConfig } from './reverb-config.js';
const cfg = getReverbConfig();
if (!cfg) return;
 // ... Echo setup with cfg.key/host/port/scheme
```

Polling (`startPolling()`) is always started first; realtime upgrades it when `cfg` is valid, otherwise the widget stays polling — no throw, no broken page.

### CSP — `app/Http/Middleware/SecurityHeaders.php`

Before:
```php
"connect-src 'self'"
```

After:
```php
'connect-src '.ReverbConfig::cspConnectSrc()
// "'self'" when not configured or malformed
// "'self' ws://127.0.0.1:8081 wss://127.0.0.1:8081" when configured
```

All other directives untouched (Todo 20 audit confirmed `script-src 'unsafe-inline'` must stay for AdminLTE).

---

## 3. Before/after artifact greps — the pass/fail gate

A phpunit test alone cannot catch this bug: the source was always correct; the **artifact** was wrong. The gate is a grep on the **emitted** chunks after `npm run build` on a machine **without** `REVERB_*` in the environment (the builder's env).

### How to reproduce in one command (future reviewer)

```bash
npm run build && node -e "const fs=require('fs'); const m=JSON.parse(fs.readFileSync('public/build/manifest.json','utf8')); const c=fs.readFileSync('public/build/'+m['resources/js/client-chat.js'].file,'utf8'); console.log('chat.typing '+(c.includes('chat.typing')?'FOUND':'ABSENT')); console.log('listen( '+(c.includes('listen(')?'FOUND':'ABSENT')); console.log('private( '+(c.includes('private(')?'FOUND':'ABSENT'));"
```

If the branch is build-time gated, this prints `ABSENT` for all three.

### Before (committed bundle at HEAD, and the sibling rebuild mid-audit)

Commands (auditor's proof, re-derived here):

```bash
# committed blob
git show HEAD:public/build/assets/client-chat-2O6dmx1Y.js  # 5689 bytes
node -e "const t=require('child_process').execSync('git show HEAD:public/build/assets/client-chat-2O6dmx1Y.js',{encoding:'utf8'}); ['chat.typing','showTyping','listen(','private(','Echo'].forEach(n=>console.log(n+': '+(t.includes(n)?'FOUND':'ABSENT')))"
```

Result verbatim:

```
committed client-chat-2O6dmx1Y.js
chat.typing: ABSENT
showTyping: ABSENT
listen(: ABSENT
private(: ABSENT
Echo: ABSENT
size 5689
...function g(){r.channel||!r.conversationId||_()}function _(){r.poller||=window.setInterval(p,t)}...
# _ is startPolling — subscribe is gone
```

Mid-audit rebuild on this machine (no REVERB_* env):

```
public/build/assets/client-chat-BYNZ1dtp.js  5759 bytes
chat.typing: ABSENT
showTyping: ABSENT
listen(: ABSENT
private(: ABSENT
Echo: ABSENT
```

`public/build/assets/echo-DYAwdQnE.js` at HEAD still contained its guard:

```
import{r as e}from"./rolldown-runtime-QTnfLwEv.js"; ... var a={BASE_URL:`/build/`,VITE_APP_NAME:`Hosting CRM`},o=a.VITE_REVERB_APP_KEY;if(!o)a.reason=`VITE...` ...
# ^ build-time inline, not runtime
```

### After (this fix, rebuilt on the same machine with no REVERB_* env — `npm run build` 2026-09-13)

```
public/build/manifest.json -> resources/js/client-chat.js = assets/client-chat-B9I7dJDh.js (6613 bytes)
public/build/manifest.json -> resources/js/echo.js = assets/echo-BeIpdjVQ.js (950 bytes)
public/build/manifest.json -> _reverb-config-2MAUsTYn.js (74KB, shared)
```

```bash
node -e "const fs=require('fs'); const t=fs.readFileSync('public/build/assets/client-chat-B9I7dJDh.js','utf8'); ['chat.typing','listen(','private('].forEach(n=>console.log(n+': '+(t.includes(n)?'FOUND':'ABSENT')))"
```

Result verbatim:

```
built client-chat-B9I7dJDh.js (AFTER FIX)  6613 bytes
chat.typing: FOUND
listen(: FOUND
private(: FOUND
# tail: ...a=new n({broadcaster:`reverb`,key:e.key,wsHost:e.host,wsPort:e.port,...}),t.channel=a.private(`chat.conversation.${t.conversationId}`),t.channel.listen(`.chat.message.new`,...),t.channel.listen(`.chat.typing`,...) }catch{t.channel=null}...
```

```bash
node -e "const fs=require('fs'); const t=fs.readFileSync('public/build/assets/echo-BeIpdjVQ.js','utf8'); console.log(t.slice(0,400))"
```

```
import{r as e}from"./rolldown-runtime...";import{n as t,r as n,t as r}from"./reverb-config-2MAUsTYn.js";var i=e(t(),1);window.Pusher=i.default;var a={enabled:!1,echo:null,reason:``},o=r();if(!o)a.reason=`Reverb is not configured...`;else try{let e=o.scheme===`https`;a.echo=new n({broadcaster:`reverb`,key:o.key,wsHost:o.host,wsPort:o.port,wssPort:o.port,forceTLS:e,...}),a.enabled=!0,window.Echo=a.echo}catch...
# ^ runtime `o=r()` reading window.__REVERB__, not a.VITE_REVERB_APP_KEY
```

```bash
node -e "const fs=require('fs'); const b=fs.readFileSync('public/build/assets/reverb-config-2MAUsTYn.js','utf8'); console.log('window.__REVERB__ '+b.includes('window.__REVERB__')); console.log('VITE_REVERB_APP_KEY '+b.includes('VITE_REVERB_APP_KEY'))"
```

```
window.__REVERB__ true
VITE_REVERB_APP_KEY true  # dev fallback preserved, primary is runtime
```

`public/build/assets/chat-Cl4iLjeI.js` (25KB) now has:

```
chat.typing: FOUND
listen(: FOUND
private(: FOUND
```

**Verdict: the realtime branch now survives a production build performed without any `REVERB_*` env. Before it was absent; after it is present.**

Current build is **left unstaged** on purpose — `public/build/` is tracked and Todo 21 owns the single final rebuild. `git status --porcelain -- public/build` shows 7 `D`, 7 `??`, 1 `M` (manifest). The fix is proven by the on-disk build; the commit does not touch `public/build/`.

---

## 4. Secret-leak test — the most important test in this task

`tests/Feature/Chat/RealtimeRuntimeConfigTest.php::test_the_app_secret_never_appears_in_rendered_html_of_any_page_type`

- Sets `config('broadcasting.connections.reverb.secret')` to a random `super-secret-REVERB-<hex>` (and also a public key so the config renders).
- Fetches **three** page types and asserts the secret string is absent from each:
  1. `GET /admin/chat` as a user with `chat.view` (admin page — `master.blade.php` + `head` + `chat.js`)
  2. `GET /login` as guest (login page — `auth-master.blade.php` + `head` + widget)
  3. `GET /client` as an authenticated `client` user with a `Customer` row (client portal — `master.blade.php` + `head` + widget via `@unless admin`)
- Additionally asserts `ReverbConfig::forClient()` never contains `secret` or `REVERB_APP_SECRET` keys and its JSON does not contain the secret.
- A second test `test_for_client_never_exposes_secret_even_when_config_has_it` pins the helper directly.

All three pages share the same `ReverbConfig::forClient()` path. No page renders `REVERB_APP_SECRET`. The Blade renders `window.__REVERB__ = @json($_reverb)` where `$_reverb` is the filtered array; `@json` is escaped and the array never holds the secret.

**Result: `RealtimeRuntimeConfigTest` 15/15 pass; full `--filter Chat` 370/370 pass. The app secret cannot reach a browser.**

---

## 5. CSP branches

`app/Http/Middleware/SecurityHeaders.php` now delegates `connect-src` to `ReverbConfig::cspConnectSrc()`:

- **Not configured** (`key` null/empty): `connect-src 'self'`
- **Malformed** (key present but host empty, port `not-a-port`/`99999`/`-1`/`0`/`''`, scheme `ftp`, host containing CSP-breaking chars): remains `'self'` — does **not** widen. Tested in `test_csp_does_not_widen_on_malformed_port`, `test_csp_does_not_widen_on_malformed_scheme`, `test_csp_does_not_widen_when_host_is_empty`.
- **Configured** (`key=k`, `host=127.0.0.1`, `port=8081`, `scheme=http`): `connect-src 'self' ws://127.0.0.1:8081 wss://127.0.0.1:8081`

Live header probes (curl against `APP_URL` at `.env` `APP_URL=http://managehosting.local`):

```
# .env has no REVERB_* (default)
curl -I http://managehosting.local/login
Content-Security-Policy: ...; connect-src 'self'; frame-ancestors 'self'; ...

# .env temporarily has REVERB_APP_KEY=test-reverb-key-abc123, REVERB_HOST=127.0.0.1, REVERB_PORT=8081, REVERB_SCHEME=http
curl -I http://managehosting.local/login
Content-Security-Policy: ...; connect-src 'self' ws://127.0.0.1:8081 wss://127.0.0.1:8081; frame-ancestors 'self'; ...
```

Remaining directives (`default-src 'self'`, `script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net`, `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`, `frame-ancestors 'self'`) unchanged and asserted in `test_csp_header_has_expected_base_directives`.

---

## 6. Adversarial table

| Class | Observable | Result |
|---|---|---|
| **stale_state** (committed artifact disagreeing with source) | `git show HEAD:public/build/assets/client-chat-2O6dmx1Y.js` has none of `chat.typing`/`listen(`/`private(`; `public/build/assets/client-chat-B9I7dJDh.js` built without `REVERB_*` env has all of them via runtime `window.__REVERB__`. Future reviewer checks in one command: `npm run build && node -e "const fs=require('fs'); const m=JSON.parse(fs.readFileSync('public/build/manifest.json','utf8')); const c=fs.readFileSync('public/build/'+m['resources/js/client-chat.js'].file,'utf8'); console.log(c.includes('chat.typing')?'FOUND':'ABSENT')"` | Source and artifact now agree; a build without env still ships the branch. Public/build left unstaged — Todo 21 must rebuild and commit. |
| **misleading_success_output** (350 green tests coexisted with absent realtime branch) | No phpunit test can catch elimination of a **committed artifact** — the source was always correct. The new `test_built_artifact_contains_realtime_code_not_eliminated` greps the **emitted** `public/build/assets/client-chat-*.js` for `chat.typing`/`listen(`/`private(` and would have been red before this fix. The auditor's `npm run build` + grep is the only gate that turns from absent to present. | Artifact grep is the headline pass/fail; phpunit now includes it. |
| **prompt_injection / secret leakage** | Rendered HTML of `/admin/chat`, `/login`, `/client` grepped for the exact `REVERB_APP_SECRET` value set in config; also `ReverbConfig::forClient()` JSON grepped. All three pages plus the helper return absent. | Secret never reaches HTML or the browser bundle. |
| **malformed_input** | `getReverbConfig()` receives `key` with empty host, host but no port, `port='not-a-port'/'99999'/'-1'/'0'/''`, `scheme='ftp'` or host with invalid chars. `ReverbConfig::cspConnectSrc()` and `getReverbConfig()` both return degraded values: JS returns `null` (caller falls back to 5s polling in `client-chat.js` and to `polling` banner in `chat.js`), CSP stays `'self'`. No throw, no broken page. Tests: `test_csp_does_not_widen_on_malformed_*`, `ReverbConfig` host/port/scheme validation, and JS `try/catch` returning null. | Degrades to polling, never throws. |
| **dirty_worktree** | `git status --porcelain \| Measure-Object -Line` before: 202, after: 212. `git show --stat HEAD` not used for commit yet. Planned commit `fix(chat): resolve Reverb config at runtime so realtime survives the build` will stage **only** by name: `app/Support/ReverbConfig.php`, `resources/js/reverb-config.js`, `resources/js/echo.js`, `resources/js/client-chat.js`, `resources/views/vendor/adminlte/partials/head.blade.php`, `app/Http/Middleware/SecurityHeaders.php`, `tests/Feature/Chat/RealtimeRuntimeConfigTest.php`, `tests/Feature/Chat/EchoBootstrapTest.php`, and this evidence file — and **nothing under `public/build/`**. | No `git add -A`/`git stash`/`reset`; build proof left unstaged as required. |
| **cancel_resume** | Single task, no mid-flight cancellation requested. | Not applicable — no cancel/resume path exercised. |
| **hung_commands** | `npm run build` (7.6s), `phpunit --filter Chat` (123s), `curl` probes all completed within timeout; no command hung. | Not applicable — no hung command observed. |
| **flaky_tests** | Suite run 370/370 stable; the previous 6 non-Chat failures (BOM in `resources/views/admin/**`, ticket view) unchanged and outside Chat filter. | Not applicable — Chat suite deterministic; full-suite 6 pre-existing not introduced here. |
| **repeated_interruptions** | Single executor, no sibling claiming this lane; shared browser panel re-opened before each measurement as instructed. | Not applicable — no repeated interruptions. |

---

## 7. Verify

```
php artisan config:clear
php vendor/phpunit/phpunit/phpunit --filter Chat        # 370/370 (baseline 350 + 15 new + 5 sibling) — no regression
npm run build                                          # 7.66s, 74 modules, no errors
vendor/bin/pint --test app/Support/ReverbConfig.php app/Http/Middleware/SecurityHeaders.php
curl -I http://managehosting.local/login                # connect-src 'self' vs 'self' ws://...wss://... as above
```

Manual QA (`openchamber_web`):

- No Reverb process runs on this machine (`BROADCAST_CONNECTION=log`, no `REVERB_*` in `.env` by default). Live socket delivery **cannot be proven** here and is not claimed.
- What is proven instead:
  - (a) The client **does attempt** a connection at runtime (the code path `getReverbConfig() -> new Echo -> private(listen)`) is present in the emitted artifact, whereas before it was absent. A failed WebSocket attempt visible in the console (e.g., `WebSocket connection to ws://127.0.0.1:8081 failed`) after configuring a key would be a **PASS** — it proves the code is live and only the server is missing.
  - (b) The CSP **would permit** that attempt when configured (see curl headers above) and **would not** permit it otherwise.
- The browser panel is shared with sibling sessions and is frequently hijacked; re-opened immediately before each snapshot as instructed. No console errors beyond the expected `Reverb is not configured` reason when unconfigured.

BOM: `app/Support/ReverbConfig.php`, `app/Http/Middleware/SecurityHeaders.php`, `resources/views/vendor/adminlte/partials/head.blade.php`, `resources/js/echo.js`, `resources/js/client-chat.js`, `resources/js/reverb-config.js` all report `EF BB BF` absent (clean).

`.env` was temporarily appended with `REVERB_APP_*` for the `curl -I` configured-branch probe, then restored byte-for-byte via `Move-Item .env.bak.realtime -> .env` and `php artisan config:clear`. Final `Get-Content .env | Select-String REVERB` shows **0 matches**, and `git diff -- .env` is empty (`.env` is `.gitignore`'d). A backup `.env.bak.realtime` was created and removed; no secret was committed.

---

## 8. Todo 21 note — do not skip

Todo 21 must run `npm run build` and **commit** `public/build/` (manifest + 7 `D`/`??` assets) in its own commit. The current build on disk (`client-chat-B9I7dJDh.js`, `echo-BeIpdjVQ.js`, `reverb-config-2MAUsTYn.js`) proves the fix, but it is **not staged** here. Until Todo 21 commits it, the repository's `HEAD` still ships the old `client-chat-2O6dmx1Y.js` artifact and reinstalls remain polling-only. Verify again after Todo 21 with the one-command check in §1.

---

## 9. Changed files (this commit stages only these, by name — no `public/build`)

- `app/Support/ReverbConfig.php` (new) — request-time public config + CSP helper, never exposes secret
- `resources/js/reverb-config.js` (new) — runtime reader (`window.__REVERB__` primary, `import.meta.env` dev fallback) with strict malformed-input degradation
- `resources/js/echo.js` — reads `getReverbConfig()`, no build-time `VITE_REVERB_APP_KEY` gate
- `resources/js/client-chat.js` — same, plus `import { getReverbConfig }`
- `resources/views/vendor/adminlte/partials/head.blade.php` — renders `window.__REVERB__ = @json(...)` when configured
- `app/Http/Middleware/SecurityHeaders.php` — `connect-src` via `ReverbConfig::cspConnectSrc()`
- `tests/Feature/Chat/RealtimeRuntimeConfigTest.php` (new) — 15 tests: presence/absence, secret never in HTML, CSP both branches, malformed, artifact grep
- `tests/Feature/Chat/EchoBootstrapTest.php` — updated to assert runtime mechanism (was asserting build-time `VITE_*` in `echo.js`)
- `.omo/evidence/slack-like-chat-system/fix-realtime-runtime-config.md` — this file

Not touched (per scope): `app/Support/ChatMessagePayload.php`, `database/seeders/**`, `app/Services/ChatService.php`, `app/Http/Controllers/**`, `routes/**`, `resources/views/admin/chat/**`, `resources/css/adminlte.css`, `resources/views/admin/**`.

`public/build/**`: built to prove (`npm run build` — 7.6s), grepped (FOUND after), **left unstaged**.
