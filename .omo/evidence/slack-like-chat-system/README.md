# slack-like-chat-system, Evidence Index

Evidence directory: `.omo/evidence/slack-like-chat-system/`

This index is the manifest for Todo 22. It lists every markdown file in the directory, so `ls *.md` and this file agree. Counts are reported at the time Todo 22 was written. Historical test counts are cited where noted, not re-asserted here.

---

## Counts

| Bucket | Files | Notes |
|---|---|---|
| Task evidence (Todos 1..21, 23) | 23 | `task-1..21` plus `task-23` (deployable Reverb) and `task-14b` (inbound typing) |
| This doc task | 1 | `task-22-slack-like-chat-system.md` |
| Index | 1 | this file |
| Fixes and reconciliation | 5 | `fix-*` (4) plus `reconciliation-2026-09-12.md` |
| Verifications | 3 | `verification-14-15-17-18-21.md`, `verification-14-16-19.md`, `verification-14-16-20.md` |
| **Total markdown in directory** | **33** | `ls *.md` at this commit. The plan gate is at least 24, this meets it with margin. |

Last verification run cited as historical: 372 Chat-filtered tests passing, 9 `SeederIntegrityTest` cases passing, `composer seed:smoke` ALL PASS, `npm run build` exit 0. Re-run `php artisan test --filter Chat` and `composer seed:smoke` to reproduce.

---

## Task evidence

| # | File | Todo | Summary |
|---|---|---|---|
| 1 | `task-1-slack-like-chat-system.md` | 1 | Additive migrations: 6 chat tables, no rename of `chat_sessions`, `last_read_message_id` as read cursor, no `chat_read_state` |
| 2 | `task-2-slack-like-chat-system.md` | 2 | `install:broadcasting --type=reverb`, `config/broadcasting.php`, `routes/channels.php`, `.env.example` REVERB keys, `vendor/` committed |
| 3 | `task-3-slack-like-chat-system.md` | 3 | `chat.view` / `chat.manage` / `chat.create_channel` in `AdminLteRbacSeeder`, backfill migration, menu and route gates |
| 4 | `task-4-slack-like-chat-system.md` | 4 | `laravel-echo` + `pusher-js`, `echo.js`, Vite `VITE_REVERB_*`, layout include, `npm run build` chunk |
| 5 | `task-5-slack-like-chat-system.md` | 5 | 6 Eloquent models and factories, `last_read_message_id` only, morph links, soft deletes |
| 6 | `task-6-slack-like-chat-system.md` | 6 | `ChatService` domain service plus `ChatConversationPolicy` and `ChatMessagePolicy` |
| 7 | `task-7-slack-like-chat-system.md` | 7 | Broadcast events: `NewChatMessage`, `MessageEdited`, `MessageDeleted`, `ReactionToggled`, `TypingIndicator`, `UserPresence`, all `ShouldBroadcastNow` |
| 8 | `task-8-slack-like-chat-system.md` | 8 | Admin `ChatController` write path plus 10+ routes in `routes/admin/config.php` with `FormRequests` |
| 9 | `task-9-slack-like-chat-system.md` | 9 | Reactions, `markRead`, `unreadCount`, presence via `chat.presence` cache and polling fallback |
| 10 | `task-10-slack-like-chat-system.md` | 10 | File attachments on `chat_message_attachments`, 10 MB and mime whitelist, signed `img` preview and download |
| 11 | `task-11-slack-like-chat-system.md` | 11 | Customer engine: `customer_inbox`, guest token, queue/assign/transfer/close/rate, `convertToTicket` |
| 12 | `task-12-slack-like-chat-system.md` | 12 | Entity composer: `GET /admin/chat/search-entities` and `POST .../entity-links`, polymorphic `message_entity_links`, broadcast cards |
| 13 | `task-13-slack-like-chat-system.md` | 13 | Admin Slack layout: sidebar, message pane, thread panel, composer, Echo subscriptions, capped 50 messages, `Load older` cursor |
| 14 | `task-14-slack-like-chat-system.md` | 14 | Client widget `widget.blade.php` plus `Client\ChatController` and guest `throttle:30,1` routes in `routes/chat.php` |
| 15 | `task-14b-inbound-typing.md` | 14b | Inbound typing for the customer inbox direction, `Support is typing` guard |
| 16 | `task-15-slack-like-chat-system.md` | 15 | Search (`LIKE '%q%'` bounded by policy scope), history cursor pagination, `j/k/r/e/cmd+K` shortcuts |
| 17 | `task-16-slack-like-chat-system.md` | 16 | Notifications `ChatMentionNotification` etc on `database` + broadcast, `NotificationPreferenceService` opt-out, `default` queue |
| 18 | `task-17-slack-like-chat-system.md` | 17 | Audit to `audit_log` and entity timeline links on product/customer/ticket show pages (3 surfaces, 3 `@include` lines pending commit, deviation 12) |
| 19 | `task-18-slack-like-chat-system.md` | 18 | Demo seeds: 3 channels, 4 DMs, 1 customer inbox, threads, reactions, links; `DummyDataConfig` minima, idempotency |
| 20 | `task-19-slack-like-chat-system.md` | 19 | Reconnection fallback: 5s poll on `after_id`, `Realtime disconnected, polling` banner, `visibilitychange` suspend, skeletons and toasts |
| 21 | `task-20-slack-like-chat-system.md` | 20 | Hardening: `symfony/html-sanitizer`, `throttle:chat` 60/min with `withoutMiddleware('throttle:admin')`, slug validation, signed URLs, CSP via `SecurityHeaders` |
| 22 | `task-21-slack-like-chat-system.md` | 21 | Migration rollback `--step=7`, `migrate:fresh --seed`, `SeederIntegrityTest`, `route:list`, `config:clear`, `npm run build`, `pint` |
| 23 | `task-22-slack-like-chat-system.md` | 22 | Docs: `README.md` Slack-like Chat section, `docs/chat-slack.md`, this index |
| 24 | `task-23-slack-like-chat-system.md` | 23 | Deployable Reverb: `scripts/reverb-service.ps1`, `scripts/iis-reverb-proxy.ps1`, `ChatQueueDisciplineTest`, queue-name discipline |

Notes on numbering: Todo 23 executes in Wave 2 though it is numbered last to avoid renumbering. `task-14b` is an intermediate typing split recorded during Wave 4.

---

## Fixes and reconciliation

| File | Scope | What it proves |
|---|---|---|
| `fix-chatcontroller-defects.md` | Controller defects | Additional `ChatController` defect fixes between Todos 8 and 21 |
| `fix-client-chat-bundle.md` | Client bundle | Client chat asset corrections |
| `fix-operator-name-leak.md` | Privacy | Operator name leak fix (guest payload shows `Support`) |
| `fix-realtime-runtime-config.md` | Realtime runtime | `window.__REVERB__` plus `ReverbConfig` for request-time config and CSP |
| `reconciliation-2026-09-12.md` | Plan reconciliation | Full pass audit that produced the 14 accepted deviations documented in `docs/chat-slack.md` section 15 |

---

## Verifications

| File | Tasks covered | Signals |
|---|---|---|
| `verification-14-15-17-18-21.md` | 14, 15, 17, 18, 21 | Client widget, search, timeline, seeds, rollback smoke |
| `verification-14-16-19.md` | 14, 16, 19 | Guest flow, notifications, fallback banner, presence |
| `verification-14-16-20.md` | 14, 16, 20 | Guest flow, notifications, CSP and security gates |

Additional verification artefacts at this doc date: `routes/console.php:175` schedules `queue:work --queue=emails,default`, `app/Events/Chat/*` implement `ShouldBroadcastNow`, `routes/channels.php` delegates every socket auth to `ChatConversationPolicy`, and `grep -ri whatsapp docs/chat-slack.md` returns 0.

---

## How to verify the index

```powershell
# PowerShell on Windows
Get-ChildItem .omo\evidence\slack-like-chat-system\*.md | Measure-Object | Select-Object -ExpandProperty Count
# Expect: 33 at this commit (at least 24 per the plan gate)

Select-String -Path docs\chat-slack.md -Pattern "ShouldBroadcastNow" -Quiet   # True
Select-String -Path docs\chat-slack.md -Pattern "never invent a queue" -Quiet # True
Select-String -Path docs\chat-slack.md -Pattern "BROADCAST_CONNECTION=reverb" -Quiet # True
Select-String -Path docs\chat-slack.md -Pattern "whatsapp" -Quiet             # False (case-insensitive check separately)
```

For the whatsapp check the plan requires `grep -ri whatsapp docs/chat-slack.md` returns 0. The PowerShell equivalent is:

```powershell
Select-String -Path docs\chat-slack.md -Pattern "whatsapp" -CaseSensitive:$false | Measure-Object | Select-Object -ExpandProperty Count
# Expect: 0
Select-String -Path README.md -Pattern "whatsapp" -CaseSensitive:$false | Measure-Object | Select-Object -ExpandProperty Count
# Expect: 0 (unless a pre-existing unrelated occurrence is present, which this index would note)
```

---

## Docs

- `README.md`, has the `Slack-like Chat` anchor and links to `docs/chat-slack.md`
- `docs/chat-slack.md`, the full chat reference: route table (39 routes from `php artisan route:list --path=chat`), channel auth, operations (Windows service plus IIS WebSocket proxy), `ShouldBroadcastNow` rationale, queue-name discipline, and 14 deviations
- `docs/iis-deployment.md`, unrelated to chat, retained for IIS FastCGI baseline
