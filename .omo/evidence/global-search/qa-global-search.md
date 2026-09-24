# Global Search — agent-executed browser QA (plan todo 13)

- **Date:** 2026-09-24
- **Repo:** `C:\Users\Administrator\Local Sites\managehosting\app` (Laravel 13, PHP 8.4.16, Windows PowerShell 5.1)
- **Host under test:** `http://managehosting.local` — the live Herd host, already running. **No server, watcher or long-running command was started.**
- **Toolchain:** Playwright 1.63.0 (repo devDependency, bundled Chromium, headless); PHPUnit via `php artisan test --without-tty`.
- **Timebox:** every bash call carried an explicit timeout; the browser scripts self-kill at 13 min / 6 min; per-step Playwright timeouts are 10–30 s. Actual browser wall time ≈ 3.5 min (run 1) + 1.5 min (rerun 1) + 1 min (rerun 2).
- **Product code was NOT touched.** The only product-tree files created were two temporary QA artifacts (a PHPUnit test and Playwright scripts); all are deleted, receipts in the cleanup section.

## Verdict summary

| Case | What was checked | Verdict |
| --- | --- | --- |
| (a) | Ctrl/Cmd+K palette → Records group, keyboard selection, Enter → record URL | **PASS** |
| (b) | `/admin/search?q=` grouped page, "View all", click → show page | **PASS** |
| (c) | `/admin/chat` Ctrl/Cmd+K → only the chat switcher; elsewhere only the palette | **PASS** |
| (d) | Role isolation of Servers/SSL/hosting-account groups | **LITERAL WORDING FAIL / INTENT PASS** — plan-wording GAP, not a product defect (see below) |
| (e) | `q=%` wildcard isolation on an isolated DB | **PASS** (with a short-query contract nuance recorded) |
| (f) | 375 px mobile layout | **PASS** |

## Environment facts (read-only probes, live MySQL DB)

Probe (temp script, since deleted) booted the app and printed:

```
DB_CONNECTION=mysql
USERS:
1 | admin@localhost.com | role=admin | roles=admin
2 | somenath@hotmail.com | role=client | roles=
CUSTOMERS total=1
  CUSTOMER #1 company=KC email=somenath@hotmail.com
SERVERS=1 SSL=0 HOSTING=5 DOMAINS=0
  SERVER #1 name=Some-Hyper-V ip=10.1.3.133
```

- **No seeded `acme` rows exist.** The seed's customer is `KC / somenath@hotmail.com`, so the positive cases use `q=somenath` (the instruction allowed "a seeded customer email (or `acme`)"). `q=acme` is captured as the negative/empty case in (b).
- Login: `admin@localhost.com` / `Admin@123` (password verified with `Hash::check` before use). Login page is `GET /admin` (`admin.login`); form fields are `input[name="email"]`, `input[name="password"]`, `button[type="submit"]`.
- `security_math_captcha_enabled=false`, so no captcha field is rendered.

### Upgrade-path precondition (recorded because it changes what case (d) can see)

Before QA, migration `2026_09_23_000001_backfill_search_permission` was **Pending** on the live DB:

```
2026_09_23_000001_backfill_search_permission .. Pending

ROLE support: perms=activity.view,chat.*,customers.view,dashboard.view,domains.view,email.view,hosting.view,
  invoices.view,kb.*,payments.view,tickets.*           <- no `search`
ROLE staff:   perms=chat.*,customers.view,dashboard.view,domains.view,invoices.view,kb.view,products.view,
  tickets.view                                         <- no `search`
```

The plan says this QA runs against a seeded instance and the backfill is the documented upgrade path, so it was applied once:

```
command: php artisan migrate --force --no-ansi
observed: 2026_09_23_000001_backfill_search_permission .. 47.28ms DONE

after:
ROLE support: ... ,tickets.view,search
ROLE staff:   ...,tickets.view,search
```

Only that one pending migration ran (verified by `migrate:status`; every other migration showed `Ran`). The migration is additive and idempotent; **no product code changed.**

---

## Case (a) — palette Records group + keyboard selection + Enter → record URL — PASS

**Actions (Playwright, admin session):** `goto /admin/dashboard` → `press Control+k` → wait for `#adminlteCommandPalette:not([hidden])` → wait for `document.activeElement.id === 'adminlteCommandPaletteInput'` → `type "somenath"` (40 ms delay) → wait for `.adminlte-cmdk__group` containing `Customers`.

**Selectors:** `#adminlteCommandPalette`, `#adminlteCommandPaletteInput`, `#adminlteCommandPaletteResults [role="option"]`, `.adminlte-cmdk__group`.

**Observed (raw log `qa-browser-log.json`):**

```
a_palette_open             = true
a_input_focused            = "adminlteCommandPaletteInput"
a_group_headers            = ["Customers"]
a_rows                     = [
  {"id":"adminlteCmdkOption0","active":true,"ariaSelected":"true","text":"KCsomenath@hotmail.com","small":"somenath@hotmail.com"},
  {"id":"adminlteCmdkOption1","active":false,"ariaSelected":"false","text":"View all results","small":""}]
a_activedescendant         = "adminlteCmdkOption0"
ArrowDown -> a_active_after_arrowdown       = "View all results"
             a_activedescendant_after_down  = "adminlteCmdkOption1"
ArrowUp   -> a_active_after_arrowup         = "KCsomenath@hotmail.com"
             a_activedescendant_after_up    = "adminlteCmdkOption0"
Enter     -> a1_enter_url                   = "http://managehosting.local/admin/customers/1"   <- the record's show page
```

**Literal ArrowDown + Enter on a two-record dataset (`q=some`, logs `qa-browser-log.json` + `qa-browser-log-rerun2.json`):**

```
a2_rows                  = ["KCsomenath@hotmail.com","Some-Hyper-V10.1.3.133 · hyperv","View all results"]
ArrowDown -> a2_active_after_arrowdown = "Some-Hyper-V10.1.3.133 · hyperv"
Enter     -> a2_enter_url              = "http://managehosting.local/admin/servers/1"
             a2_landed_heading         = "Some-Hyper-V"
```

**Conclusion:** the Records group renders for a seeded record; selection is keyboard-driven (`aria-activedescendant` + `aria-selected` + `.active` all move); Enter navigates to the server-side-resolved record URL. For the one-customer dataset the record is row index 0, so ArrowDown moves to the "View all results" row (flat-list order, by design) — the record URL is reached with ArrowUp + Enter, and ArrowDown + Enter reaches a record URL when a second record row exists. Screenshots: `qa-a-records.png`, `qa-a2-server-record.png`; DOM dump: `qa-a-palette-rows.html`.

## Case (b) — `/admin/search?q=…` grouped page — PASS

**Action:** `goto /admin/search?q=somenath` → wait `.mh-search__group` → read group titles, "View all" hrefs, result links → click the result row link.

**Selectors:** `.mh-search__group .card-title`, `.mh-search__group a[aria-label^="View all"]`, `[data-search-result] a.table-link`.

**Observed (`qa-browser-log.json`):**

```
b_group_titles   = ["Customers (1)"]
b_view_all_hrefs = ["http://managehosting.local/admin/customers?search=somenath"]
b_result_links   = [{"text":"KC","href":"http://managehosting.local/admin/customers/1"}]
click            -> b_click_landing = "http://managehosting.local/admin/customers/1"
                    b_click_page_heading = "Somenath Patr"
```

`q=acme` (no such seeded row) rendered the empty state, not a broken page:

```
b_acme_empty_state = true
b_acme_empty_text  = "No results found for “acme”\n\nTry a different spelling, a customer email, an invoice number, or a domain name."
```

Screenshot: `qa-b-page.png`. **Conclusion:** grouped header with capped count, "View all" deep link carrying the `search` query param, and a result row that lands on the record's show page.

## Case (c) — Ctrl/Cmd+K precedence on `/admin/chat` — PASS

**Action:** `goto /admin/chat` → wait `#chat-switcher` (state `attached`) → `press Control+k` → read both overlays; then `goto /admin/dashboard` → `press Control+k`.

**Selectors:** `#chat-switcher` (`d-none` = closed), `#adminlteCommandPalette` (`[hidden]`), `#adminlteCommandPaletteInput`.

**Observed (`qa-browser-log-rerun2.json`):**

```
c_chat_url                   = "http://managehosting.local/admin/chat"
c_chat_flag                  = true          (window.__mhChatShortcuts)
c_switcher_at_rest_hidden    = true
Ctrl+K on /admin/chat:
c_chat_switcher_visible      = true          (chat switcher opened)
c_palette_hidden_on_chat     = true          (palette stayed hidden)
c_palette_input_visible      = false
Escape                       -> c_switcher_closed_after_escape = true
Elsewhere (/admin/dashboard), Ctrl+K:
c_elsewhere_palette_open     = true          (only the palette opens)
c_elsewhere_switcher_exists  = false
```

Screenshot: `qa-c-chat-palette.png`. **Conclusion:** one keystroke opens exactly one overlay on the chat page (the switcher), and on other admin pages only the palette opens — order-independent, as designed.

## Case (d) — Servers/SSL/hosting-account group isolation — LITERAL FAIL / INTENT PASS

The case as worded expects a **support**-role viewer to see no Servers/SSL/hosting-account groups. No support or staff user was seeded, so temporary users were created for QA and deleted afterwards (mechanism note below).

**Observed with the temporary support user (`qa-temp-support@example.test`, role `support`):**

```
d_support_hyper    = {url:"/admin/search?q=Hyper", forbidden_403:false,
                      group_titles:["Servers (1)"], empty_state:false, result_rows:1}
d_support_somenath = {group_titles:["Customers (1)"], result_rows:1}
```

→ **Support DOES see the Servers group.** This is exactly what `database/seeders/AdminLteRbacSeeder.php:206` grants (`support` holds `hosting.view`) and what plan line 36 + todo 6 acceptance (line 171: "a `support` user (has `hosting.view`) gets the server group") require. The literal wording of case (d) is therefore **not satisfiable by design**.

**Intent-equivalent check — the `staff` role, which deliberately lacks `hosting.view` (`AdminLteRbacSeeder.php:254`):**

```
d_staff_hyper    = {url:"/admin/search?q=Hyper", forbidden_403:false,
                    group_titles:[], empty_state:true, result_rows:0}
d_staff_somenath = {group_titles:["Customers (1)"], result_rows:1}      <- non-vacuity: staff is not broken
```

→ **PASS for the actual isolation property:** a role without `hosting.view` gets no Servers/hosting-account group even though the matching row exists (same query returns `Servers (1)` for support/admin). Screenshots: `qa-d-support.png` (support, `q=Hyper`), `qa-d-staff.png` (staff, `q=Hyper`).

**GAP (plan wording, not product):** todo-13 case (d) names `support` as the role that must not see Servers/SSL, contradicting plan line 36 and todo 6's acceptance criteria. Product behaviour is correct; the case's role label is wrong. No product code was changed.

**Notes:** (1) SSL is not observable on the live seed at all — `ssl_certificates` count is 0 and empty groups are never emitted (`GlobalSearchService.php:99-101`) — but SSL shares the `hosting.view` gate proven above by the servers group. (2) The temp users were created with a temp boot script instead of `php artisan tinker --execute` because PowerShell 5.1 argument quoting mangled every `--execute` string into a PHP parse error (three attempts); the effect is identical (same model/role assignment, local dev DB) and both users were deleted.

## Case (e) — wildcard isolation on an isolated database — PASS

**Command (isolated DB):** a temporary PHPUnit test `tests/Feature/QaCaseEWildcardIsolationTest.php`, run on the phpunit config's isolated DB (`phpunit.xml: DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`) — never the live MySQL DB:

```
php artisan test --without-tty --filter=QaCaseEWildcardIsolation --no-ansi
-> {"tool":"phpunit","result":"passed","tests":2,"passed":2,"assertions":18,"duration_ms":4294}
```

**Fixtures:** `QaRow 100% Uptime` (contains a literal `%`), `QaRow 100x Uptime` (no literal `%`, the wildcard twin), `QaRow 0_ Legacy`, `QaRow 0X Legacy`; viewer holds `search` + `customers.view`.

**What the assertions observed (HTTP-level, real routes):**

| Query | Surface | Observed |
| --- | --- | --- |
| `q=%` (one char) | `GET /admin/search?q=%25` | 200 with **zero** `data-search-result` rows (short-query path; no full-table dump) |
| `q=%` (one char) | `GET /admin/search/typeahead?q=%25` | 200 `{"query":"","groups":[],"total":0}` |
| `q=100%` | `GET /admin/search?q=100%25` | sees `QaRow 100% Uptime`, does **not** see `QaRow 100x Uptime`; exactly 1 result row |
| `q=100%` | `GET /admin/search/typeahead?q=100%25` | `total=1`, label `QaRow 100% Uptime`, url `route('admin.customers.show', $literal)` |
| `q=100x` | typeahead | returns `QaRow 100x Uptime` (non-vacuity: the twin exists and is findable) |
| `q=0_` | typeahead | only `QaRow 0_ Legacy` (underscore also literal) |

**Contract nuance (observed, matches the plan's min-2-char rule):** a lone `%` is one character, so both surfaces short-circuit before any provider query — `q=%` therefore returns no rows *even when a literal-`%` row exists*. The literal-vs-wildcard isolation the case is after is proven with the multi-character `q=100%` / `q=0_`. This is the plan's own `mb_strlen >= 2` contract (plan lines 63/188), not a product defect; plan line 91's `q=%` phrasing is imprecise on this point.

**Cleanup receipt:** `Test-Path tests/Feature/QaCaseEWildcardIsolationTest.php -> True` before delete, `False` after.

## Case (f) — 375 px mobile viewport — PASS

**Action:** admin session, `setViewportSize(375, 812)`, `goto /admin/search?q=somenath`, wait `.mh-search__group`, screenshot `fullPage: true`.

**Observed (`qa-browser-log.json`):**

```
f_viewport            = [375,812]
f_horizontal_overflow = false        (documentElement.scrollWidth <= innerWidth)
f_group_titles        = ["Customers (1)"]
f_result_rows         = 1
f_input_visible       = true
```

Screenshot: `qa-f-mobile.png`. (SHA-256 `f1f704a1…97ee4f` is identical to task-11's `mobile-375-grouped.png` — same page, query and viewport, so Chromium rendered byte-identical output; this is determinism, not a copied file.)

---

## Cleanup receipts

```
php qa-users.php delete
  DELETED qa-temp-support@example.test id=3 remaining=0
  DELETED qa-temp-staff@example.test   id=4 remaining=0
Test-Path tests/Feature/QaCaseEWildcardIsolationTest.php  -> True  before, False after delete
Test-Path qa-todo13-temp.cjs / qa-todo13-rerun.cjs / qa-todo13-rerun2.cjs -> False after delete
Test-Path %TEMP%\2\opencode\qa-users.php and dbprobe.php  -> False after delete
```

No temp user, script, or fixture remains. Intended evidence files kept: this report, the screenshots, `qa-a-palette-rows.html`, and the three raw JSON logs.

## Raw evidence files

`qa-browser-log.json` (cases a1/a2/b/c/f/d run 1), `qa-browser-log-rerun.json` (a2 instrumentation + rerun-1 attempt), `qa-browser-log-rerun2.json` (a2 definitive + case c corrected), `qa-a-palette-rows.html`, `qa-a-records.png`, `qa-a2-server-record.png`, `qa-b-page.png`, `qa-c-chat-palette.png`, `qa-d-support.png`, `qa-d-staff.png`, `qa-f-mobile.png`.

## GAPS / follow-ups

1. **Plan wording (case d):** the case names `support` as the role that must see no Servers/SSL groups; the seeder deliberately gives support `hosting.view`. Product is correct — the plan text should name `staff` (verified PASS) or a role without `hosting.view`. No product code changed.
2. **Upgrade note (environment, not product):** on this upgraded live DB the `backfill_search_permission` migration was pending, so `support`/`staff` were locked out of `/admin/search` (would 403) until `php artisan migrate` was run. After `php artisan migrate --force` both roles gained `search` and all non-admin flows worked. Upgraders must run migrations (as the plan states).
3. **SSL group unobservable** on the live seed (0 certificates); the shared `hosting.view` gate is proven via the Servers group.
4. **Literal ArrowDown + Enter** for the single-row customer dataset selects the "View all results" row (flat list order); the record URL was proven with ArrowUp + Enter and via the two-record dataset. UX is by design; noted for the record.
