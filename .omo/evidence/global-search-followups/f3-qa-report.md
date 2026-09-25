# F3 real-browser QA — global-search follow-ups (FU-4 error slot, FU-1 gating, regression)

- **Date:** 2026-09-25
- **Repo:** `C:\Users\Administrator\Local Sites\managehosting\app` (Windows PowerShell 5.1)
- **Host under test:** `http://managehosting.local` — live Herd host, already running. **No server, watcher, or long-running command was started.**
- **Toolchain:** Playwright 1.63.0 (repo devDependency, bundled Chromium, headless); temp PHP boot scripts for DB probes + temp users (tinker `--execute` quoting is broken on PS 5.1, same as the previous run).
- **Timebox:** per-step Playwright timeouts 10–30 s; script self-kill at 10 min. Actual browser wall time ≈ 1.5 min, single run, no reruns needed.
- **Product code was NOT touched.** No migration was run (`migrate:fresh` never invoked). Only temp QA artifacts were created; all deleted (receipts below).

## Environment facts (read-only probe of the live MySQL DB, `local`)

- `USERS=2`: `admin@localhost.com` (role `admin`), `somenath@hotmail.com` (no role). Admin login `admin@localhost.com` / `Admin@123`; login page `GET /admin`, fields `input[name="email"]`, `input[name="password"]`, `button[type="submit"]`.
- Permissions `service-instances.manage`, `service-instances.view`, `search`, `hosting.manage`, `hosting.view` all exist; 7 roles seeded.
- `service_instances=5` with `service_tag` `HOST-1/15/17/18/19` (usernames `host11…`), so the FU-1 check used the **service-instances group directly — no substitution needed**.
- `SearchPageRequest` rule is `q: nullable|string|max:100`, so a 101-char query is the FU-4 trigger.

## Verdict summary

| Case | What was checked | Verdict |
| --- | --- | --- |
| (i) | FU-4 error slot: 101-char query via form submit + direct navigate | **PASS** |
| (ii) | FU-1 gating: manage-only vs search-only temp users on `q=HOST-1` | **PASS** |
| (iii) | Regression: Ctrl+K palette + `q=acme` renders normally | **PASS** |

## Case (i) — FU-4 error slot — PASS

**Command:** admin session → `goto /admin/search?q=acme` → fill `[data-search-form] input[name="q"]` with `'a'.repeat(101)` → click submit → read `[data-search-error]` + input attributes; then `goto /admin/search?q=<101 a's>` directly.

**Selectors:** `[data-search-form]`, `[data-search-error]`, `[data-search-form] input[name="q"]`.

**Observed (raw log `f3-browser-log.json`):**

```
form-submit path:
  i_submit_final_url       = "http://managehosting.local/admin/search?q=acme"   (redirect-back, q param len 4)
  i_error_count            = 1
  i_error_text             = "The q field must not be greater than 100 characters."
  i_error_role             = "alert"
  i_input_class            = "form-control  is-invalid "
  i_input_aria_invalid     = "true"
  i_input_aria_describedby = "search-q-error"
direct-navigate path:
  i_direct_final_url   = "http://managehosting.local/admin/search?q=acme"
  i_direct_error_count = 1
  i_direct_error_text  = "The q field must not be greater than 100 characters."
```

Screenshots: `f3-i-error.png`, `f3-i-error-direct.png`. The overlong `q` no longer looks like a silent redirect: the message renders in the `role=alert` slot and the input carries `is-invalid` + `aria-invalid` + `aria-describedby`.

## Case (ii) — FU-1 gating — PASS

**Temp fixtures (temp boot script `f3-users.php`, since deleted):** role `f3-manage-only` holding ONLY `service-instances.manage` + `search` with user `f3-manage@example.test`; role `f3-search-only` holding ONLY `search` with user `f3-searchonly@example.test`. Password `Temp@12345` for both.

**Command:** each temp user logs in, `goto /admin/search?q=HOST-1`, read `.mh-search__group .card-title` + `[data-search-result]` rows.

**Observed:**

```
manage-only (service-instances.manage + search), HTTP 200:
  ii_manage_groups = [" Service Instances (5)"]
  ii_manage_rows   = ["HOST-1 — host11", "HOST-19 — host1919", "HOST-18 — host18181818",
                      "HOST-17 — host1717", "HOST-15 — host151515"]
  ii_manage_empty  = 0
search-only (search alone), HTTP 200:
  ii_search_groups = []
  ii_search_rows   = []
  ii_search_empty  = 1        (empty state, not a 403)
```

Screenshots: `f3-ii-manage.png`, `f3-ii-searchonly.png`. The manage-only viewer SEES the group (FU-1 expansion works in the browser); the search-only viewer sees NONE of it while the page still returns 200 with the empty state. No hosting-trio fallback was needed — the live DB holds 5 service-instance rows and the provider searches `service_tag/username/domain`.

## Case (iii) — regression — PASS

**Command:** admin session → `goto /admin/dashboard` → `press Control+k` → read `#adminlteCommandPalette` hidden state + `document.activeElement.id`; then `goto /admin/search?q=acme`.

**Observed:**

```
iii_palette_open    = true
iii_palette_focused = "adminlteCommandPaletteInput"
iii_acme_url        = "http://managehosting.local/admin/search?q=acme"
iii_acme_groups     = []
iii_acme_empty      = 1
iii_acme_error      = 0
```

Screenshot: `f3-iii-palette.png`. The palette opens with its input focused (not broken by the FU-4 blade change) and `q=acme` still renders the normal empty state with no error slot.

## Cleanup receipts

```
php f3-users.php delete
  DELETED f3-manage@example.test id=6
  DELETED f3-searchonly@example.test id=7
  DELROLE f3-manage-only
  DELROLE f3-search-only
  REMAIN_MANAGE=0
  REMAIN_SEARCH=0
Test-Path f3-qa.cjs    -> False
Test-Path f3-users.php -> False
Test-Path f3-probe.php -> False
```

No temp user, role, or script remains. Evidence kept: this report, `f3-browser-log.json`, `f3-i-error.png`, `f3-i-error-direct.png`, `f3-ii-manage.png`, `f3-ii-searchonly.png`, `f3-iii-palette.png`.

## Gaps

None. All three cases passed on the first run with observed output; no substitution, no rerun, no plan-wording discrepancy encountered.
