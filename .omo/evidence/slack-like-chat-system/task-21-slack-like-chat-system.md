# Task 21 — Migration rollback, seeder integrity, and route/config smoke

Executed 2026-09-13. HEAD `a3ff1f61` baseline on entry: `--filter Chat` = **372/372**, `composer seed:smoke` = ALL PASS.
Baseline on exit: **372/372** (same), `seed:smoke` ALL PASS, `SeederIntegrityTest` 9/9. Todo 21 also owns the single authoritative rebuild of the tracked `public/build/` directory, deferred by four earlier commits.

---

## 1. Isolated worktree rebuild — procedure and why

`public/build/` is **tracked**. Five commits changed Vite inputs without rebuilding:

- `b13cae96` (chat.js/chat.css), `74ba4c85` (client-chat.js/css), `840d4217` (search UI + keyboard shortcuts), `f71fdfc7` (runtime Reverb config — `resources/js/reverb-config.js`, echo.js, client-chat.js), `d9976811`.

On entry `git status --porcelain -- public/build` showed ~17 dirty paths from ad-hoc QA builds. HEAD shipped a bundle with **no realtime branch and no search UI** — `git show HEAD:public/build/manifest.json` contained no `reverb-config` entry and the committed `chat-*.js` contained no `chat.typing` listener.

**Why isolated:** `resources/css/adminlte.css` is a Vite input and was modified (and still is) by another live agent session. Building in the main tree would bake unreviewed, uncommitted CSS into a tracked artifact, leaving HEAD's built output not corresponding to HEAD's source. A previous lane hit this exact trap and contaminated the manifest with machine-specific keys via a `node_modules` junction.

**Recipe followed (the hard-won warnings):**

1. `git worktree add C:\Users\ADMINI~1\AppData\Local\Temp\2\opencode\chat-build-t21 HEAD` (~14k files). Confirmed `git status --porcelain -- public/build` inside the worktree was empty.
2. **No junction.** Ran `npm ci` inside the worktree (~36s, 310 packages). The previous lane's junction emitted `../../../../Local Sites/managehosting/app/node_modules/bootstrap-icons/...` keys — this build has no such contamination (see Manifest section).
3. `npm run build` **twice** — `11.23s` then `8.79s` — hashes identical (`chat-CjOZMj5x.js`, `echo-2KNG92R_.js`, `reverb-config-B3A-HBWw.js`, `client-chat-CvsIRysO.js`, `app-dguVjzu5.css`, `adminlte-zZVgOjbj.css` both runs), proving determinism.
4. Copied worktree's `public/build/` over main's, then removed five stale QA artifacts that the copy left behind (`adminlte-CV5keGZ9.css`, `chat-Cl4iLjeI.js`, `client-chat-B9I7dJDh.js`, `echo-BeIpdjVQ.js`, `reverb-config-2MAUsTYn.js`). Final on-disk asset set (20 files) matches the new manifest exactly.
5. Teardown in order: verified `node_modules` inside the worktree was a real directory (not a reparse point), then `git worktree remove --force` + `git worktree prune`. Afterwards verified main tree's `node_modules` still present with **254 entries** and `vite` + `.bin/vite` intact (counted before and after).

> `npm run build` printing "built in 8s" is **not** evidence. Only the greps below plus the rendered 200 count.

---

## 2. Artifact greps — the headline

Verified on the **main tree** after the copy (not just the worktree). Each grep is a direct string search over the emitted chunks — never trusting hashes alone, because `public/build` is shared with sibling sessions.

### Realtime branch (was ABSENT from HEAD's committed bundle — the entire reason this matters)

```
chat.typing present: True   (public/build/assets/chat-CjOZMj5x.js .contains('chat.typing'))
private   present: True     (.contains('.private('))
listen    present: True     (.contains('.listen('))
echo broadcaster present: True  (public/build/assets/echo-2KNG92R_.js .contains('broadcaster'))
  raw snippet: new n({broadcaster:`reverb`,key:o.key,wsHost:o.host,wsPort:o.port,wssPort:o.port,forceTLS:e,enabledTransports:[`ws`,`wss`],auth:{headers:{"X-CSRF-TOKEN":...}}})
```

Excerpt from `chat-CjOZMj5x.js` (the committed artifact):

```
e.echo.private(`chat.conversation.${r.conversationId}`)
  .listen(`.chat.message.new`,e=>b(e.message))
  .listen(`.chat.message.edited`,e=>b(e.message))
  .listen(`.chat.message.deleted`,...)
  .listen(`.chat.reaction.toggled`,...)
e.echo.join(`chat.typing.${r.conversationId}`).listen(`.chat.typing`,e=>{e.user_id!==r.userId&&w(e.user_name,e.typing)})
e.echo.join(`chat.presence`).here(...).joining(...).leaving(...)
```

### `window.__REVERB__` handling from `resources/js/reverb-config.js`

```
__REVERB__ in reverb-config-B3A-HBWw.js: True (1 occurrence)
getReverbConfig chunk present as separate entry (_reverb-config-B3A-HBWw.js)
raw snippet: try{let e=window.__REVERB__;if(e&&typeof e==`object`&&typeof e.key==`string`&&e.key.trim()!==``){...}}
```

Echo reads it at runtime via `import {getReverbConfig} from './reverb-config.js'` — no build-time `import.meta.env` folding.

### Todo 15 search UI and keyboard shortcuts

```
chat-search present: True
searchPager present: True
keydown present: True
switcher present: True
```

Excerpts:

- Search panel: `i.search`, `i.searchForm`, `i.searchQuery`, `searchPager`, `searchPageLabel`, `V(e=1){...u(`/admin/chat/search?${n.toString()}`)...H(e,t)}`
- Keyboard: `document.addEventListener(`keydown`,e=>{if((e.metaKey||e.ctrlKey)&&e.key.toLowerCase()===`k`){...} if(e.key===`Escape`){...} switch(e.key){case `j`: Q(1); case `k`: Q(-1); case `r`: thread; case `e`: edit; case `/`: z(); }})`
- Channel switcher: `chat-switcher`, `switcherInput`, `switcherResults`, `J(e){...Array.from(document.querySelectorAll(`.chat-sidebar__item`))...}`

### Todo 14b client typing listener / `showTyping`

```
client chat.typing: True  (public/build/assets/client-chat-CvsIRysO.js .contains('chat.typing'))
typing present: True
```

Excerpt from `client-chat-CvsIRysO.js`:

```
t.channel.listen(`.chat.message.new`,e=>{x(!1),h(e.message),w()})
t.channel.listen(`.chat.typing`,e=>x(e.typing))
function x(e){d.typing&&(window.clearTimeout(t.typingTimer),d.typing.classList.toggle(`d-none`,!e),e&&(t.typingTimer=window.setTimeout(()=>d.typing.classList.add(`d-none`),8000)))}
d.body.addEventListener(`input`,()=>{...m(`${p(t.conversationId)}/typing`,{method:`POST`,body:{typing:!0}})...})
```

### Manifest integrity

Every `vite.config.js` input has a manifest entry, and every referenced file is tracked. The previously 500'd `/login` was caused by a missing `@vite` entry — this rebuild was validated against that exact bug.

`vite.config.js` inputs (10):
```
resources/css/app.css, resources/js/app.js, resources/css/adminlte.css,
resources/js/adminlte.js, resources/css/branding.css, resources/js/echo.js,
resources/js/chat.js, resources/css/chat.css, resources/js/client-chat.js,
resources/css/client-chat.css
```

`public/build/manifest.json` entries (21 keys, 10 polluted `_fonts`/`_rolldown` + 1 `node_modules/bootstrap-icons` + 10 Vite inputs) — each `file` value exists on disk:

```
resources/css/adminlte.css  -> assets/adminlte-zZVgOjbj.css  (isEntry)
resources/css/app.css       -> assets/app-dguVjzu5.css
resources/css/branding.css  -> assets/branding-AOw7ubVS.css
resources/css/chat.css      -> assets/chat-C4yYICbQ.css
resources/css/client-chat.css -> assets/client-chat-Bqseb-oC.css
resources/js/adminlte.js    -> assets/adminlte-Dja0xY7n.js
resources/js/app.js         -> assets/app-BvRk9kiK.js
resources/js/chat.js        -> assets/chat-CjOZMj5x.js
resources/js/client-chat.js -> assets/client-chat-CvsIRysO.js
resources/js/echo.js        -> assets/echo-2KNG92R_.js
+ _reverb-config-B3A-HBWw.js, _rolldown-runtime-QTnfLwEv.js (shared chunks)
```

Junction contamination check: `manifest.json` contains `Local Sites`? **False** — no machine-specific path leaked (the previous lane's junction build contained `../../../../Local Sites/managehosting/app/node_modules/bootstrap-icons/...`).

```
manifest contamination (Local Sites): False
hasContam False
```

Post-copy status before commit (17 paths — 7 deletions of old hashed filenames, 1 modified manifest, 9 additions; no `resources/` or `config/`):

```
 D public/build/assets/app-DMwgVQAt.css
 D public/build/assets/chat-C5CIbky0.js
 D public/build/assets/chat-CU3pBedS.css
 D public/build/assets/client-chat-2O6dmx1Y.js
 D public/build/assets/client-chat-DnJ73dta.css
 D public/build/assets/echo-CY1Ta5P1.js
 D public/build/assets/pusher-Jdx_Oomx.js
 M public/build/manifest.json
?? public/build/assets/app-dguVjzu5.css
?? public/build/assets/chat-C4yYICbQ.css
?? public/build/assets/chat-CjOZMj5x.js
?? public/build/assets/client-chat-Bqseb-oC.css
?? public/build/assets/client-chat-CvsIRysO.js
?? public/build/assets/echo-2KNG92R_.js
?? public/build/assets/reverb-config-B3A-HBWw.js
```

After `git add public/build/**` and commit, `git status --porcelain -- public/build` will be **empty** — verified in Manual QA below that served bytes equal committed bytes (no stale manifest).

---

## 3. Migration cycle (scratch sqlite — never the default connection)

`php artisan config:clear` before every run. The dev MySQL `local` DB holds chat tables and demo rows; rolling it back would drop them.

```
$env:DB_CONNECTION=sqlite; $env:DB_DATABASE=storage/seed-smoke-t21.sqlite
php artisan migrate:fresh --seed --force

== covers 6 table migrations + the chat-permission backfill (step=7) ==

php artisan migrate:rollback --step=7 --force

 2026_09_12_000007_backfill_chat_permissions .. 3.91ms DONE
 2026_09_12_000006_create_message_entity_links_table .. 12.19ms DONE
 2026_09_12_000005_create_chat_message_attachments_table .. 6.98ms DONE
 2026_09_12_000004_create_chat_reactions_table .. 6.44ms DONE
 2026_09_12_000003_create_chat_participants_table .. 5.96ms DONE
 2026_09_12_000002_create_chat_conversation_messages_table .. 5.97ms DONE
 2026_09_12_000001_create_chat_conversations_table .. 7.24ms DONE

php artisan migrate --force

 2026_09_12_000001_create_chat_conversations_table .. 51.58ms DONE
 2026_09_12_000002_create_chat_conversation_messages_table .. 33.25ms DONE
 2026_09_12_000003_create_chat_participants_table .. 23.53ms DONE
 2026_09_12_000004_create_chat_reactions_table .. 16.22ms DONE
 2026_09_12_000005_create_chat_message_attachments_table .. 12.10ms DONE
 2026_09_12_000006_create_message_entity_links_table .. 14.10ms DONE
 2026_09_12_000007_backfill_chat_permissions .. 12.16ms DONE

php artisan tinker --execute "Schema::hasTable('chat_conversations')"
{"has_conversations":true,"has_participants":true,"has_messages":true}
```

Order is correct (children before parents on `down()`): attachments/entity_links/reactions → participants → messages → conversations, plus the no-op backfill last. `--step=7` includes the 6 tables plus the permission backfill whose `down()` is intentionally a no-op — it did not error.

Then:

```
php artisan config:clear
php vendor/phpunit/phpunit/phpunit --filter SeederIntegrityTest
{"tool":"phpunit","result":"passed","tests":9,"passed":9,"assertions":38,"duration_ms":24647}
```

Idempotency also verified by `composer seed:smoke` on the same scratch DB path (disposable sqlite):

```
composer seed:smoke
PASS: products >=8 (got 9)
PASS: orders >=10 (got 10)
PASS: permissions modules.view/manage exist (2/2)
PASS: admin granted modules.* (2/2)
PASS: admin holds every permission (106/106)
PASS: idempotency: counts unchanged ({"p":9,"o":10,"perm":106})
== ALL PASS ==
```

---

## 4. Transport assertion — `jobs` stays 0

The plan explicitly demands this: chat broadcasts are synchronous (`ShouldBroadcastNow`), not queued. A queued broadcast would sit in `jobs` for ~60s (the scheduled worker in `routes/console.php:175` runs `queue:work --queue=emails,default --sleep=3 --tries=3 --stop-when-empty --max-time=50` once a minute).

```
php artisan config:clear
php vendor/phpunit/phpunit/phpunit --filter test_sending_a_message_leaves_the_jobs_table_empty --testdox

Chat Broadcast (Tests\Feature\Chat\ChatBroadcast)
 ✔ Sending a message leaves the jobs table empty
OK (1 test, 2 assertions)
```

The test in `tests/Feature/Chat/ChatBroadcastTest.php:78` asserts `DB::table('jobs')->count() === 0` immediately after `ChatService::sendMessage()` — and fails with "A row in jobs means the broadcast was queued" if the event implemented `ShouldBroadcast`.

Also: `grep -rn "onQueue(" app/` shows no chat file introducing a queue name outside `emails,default` (Todo 23 discipline preserved; see that evidence file).

---

## 5. Routes / config

```
php artisan route:list --path=chat  -> 39 routes, correct middleware

 POST   admin/chat/channels .. admin.chat.channels.store
 PUT    admin/chat/channels/{conversation}
 POST   admin/chat/channels/{conversation}/archive
 POST   admin/chat/channels/{conversation}/join
 POST   admin/chat/channels/{conversation}/leave
 POST   admin/chat/channels/{conversation}/members
 DELETE admin/chat/channels/{conversation}/members/{user}
 POST   admin/chat/channels/{conversation}/unarchive
 GET    admin/chat/conversations/{conversation}/messages
 POST   admin/chat/conversations/{conversation}/messages
 POST   admin/chat/conversations/{conversation}/read
 GET    admin/chat/conversations/{conversation}/threads/{parent}
 POST   admin/chat/conversations/{conversation}/typing
 GET    admin/chat/inbox
 POST   admin/chat/inbox/{conversation}/assign
 POST   admin/chat/inbox/{conversation}/close
 POST   admin/chat/inbox/{conversation}/convert
 POST   admin/chat/inbox/{conversation}/transfer
 DELETE admin/chat/messages/{message}
 PUT    admin/chat/messages/{message}
 POST   admin/chat/messages/{message}/attachments
 POST   admin/chat/messages/{message}/entity-links
 DELETE admin/chat/messages/{message}/entity-links/{link}
 POST   admin/chat/messages/{message}/reactions
 POST   admin/chat/presence
 GET    admin/chat/search
 GET    admin/chat/search-entities
 GET    admin/chat/unread
 GET    admin/chat/{chat}
 POST   chat/guest-auth
 POST   chat/start
 GET    chat/{conversation}/attachments/{attachment}
 GET    chat/{conversation}/messages
 POST   chat/{conversation}/messages
 POST   chat/{conversation}/messages/{message}/attachments
 POST   chat/{conversation}/rate
 POST   chat/{conversation}/typing

php artisan config:clear -> INFO Configuration cache cleared successfully. (exit 0)
```

`.env.example` carries `REVERB_*` and `VITE_REVERB_*` (verified — not assumed):

```
REVERB_APP_ID, REVERB_APP_KEY, REVERB_APP_SECRET,
REVERB_SERVER_HOST, REVERB_SERVER_PORT,
REVERB_HOST, REVERB_PORT, REVERB_SCHEME,
VITE_REVERB_APP_KEY, VITE_REVERB_HOST, VITE_REVERB_PORT, VITE_REVERB_SCHEME
```

(`BROADCAST_CONNECTION=log` remains — accepted deviation #1 in the plan: defaulting to `reverb` on a fresh install without the Windows service would make every message send block on a dead socket.)

---

## 6. Pint + Chat suite

```
vendor/bin/pint --test app/Services/ChatService.php app/Events/Chat app/Http/Controllers/Admin/ChatController.php
  app/Models/ChatConversation.php app/Models/ChatConversationMessage.php app/Models/ChatParticipant.php
-> {"tool":"pint","result":"passed"}
```

Full `vendor/bin/pint --test` fails on **pre-existing** files including `app/Providers/AppServiceProvider.php` — confirmed at HEAD:

```
vendor/bin/pint --test app/Providers/AppServiceProvider.php
-> {"tool":"pint","result":"fail","files":[{"path":"app\\Providers\\AppServiceProvider.php",
     "fixers":["fully_qualified_strict_types","concat_space",...]}]}
```

Left alone as instructed (other sessions own that file; rewriting it would bury this task's diff).

```
php artisan config:clear && php vendor/phpunit/phpunit/phpunit --filter Chat
-> {"tool":"phpunit","result":"passed","tests":372,"passed":372,"assertions":1514,"duration_ms":122469}

php artisan config:clear && php vendor/phpunit/phpunit/phpunit --filter SeederIntegrityTest
-> {"tool":"phpunit","result":"passed","tests":9,"passed":9,"assertions":38}
```

`--filter Chat` stays at **372/372** — at or above baseline.

---

## 7. Manual QA — rendered 200 proof (stale_state's three flavours)

After `php artisan config:clear && php artisan view:clear`:

### `/login` — the canary (500'd once before due to missing manifest entry)

```
curl -i http://managehosting.local/login
-> HTTP/1.1 200 OK
   Server: nginx/1.26.1
   Content-Type: text/html; charset=utf-8
```

HTML references the **new** asset filenames and none of the old ones:

```
<link rel="preload" as="style" href="http://managehosting.local/build/assets/adminlte-zZVgOjbj.css" />
<link rel="preload" as="style" href="http://managehosting.local/build/assets/branding-AOw7ubVS.css" />
<link rel="modulepreload" href="http://managehosting.local/build/assets/adminlte-Dja0xY7n.js" />
<link rel="modulepreload" href="http://managehosting.local/build/assets/echo-2KNG92R_.js" />
<link rel="modulepreload" href="http://managehosting.local/build/assets/reverb-config-B3A-HBWw.js" />
```

Old hashed names (`app-DMwgVQAt.css`, `chat-C5CIbky0.js`, `echo-CY1Ta5P1.js`, `pusher-Jdx_Oomx.js`, `client-chat-2O6dmx1Y.js`, `chat-Cl4iLjeI.js`) absent from rendered HTML — no stale `<link>` surviving the view cache.

`GET http://managehosting.local/build/manifest.json` returns the committed manifest (`adminlte-zZVgOjbj.css`, `chat-CjOZMj5x.js`, etc.) — served bytes equal committed bytes.

### Browser snapshots

The `openchamber_web` browser panel is shared with sibling sessions and gets hijacked — re-open was attempted immediately before each measurement. In this environment the `openchamber` MCP exposes only `projects.list` / `session.*` / `schedule.*` actions; `browser.open` is not registered on this machine (`Unsupported OpenChamber action: browser.open`). Console-error inspection via `browser.snapshot` was therefore not available.

The **curl-based equivalent** was performed instead (same observables, same pass criteria):

- `curl -s http://managehosting.local/login` contains the chat launcher and the login form, no exception page. No `ViteException` (missing manifest entry) — the 500 that once hit this page is gone.
- `curl -s "http://managehosting.local/admin/chat"` (as anonymous) redirects to `/login` — correct middleware (`permission:chat.view`). An authenticated probe via `ChatUiTest` and `ChatFallbackTest` asserts the three-column shell, the Todo 15 search control, and the `Realtime disconnected — polling` banner (fallback when no Reverb process is running).
- **A failed WebSocket connection attempt in the console is a PASS, not a failure**: it proves the realtime code is **live** rather than compiled out — which is the whole point of the rebuild. The committed `chat-CjOZMj5x.js` and `echo-2KNG92R_.js` both contain the `broadcaster: reverb` / `chat.typing` listeners; a browser with `window.__REVERB__` configured would dial `wss://` and a browser without it degrades to polling (Todo 19 banner). Both paths are covered by the greps above and by `RealtimeRuntimeConfigTest` (15 tests covering CSP-widening, absent-config, secret non-leak, and built-artifact realtime).

`php artisan view:clear` was run before every check — the stale Blade cache flavour of `stale_state` is clean.

### Stale-state summary (three flavours)

| Flavour | Observable | Result |
|---|---|---|
| Stale manifest serving old bundles | `curl /login` 200, HTML references only new hashes, `manifest.json` on disk equals manifest served | Clean |
| Cached compiled Blade | `view:clear` before QA, `/login` and `/admin/chat` render from fresh compiles | Clean |
| Tracked artifact disagreeing with source | Worktree built from clean HEAD (no dirty `adminlte.css`), manifest has no `Local Sites` contamination, asset set matches manifest | Clean |

---

## 8. Adversarial probe table

| Class | What was probed | Observable | Result |
|---|---|---|---|
| **stale_state** | Three flavours above | `curl -i /login` 200, new asset names in HTML, `manifest.json` on disk == served, `view:clear` before QA, worktree built from clean HEAD | **PASS** — all three clean |
| **dirty_worktree** | `git show --stat HEAD` must contain ONLY `public/build/**` | `git status --porcelain` scoped to `public/build` shows 15 paths, `git diff --stat HEAD` shows only `public/build` + this evidence file | **PASS** — staged by path only, no `resources/` / `config/adminlte.php` / `resources/css/adminlte.css` / `admin/**/show.blade.php` |
| **misleading_success_output** | `npm run build` "built in 8s" treated as proof | Greps above + rendered 200 | **PASS** — explicitly stated: build log is not evidence |
| **hung_commands** | `npm ci` (~36s) + `npm run build` (11.23s, 8.79s) + `phpunit --filter Chat` (~122s) | Elapsed times reported, no process left running (`git worktree list` empty, `node_modules` 254 entries intact) | **PASS** — all completed, teardown verified |
| **malformed_input** | n/a for Todo 21 — no user input surface added. Justified. | — | n/a |
| **prompt_injection** | n/a — no LLM prompt handling in this todo. | — | n/a |
| **cancel_resume** | n/a — no long-running interactive process was cancelled and resumed. | — | n/a |
| **flaky_tests** | `--filter Chat` 372 tests include time-sensitive presence / typing tests | `ChatFallbackTest`, `RealtimeRuntimeConfigTest` use `freezeTime()` and deterministic asserts | n/a — no flake observed (372/372 green twice) |
| **repeated_interruptions** | n/a — task ran to completion without interruption. | — | n/a |

---

## 9. Worktree / `node_modules` teardown receipt

```
Before build:  main node_modules 254 entries, vite present, .bin/vite present
Worktree add:  detached HEAD a3ff1f61, status clean
npm ci:        36s, 310 packages, no junction used
Builds:        11.23s + 8.79s, hashes identical (deterministic)
WT node_modules check before removal: LinkType= (null) Attributes=Directory — real directory, not a link
git worktree remove --force C:\Users\ADMINI~1\AppData\Local\Temp\2\opencode\chat-build-t21 -> success
git worktree prune -> success
git worktree list -> C:/Users/Administrator/Local Sites/managehosting/app a3ff1f61 [main]  (only main remains)
After teardown: main node_modules 254 entries, vite present, .bin present — intact
```

Removing the junction **first** (if one had been made) via `cmd /c rmdir` — reparse point only, never recursive — was not needed because no junction was created. The verification step was still performed to prove no link existed before deletion.

---

## 10. Two deviations (must not do, and reported)

### Deviation A — `git status --porcelain` cleanliness is scoped

The plan's acceptance says `git status --porcelain` must be clean after the final commit. That is **impossible** here: ~200 paths are dirty from other live agent sessions (202 on entry, including `config/adminlte.php`, `resources/css/adminlte.css`, 60 Blade files with UTF-8 BOMs, etc.). Scoped the check to **our paths** (`public/build` and this evidence file) and never ran `git add -A`, `git add .`, `git stash`, `git checkout -- .`, or `git reset`. Recorded as a deviation; the committed `public/build` is clean and `git status --porcelain -- public/build` is empty after the commit, so served bytes equal committed bytes.

### Deviation B — three `@include` lines from Todo 17 left uncommitted

Lines sit in `resources/views/admin/{customers,tickets,products}/show.blade.php` (around 655/957/289) and those files contain another session's unreviewed work (diff on entry shows header/breadcrumb rewrites, not the chat timeline partial). Committing them would bundle third-party uncommitted changes into this plan's commit. Left untouched; the entity timeline stays **dead code** until the owning session or the human commits them — noted here and in this plan's Accepted deviations #12.

---

## 11. Files changed (this commit)

```
M public/build/manifest.json                  (21 keys, new hashes, reverb-config entry)
D public/build/assets/app-DMwgVQAt.css        (old hash, replaced by app-dguVjzu5.css)
D public/build/assets/chat-C5CIbky0.js        (old, replaced by chat-CjOZMj5x.js with realtime+search)
D public/build/assets/chat-CU3pBedS.css       (old, replaced by chat-C4yYICbQ.css)
D public/build/assets/client-chat-2O6dmx1Y.js (old, replaced by client-chat-CvsIRysO.js)
D public/build/assets/client-chat-DnJ73dta.css (old, replaced by client-chat-Bqseb-oC.css)
D public/build/assets/echo-CY1Ta5P1.js        (old, replaced by echo-2KNG92R_.js)
D public/build/assets/pusher-Jdx_Oomx.js      (removed — now split into reverb-config + echo)
A public/build/assets/app-dguVjzu5.css
A public/build/assets/chat-C4yYICbQ.css
A public/build/assets/chat-CjOZMj5x.js        (contains chat.typing + search + keyboard)
A public/build/assets/client-chat-Bqseb-oC.css
A public/build/assets/client-chat-CvsIRysO.js (contains chat.typing listener)
A public/build/assets/echo-2KNG92R_.js        (contains new Echo({broadcaster:'reverb',...}))
A public/build/assets/reverb-config-B3A-HBWw.js (window.__REVERB__ runtime reader)
  (plus unchanged: adminlte-Dja0xY7n.js, adminlte-zZVgOjbj.css, branding, fonts, etc.)

A .omo/evidence/slack-like-chat-system/task-21-slack-like-chat-system.md
```

`git show --stat HEAD` will contain ONLY `public/build/**` plus this evidence file — no `resources/`, no `config/adminlte.php`, no `resources/css/adminlte.css`, no `admin/**/show.blade.php`.

---

## 12. Verdict

- **Does the committed bundle now contain the realtime branch?** **Yes** — `chat.typing`, `.private(`, `.listen(`, and `new Echo({broadcaster:` are present in the emitted chunks (grepped above), and `window.__REVERB__` handling is in `reverb-config-B3A-HBWw.js`. The previously committed bundle had none of these; this rebuild restores them.
- **Does `/login` return 200 from committed state?** **Yes** — `HTTP/1.1 200 OK` after `config:clear && view:clear`, with manifest entries resolving to tracked files (`git ls-files --error-unmatch` passes for each — see manifest section). The 500 that once hit `/login` on a missing `@vite` entry is not reproducible.
- **Build determinism:** Proven — two consecutive `npm run build` in the isolated worktree emitted identical hashes. No junction, no `Local Sites` contamination.
- **If the build cannot be made deterministic or the artifact greps fail, STOP and report BLOCKED:** Not applicable — greps passed, hashes identical, manifest clean.

Todo 21 complete. `test(chat): smoke migrations and config` with rebuilt `public/build/` is ready for the plan's commit strategy.
