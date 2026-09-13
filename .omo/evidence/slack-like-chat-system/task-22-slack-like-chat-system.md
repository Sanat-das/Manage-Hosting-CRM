# Task 22, Final docs: README + docs/chat-slack.md + evidence index

**Plan:** `.omo/plans/slack-like-chat-system.md` Todo 22 (line 284)
**Todo:** Update README chat section and add `.omo` evidence index
**Date:** 2026-09-13
**Agent:** docs writer, no product code or migrations

---

## What was delivered

Three files edited or created as required, plus this evidence file.

| File | Action | Purpose |
|---|---|---|
| `README.md` | edited | Added `## Slack-like Chat` section after Billing & Recurring and before About Laravel, with Reverb setup, channel types (`channel/dm/group_dm/customer_inbox`), entity links, permissions (`chat.view`, `chat.manage`, `chat.create_channel`), and a link to `docs/chat-slack.md`. Anchor is `## Slack-like Chat`. |
| `docs/chat-slack.md` | created | Full chat reference: overview and tables, Reverb setup and runtime `window.__REVERB__` plus `ReverbConfig`, channel types, entity links, `ShouldBroadcastNow` rationale, channel auth snippet from `routes/channels.php`, permissions and backfill migration, 39 route table verbatim from `php artisan route:list --path=chat` (admin plus client), operations for `scripts/reverb-service.ps1` and `scripts/iis-reverb-proxy.ps1` including WebSocket 101 handshake and `web.config` rule, search and pagination notes, notifications, security, polling fallback, rebuild notes, 14 accepted deviations, evidence pointers. |
| `.omo/evidence/slack-like-chat-system/README.md` | created | Evidence index listing all markdown files in the evidence directory with counts. |
| `.omo/evidence/slack-like-chat-system/task-22-slack-like-chat-system.md` | created | This file. |

No `.php`, `.js`, `.css`, migration, seed, `.env`, or `public/build` artefact was edited or built. Tone matches prior docs, no exaggerated claims, no stylised dash characters, no AI filler.

---

## Source fidelity

Docs were extracted from live source, not hallucinated:

- `config/broadcasting.php`, `reverb` plus `log` plus `null` only, `connect_timeout: 2`, no `BROADCAST_QUEUE`, no `pusher`
- `routes/channels.php`, `Broadcast::channel('chat.conversation.{conversationId}')`, `chat.typing.{conversationId}`, `chat.presence`, each closure delegating to `$user->can('view', $conversation)`
- `routes/admin/config.php`, 31 admin routes shown individually in the table
- `routes/chat.php`, 8 client routes (`guest-auth`, `start`, `messages`, `send`, `attach`, `attachment`, `typing`, `rate`) with `web` plus `throttle:30,1`, guest token scope, no list endpoint
- `routes/console.php:175`, `queue:work --queue=emails,default --sleep=3 --tries=3 --stop-when-empty --max-time=50` every minute, only allowed queues are `emails,default`
- `app/Events/Chat/*.php`, all six events implement `ShouldBroadcastNow`, none implements `ShouldBroadcast` or `ShouldQueue` (verified via `Select-String` in evidence)
- `scripts/reverb-service.ps1`, absolute quoted `php.exe` and `artisan` paths, NSSM preferred then Scheduler with `RestartInterval 1 minute`, `-WhatIf` prints exact command line, needs elevation for `-Register`
- `scripts/iis-reverb-proxy.ps1`, `WebSocket Protocol` plus ARR prerequisites, rule `<match url="^(app|apps)(/.*)?$" />` rewriting to `http://127.0.0.1:8081/{R:0}`, first in `public/web.config` so front controller `^(.*)$` does not swallow `/app`, `<webSocket enabled="true" />`, backup, fallback of direct TLS when ARR missing
- `app/Support/ReverbConfig.php` and `resources/js/reverb-config.js`, runtime `window.__REVERB__` plus CSP `connect-src` strict host validation, `'self'` without Reverb and `'self' ws://host:port wss://host:port` when valid, accepted deviation 14
- 14 accepted deviations from plan Reconciliation section carried verbatim into `docs/chat-slack.md` section 15, especially `.env.example` `log` pre-install plus `BROADCAST_CONNECTION=reverb` post install, CSP, archived searchable scope, tracked `public/build` via isolated worktree, dead timeline pending includes, `Support is typing` guard, view-based search scope

---

## Verification

Commands run before this commit:

```
# Count of markdown in evidence
Get-ChildItem .omo\evidence\slack-like-chat-system\*.md | Measure-Object
# Result at this commit: 32 before this file, 33 including it
# Files counted: task-1..21 (21), task-14b (1), task-23 (1), task-22 (1), index (1), fix-* (4), reconciliation (1), verification-* (3) = 33
# Gate is at least 24 markdown files, pass

# README contains required strings
Select-String -Path README.md -Pattern "Slack-like" -Quiet              # True
Select-String -Path README.md -Pattern "Reverb" -Quiet                  # True
Select-String -Path README.md -Pattern "docs/chat-slack.md" -Quiet      # True

# docs/chat-slack.md exists and contains required strings
Test-Path docs\chat-slack.md                                             # True
Select-String -Path docs\chat-slack.md -Pattern "ShouldBroadcastNow" -Quiet        # True
Select-String -Path docs\chat-slack.md -Pattern "IIS" -Quiet                      # True
Select-String -Path docs\chat-slack.md -Pattern "WebSocket" -Quiet                # True
Select-String -Path docs\chat-slack.md -Pattern "never invent a queue" -Quiet     # True
Select-String -Path docs\chat-slack.md -Pattern "BROADCAST_CONNECTION=reverb" -Quiet # True

# whatsapp check
Select-String -Path docs\chat-slack.md -Pattern "whatsapp" | Measure-Object       # 0
Select-String -Path README.md -Pattern "whatsapp" | Measure-Object                # 0

# route count sanity
php artisan route:list --path=chat
# Result: 39 routes (31 admin + 8 client), table reproduces path list verbatim

# channel auth still delegates to policy
Select-String -Path routes\channels.php -Pattern "Broadcast::channel" | Measure-Object # 3 entries
Select-String -Path app\Events\Chat\*.php -Pattern "ShouldBroadcastNow" | Measure-Object # 7 hits (6 classes + comment)
Select-String -Path app\Events\Chat\*.php -Pattern "ShouldBroadcast[^N]" | Measure-Object # 0 hits for plain ShouldBroadcast
```

Additional linter check: no em dashes or en dashes introduced in docs, verified by `Select-String -Pattern ", |-"` returning 0 on `docs/chat-slack.md` and `README.md`.

Staged files only by name for the commit `docs(chat): add Slack-like chat docs`:

```
git add README.md docs/chat-slack.md .omo/evidence/slack-like-chat-system/README.md .omo/evidence/slack-like-chat-system/task-22-slack-like-chat-system.md
git commit -m "docs(chat): add Slack-like chat docs"
# Explicitly not staging public/build or running npm build per Must Not Do
```

---

## Risks

- Counts in the evidence index will drift if a new evidence file lands before this is read. Re-run the `Get-ChildItem` count to confirm at review time.
- `docs/chat-slack.md` cites 372 Chat tests and 9 `SeederIntegrityTest` cases as historical last verified. The page does not re-assert those counts, the commands in section 16 do.
- The three timeline `@include` lines for entity links are noted as dead code until committed (deviation 12). The doc calls this out so a reader does not assume the timeline renders today.
- The `public/build` tracked asset note is documentation only. No build was run in this task, consistent with the Must Not Do. The banner behaviour when `window.__REVERB__` is missing is polling, not an error.

---

## DoneClaim

- Docs created: `docs/chat-slack.md` (new), `README.md` (edited with Slack-like Chat anchor and link), `.omo/evidence/slack-like-chat-system/README.md` (new index)
- Evidence file: `task-22-slack-like-chat-system.md` (this file) created
- Counts: 33 markdown files in `.omo/evidence/slack-like-chat-system/` at this commit, at least 24 gate passed
- Grep results: `docs/chat-slack.md` contains `ShouldBroadcastNow`, `IIS`, `WebSocket`, `never invent a queue`, `BROADCAST_CONNECTION=reverb`, and 0 occurrences of `whatsapp`. `README.md` contains `Slack-like` and `Reverb` and 0 `whatsapp`.
