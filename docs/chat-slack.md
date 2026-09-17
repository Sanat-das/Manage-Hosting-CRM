# Slack-like Chat: Architecture, Routes, and Operations

This doc is the single reference for the Slack-like chat engine that ships inside the HostVexa panel. It covers transport, data model, routes, channel auth, queue discipline, operations, and the 14 accepted deviations from the original plan.

> Scope reminder: channel, DM, group DM, and customer inbox. No voice, no video, no multi-workspace. See `.omo/plans/slack-like-chat-system.md` for the original plan.

---

## 1. Overview

The chat is **additive**. Existing `chat_sessions` and `chat_messages` history is untouched. New tables carry the Slack semantics:

| Table | Purpose |
|---|---|
| `chat_conversations` | A room. `type` is one of `channel`, `dm`, `group_dm`, `customer_inbox`. Channels carry a unique `slug`, optional `is_private`, `department` (string, no FK to `ticket_departments.slug`), `topic`, `purpose`, `created_by`, `archived_at`. |
| `chat_participants` | Membership. `conversation_id`, `user_id` (nullable for guests), `guest_token`, `role` (`member` or `admin`), `joined_at`, `last_read_message_id` (canonical read cursor, no `chat_read_state` table). |
| `chat_conversation_messages` | Messages. `conversation_id`, `user_id`, `guest_token`, `parent_id` (self FK for threads), `body`, `edited_at`, soft `deleted_at`, indexes on `conversation_id`, `parent_id`, `created_at`. |
| `chat_reactions` | Emoji. `message_id`, `user_id`, `emoji` (varchar 32), unique `(message_id, user_id, emoji)`. |
| `chat_message_attachments` | Files. `message_id` FK to `chat_conversation_messages`, `disk`, `path`, `filename`, `mime_type`, `size_bytes`, `is_inline`, `content_id`. |
| `message_entity_links` | Composer attachments. `message_id`, polymorphic `linkable_type` / `linkable_id` (whitelist: `Product`, `Customer`, `CustomerContact`, `Ticket`). Morph index, `created_at`. |

Other invariants from the build:

- `last_read_message_id` on `chat_participants` is the **single source of truth** for read state.
- Messages are **soft deleted only**, shown as `[deleted]` so thread parents remain.
- `chat_message_attachments` table is created in the Todo 1 migration batch, no separate migration elsewhere.
- Factory support exists for each model. `DummyDataConfig` and `SupportSeeder::seedChat` provide demo data (3 channels, 4 DMs, 1 customer inbox, threads, reactions, links).

For the current demo counts at last verification, see the evidence index: last verified 372 Chat-filtered tests and 9 `SeederIntegrityTest` cases green, with `composer seed:smoke` reporting ALL PASS. Those are cited as historical, not re-asserted here.

---

## 2. Reverb Setup

### Why Reverb

First party, self hosted, fits Laravel 13 without an external SaaS websocket account. The panel ships as an installable IIS app, so a Pusher dependency would be a per-install cost the plan chose to avoid.

### What `install:broadcasting --type=reverb` did (Todo 2)

- `config/broadcasting.php` with a single `reverb` connection and a `log` fallback. Only `reverb`, `log`, `null` are defined, unused `pusher/ably/redis` stubs are absent.
- `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`, plus `VITE_REVERB_*` counterparts, in `.env.example`.
- `bootstrap/app.php` wires `withRouting(channels: __DIR__.'/../routes/channels.php')`.
- `routes/channels.php` registers `Broadcast::channel` auth (see section 6).
- `composer require laravel/reverb` committed together with `vendor/`, `composer.json`, `composer.lock` in a single commit. `vendor/` is tracked in this repo, so a half staged require leaves the tree dirty and wedges the updater.
- No `BROADCAST_QUEUE` is set. Chat events do not queue (see section 5).

### Client stack (Todo 4)

- `laravel-echo` and `pusher-js` via npm.
- `resources/js/echo.js` initialises Echo against the Reverb endpoint.
- `resources/js/reverb-config.js` reads the actual connection config at **request time** from `window.__REVERB__`, with a `import.meta.env` fallback only for `npm run dev`.
- `resources/js/adminlte.js` and `resources/js/app.js` include the Echo bootstrap.
- `VITE_REVERB_*` exposure in `vite.config.js` is present but is not the primary transport. The built `public/build/` is committed, the updater never runs `npm run build`.

### Runtime config: `window.__REVERB__` and `ReverbConfig`

Vite inlines `import.meta.env.VITE_*` at build time. Since `public/build/` is committed and the updater never rebuilds, a build-time key would lock every install to the key present when the bundle was built. The fix is runtime rendering:

- `app/Support/ReverbConfig.php` reads `config('broadcasting.connections.reverb.*')` at request time and exposes only the **public** values: `key`, `host`, `port`, `scheme`. `REVERB_APP_SECRET` is never rendered. `forClient()` returns `null` when no key is configured.
- `resources/views/vendor/adminlte/layouts/partials/head.blade.php` (and the client widget layout) renders `<script>window.__REVERB__ = @json($_reverb)</script>` where `$_reverb` is `ReverbConfig::forClient()`.
- `resources/js/reverb-config.js` `getReverbConfig()` is the only reader. It validates `host` (`^[a-zA-Z0-9.\-]+$` or IP), `port` (integer 1 to 65535), `scheme` (`http` or `https`), and returns `null` on any garbage so the UI falls back to polling instead of throwing.
- CSP does not widen unless `ReverbConfig::cspConnectSrc()` validates the host, port and scheme. Without Reverb, `connect-src` stays `'self'`. When valid, the header is `'self' ws://{host}:{port} wss://{host}:{port}`. Strict validation also prevents header injection. See section 7 for CSP rationale.

This is accepted deviations 1 and 14 in context.

### Post install step: `BROADCAST_CONNECTION=reverb`

`.env.example` ships `BROADCAST_CONNECTION=log` intentionally. This is **accepted deviation 1**. On a fresh clone without the Windows service registered, `reverb` as default would make every `storeMessage` block for `connect_timeout: 2` on a dead socket inside the web request. `log` keeps chat working (polling) and only after `scripts/reverb-service.ps1 -Register` succeeds should the installed `.env` be flipped to `BROADCAST_CONNECTION=reverb`. All `REVERB_*` and `VITE_REVERB_*` keys must already be present, Todo 21 gates on them.

After changing that value:

```bash
php artisan config:clear
```

---

## 3. Channel Types

`chat_conversations.type` is an enum with four values. Each drives a different policy path.

| `type` | Meaning | Created by | Visible to |
|---|---|---|---|
| `channel` | Slack style channel, created with a name that derives a slug `^[a-z0-9][a-z0-9_\-]*$`. `is_private` false means any `chat.view` holder can read. `is_private` true means invite only. Optional `department` string scopes via `ticket_department_user`. `topic` and `purpose` are optional. Supports `archived_at` freeze. | Any `chat.create_channel` holder or `chat.manage` | Public: every `chat.view` user. Private: only its participants. |
| `dm` | Direct message, exactly two `chat.view` staff. Staff only. Membership is fixed: `allowsMembershipChanges()` is false, so nobody adds a third person, removes one, or leaves. | The sidebar's **Direct messages ➕** picker (`POST /admin/chat/dms`) via `ChatService::findOrCreateDirectMessage` — idempotent, so reopening one reopens its history | Only the two participants |
| `group_dm` | Multi party DM, up to 50 participants. Staff only. Members can be added and removed, and it can be given a name. | The same picker, with two or more people chosen (`ChatService::createGroupDirectMessage`) | Only its participants |
| `customer_inbox` | One customer or guest plus operators. Created by `ChatService::startCustomerChat` from the guest widget or an authenticated customer. `guest_token` is `Str::random(40)` on the conversation and on the guest participant. Operator is assigned later through the inbox. | Customer widget (`/chat/start`) or staff | Customer or guest with the token plus assigned operators, never other customers. Isolated from staff channels by `ChatConversationPolicy`. |

Common notes:

- Channel names are human display strings. The `^[a-z0-9][a-z0-9_\-]*$` regex is enforced on the **derived slug**, not the display name (deviation 4), so `Deploys` does not break.
- `archived_at` freezes a room. Writes and reactions are blocked, reads and search stay allowed, and hits carry `conversation_archived: true` in the payload linking back into the read only room (deviation 9).
- Group DM cap is 50. Channel member add and remove go through `ChatService` with `ChatConversationPolicy` checks. Archiving does not delete messages.
- A DM is labelled by who *else* is in it. `displayName()` stays viewer agnostic for the audit log and the transcript email; the chat screen renders `labelFor(auth()->user())`, which drops the viewer — otherwise every DM reads "You, Ana" in your own sidebar.
- Two rules are structural rather than permissions, and are enforced in the controller as well as the policy: `allowsMembershipChanges()` (false for `dm` and `customer_inbox`) and `isSelfJoinable()` (public, unarchived channels only). See section 7 for why the policy alone is not enough.

---

## 4. Entity Links

The composer can attach structured references to a message so a conversation can reference the catalogue.

- Typeahead endpoint: `GET /admin/chat/search-entities?q=&type=product|customer|contact|ticket`
  - Scoped by permission: the caller must hold the permission that guards that entity screen (`customers.view` etc). Results are  `Customer.full_name` or `company`, `CustomerContact.first_name` or `last_name` or `email`, `Product.name`, `Ticket.ticket_no` or `subject`.
  - Whitelist is `Product`, `Customer`, `CustomerContact`, `Ticket`. Other types return 422, unknown ids return 404, no N+1 on the render path.
- Attach: `POST /admin/chat/messages/{message}/entity-links` with `linkable_type` and `linkable_id`. Stored as a row in `message_entity_links`. `ChatMessage::entityLinks` is a `morphTo` to `linkable`. Eager loading is required on fetch to keep the message list constant query.
- `NewChatMessage` broadcast includes the rendered cards so listeners get them without a follow up fetch.
- Timeline on entity pages: `products/show.blade.php`, `customers/show.blade.php`, `tickets/show.blade.php` render up to 5 recently linked messages with a click through to the conversation. Deviation 10 notes the actual reachable surfaces are `Product` (not `CatalogProduct`), `Customer`, and `Ticket`, because `CatalogProduct` is a different model with no link and `CustomerContact` has no show view. Links still resolve, cards still render via `MessageEntityLink::url()`.
- Working tree note (deviation 12): the three `@include('admin.chat.timeline')` lines at `customers/show.blade.php:655`, `tickets/show.blade.php:957`, and `products/show.blade.php:289` are present in the working tree but are not yet committed, because the owning agent session still holds uncommitted work in those files. Until those lines land, the timeline is dead code. Todo 21 or the owning session commits them.

---

## 5. Why Every Chat Event Is `ShouldBroadcastNow`

Every class under `app/Events/Chat` implements `Illuminate\Contracts\Broadcasting\ShouldBroadcastNow`, never `ShouldBroadcast` or `ShouldQueue`.

| Event | Channel | Purpose |
|---|---|---|
| `NewChatMessage` | `private-chat.conversation.{id}` | New message, including its `entityLinks` cards. Uses `DB::afterCommit` dispatch and keeps the payload small (ids plus rendered body) so the synchronous publish stays sub 50 ms. |
| `ChatMessageEdited` | `private-chat.conversation.{id}` | Body edit with `edited_at` |
| `ChatMessageDeleted` | `private-chat.conversation.{id}` | Soft delete signal |
| `ReactionToggled` (named `ReactionAdded` in the plan) | `private-chat.conversation.{id}` | Emoji toggle, whitelist validated |
| `TypingIndicator` | `presence-chat.typing.{id}` | Typing heartbeat. Same payload serves both the staff presence channel and the guest private channel, so it reads `Support is typing` to the customer and also to a colleague watching the same inbox. See deviation 7. |
| `UserPresence` | `presence-chat.presence` | Panel wide presence dots. |

The reason is queue topology, not taste.

```
QUEUE_CONNECTION=database          (.env.example:59)
```

There is no persistent `queue:work` daemon. The only worker is the scheduled job in `routes/console.php:175`:

```php
Schedule::command('queue:work --queue=emails,default --sleep=3 --tries=3 --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->name('queue-emails-cron')
```

It runs **once a minute** and drains with `--stop-when-empty --max-time=50`. A `ShouldBroadcast` event would be written to `jobs` and sit there for up to 60 seconds before the next schedule tick picked it up. Chat would lag by a minute and nothing would error. `ShouldBroadcastNow` publishes synchronously inside the request, so `DB::table('jobs')->count()` stays `0` after a message send. That is checked explicitly by `ChatBroadcastQueueTest` across the suite.

### Never invent a queue name

The scheduled worker drains **only** `emails,default`, in that priority order. `default` is intentional, it catches jobs queued without an explicit name that would otherwise sit forever. Any new queue name (`chat`, `broadcasts`, or an `onQueue('chat')` call) would create a second class of jobs that pile up forever without ever failing, the same way the existing `snmp-poll` and `domains` orphan queues did. `ChatQueueDisciplineTest` (Todo 23) enforces this by scanning every chat source file for `onQueue(` and `$queue =` and failing on any value outside `emails,default`, while also asserting the scanner actually found files so a silently empty scan cannot pass.

Allowed queues in this codebase are exactly `emails` and `default`. Reference `routes/console.php:175` and `tests/Feature/Chat/ChatQueueDisciplineTest.php`. The notifications from Todo 16 (`ChatMentionNotification`, `ChatReplyNotification`, `ChatAssignmentNotification`) correctly stay on `default` via the `database` channel so the scheduled worker drains them.

---

## 6. Channel Auth

All websocket authorisation lives in `routes/channels.php`. Each closure delegates to `ChatConversationPolicy`, which is also what the HTTP controllers use. That single policy is the point: a second copy of the rules in the socket layer is how a private channel becomes readable over the socket by someone the page would have blocked.

```php
Broadcast::channel('chat.conversation.{conversationId}', static function (User $user, int $conversationId) {
    $conversation = ChatConversation::find($conversationId);
    return $conversation !== null && $user->can('view', $conversation);
});

Broadcast::channel('chat.typing.{conversationId}', static function (User $user, int $conversationId) {
    $conversation = ChatConversation::find($conversationId);
    if ($conversation === null || ! $user->can('view', $conversation)) {
        return false;
    }
    return ['id' => $user->id, 'name' => $user->full_name];
});

Broadcast::channel('chat.presence', static function (User $user) {
    if (! $user->hasPermission('chat.view')) {
        return false;
    }
    return ['id' => $user->id, 'name' => $user->full_name];
});
```

Notes:

- `private-chat.conversation.{id}` is the `PrivateChannel` for message traffic. `presence-chat.typing.{id}` and `presence-chat.presence` are presence channels so the client learns when a peer stops typing or goes offline without its own heartbeat.
- Customer inbox websockets are not in this file. Guests are not `User` instances. `ChatWidgetController` authorises them from the conversation pinned `guest_token` header instead, and there is no listing endpoint, so a customer cannot discover other conversations.
- Returning `['id' => ..., 'name' => ...]` populates the presence user info. `chat.presence` is panel wide and carries no message content.
- Any `false` return closes the socket subscribe with a 403 on the broadcast auth endpoint.

---

## 7. Permissions

### The permission inventory

Chat permissions live in `database/seeders/AdminLteRbacSeeder.php` ` $permissions` array (the single authority since the 2026-09-11 fix). `InitialDataSeeder` carries no permission strings.

| Permission | Meaning | Who gets it |
|---|---|---|
| `chat.view` | Permission to use the chat at all, see public channels, DMs you belong to, presence and typing. | `admin` (via `$all`), `support`, `sales`, `staff`. `client` roles get nothing. |
| `chat.manage` | Manage level: archiving, adding or removing members, inbox operator actions. Implies `chat.view` via `PermissionMiddleware` (manage implies view). | `admin` only. |
| `chat.create_channel` | Create a new channel. | `admin`, `support`, `sales`, `staff` alongside `chat.view`. |

### Backfill migration

`database/migrations/2026_09_11_000001_grant_admin_role_every_permission.php` is the pattern, `database/migrations/2026_09_1x_000001_backfill_chat_permissions.php` is the chat instance. The updater runs `migrate --force` but never `db:seed`, so without the migration a `migrate` only upgrade on an existing install leaves zero `chat.*` rows and the Live Chat menu plus every `/admin/chat` route returns 403 even for admins.

The migration is idempotent, guarded on `Schema::hasTable` for `adminlte_roles`, `adminlte_permissions`, `adminlte_permission_role`, uses `insertOrIgnore` for the three permission rows and attaches `chat.view` plus `chat.create_channel` to the four staff roles, `down()` is intentionally a no op matching the 2026-09-11 precedent, rolling it back must not error. This is why the smoke step is `migrate --step=7` covering the 6 Todo 1 table migrations plus this backfill.

### How it gates the UI

- `config/adminlte.php` Live Chat menu item uses `'can' => 'chat.view'`.
- `routes/admin/config.php` group wraps every chat route with `permission:chat.view`. Inbox operator routes additionally call `ChatConversationPolicy::operate()` so `chat.manage` is checked at the conversation level, not just the route gate.
- `ChatConversationPolicy::view` is the per conversation check used by both controllers and the `Broadcast::channel` closures, combining public membership, private invite, department scoping via `ticket_department_user`, and the `customer_inbox` isolation that keeps customers out of staff channels and staff channels unreadable to guests.
- `ChatConversationPolicy::startDirectMessage` gates `POST /admin/chat/dms` on `chat.view`, deliberately not on `chat.create_channel`: that permission governs shared rooms, and gating a private word with a colleague behind it would leave a `chat.view` holder able to read the chat and unable to use it.

#### An admin passes every policy unread

`vendor/colorlibhq/adminlte-laravel/src/AdminLteServiceProvider.php:196` registers a `Gate::before` that returns **true for any ability at all** when `$user->isAdmin()`. It runs before every policy in the app, so for an admin `can('join', $dm)` and `can('addMember', $dm)` are both true no matter what `ChatConversationPolicy` says.

Two consequences, both handled rather than worked around:

- Anything that is a **fact** rather than a permission is asked directly. `members` computes `is_member` from the participant rows, so an admin is not offered "Join" in a room they are already in.
- Anything that is **structurally impossible** is refused in the controller too, not only in the policy — `allowsMembershipChanges()` and `isSelfJoinable()` return 422 from `addMember`, `removeMember`, `leaveChannel` and `joinChannel`. Nobody, admin included, may add a third person to a 1:1 DM.

`ChatDirectoryTest::test_nobody_can_edit_the_membership_of_a_one_to_one_direct_message` asserts the bypass is live before asserting the refusal, so it fails loudly if the package ever drops it.

### Customer and guest

The customer widget is not gated on `chat.view`. Guest auth is the conversation `guest_token` minted at `POST /chat/start` (`Str::random(40)`) and validated per request in `ChatWidgetController` and `ChatGuestAuthController`. Authenticated customers use the `customers` guard and the same controller scoped to their own inbox.

---

## 8. API and Route Table

There are **57** chat routes as of 2026-09-16 (47 admin, 10 client) — `php artisan route:list --path=chat` is the authority, and the tables below cover the engine rather than every saved-reply and settings route added since. Admin routes are in `routes/admin/config.php` (group `prefix('admin') name('admin.') middleware('web','auth','admin','throttle:admin')`) with per route permission gates. Client routes are in `routes/chat.php` (`prefix('chat') name('chat.') middleware('web','throttle:30,1')`, unauthenticated, guest token scoped).

Run `php artisan route:list --path=chat` to reproduce this table verbatim.

### Admin routes: `routes/admin/config.php`

| Method | URI | Name | Auth | Controller | Notes |
|---|---|---|---|---|---|
| GET | `admin/chat` | `admin.chat.index` | `permission:chat.view` | `ChatController@index` | Slack layout, sidebar plus message pane. 50 messages per conversation, cursor page on scroll. |
| GET | `admin/chat/search` | `admin.chat.search` | `permission:chat.view` | `ChatController@search` | History search. `LIKE '%q%'` over `chat_conversation_messages.body`, 30 per page, desc. See section 10. |
| GET | `admin/chat/search-entities` | `admin.chat.search-entities` | `permission:chat.view` plus gated per entity type (`customers.view` etc) | `ChatController@searchEntities` | Typeahead over `Product`, `Customer`, `CustomerContact`, `Ticket`. |
| GET | `admin/chat/inbox` | `admin.chat.inbox` | `permission:chat.view` plus `policy:operate` for mutate | `ChatController@inbox` | Operator queue. List unassigned `customer_inbox` rooms. |
| GET | `admin/chat/unread` | `admin.chat.unread` | `permission:chat.view` | `ChatController@unread` | Badge counts. Derived from `last_read_message_id`. |
| POST | `admin/chat/presence` | `admin.chat.presence` | `permission:chat.view` | `ChatController@presenceHeartbeat` | Presence heartbeat. Not throttled on the chat limiter. |
| GET | `admin/chat/channels` | `admin.chat.channels.browse` | `permission:chat.view` | `ChatController@browseChannels` | The public channel directory. Public, non archived rooms the caller may `view`, each flagged `joined`. The only way to find a channel you are not in, since the sidebar lists membership. 50 rows, `?q=` over name and topic. |
| POST | `admin/chat/channels` | `admin.chat.channels.store` | `permission:chat.view` | `ChatController@storeChannel` | Create a channel. Requires `chat.create_channel` or `chat.manage`. Body max not on slug. |
| GET | `admin/chat/channels/{c}/members` | `admin.chat.channels.members.index` | `permission:chat.view` plus `policy:view` | `ChatController@members` | Roster plus `can_manage` / `can_leave` / `can_join` for the caller. The flags combine the policy with membership and the conversation's shape, so an admin (who passes every policy, see section 7) is not offered Join in a room they are standing in. |
| PUT | `admin/chat/channels/{conversation}` | `admin.chat.channels.update` | `permission:chat.view` | `ChatController@updateChannel` | Rename, update topic or purpose. Policy gated. |
| POST | `admin/chat/channels/{c}/archive` | `admin.chat.channels.archive` | `permission:chat.view` plus `policy:operate` | `ChatController@archiveChannel` | Sets `archived_at`, freezes writes. |
| POST | `admin/chat/channels/{c}/unarchive` | `admin.chat.channels.unarchive` | `permission:chat.view` plus `policy:operate` | `ChatController@unarchiveChannel` | Clears `archived_at`. |
| POST | `admin/chat/channels/{c}/join` | `admin.chat.channels.join` | `permission:chat.view` | `ChatController@joinChannel` | Join a public channel. 422 unless `ChatConversation::isSelfJoinable()` — public, a channel, not archived. Private requires invite. |
| POST | `admin/chat/channels/{c}/leave` | `admin.chat.channels.leave` | `permission:chat.view` | `ChatController@leaveChannel` | Leave. 422 on a 1:1 DM. |
| POST | `admin/chat/channels/{c}/members` | `admin.chat.channels.members.store` | `permission:chat.view` plus policy | `ChatController@addMember` | Add member by user id. 422 on a 1:1 DM (`allowsMembershipChanges()`). |
| DELETE | `admin/chat/channels/{c}/members/{user}` | `admin.chat.channels.members.destroy` | `permission:chat.view` plus policy | `ChatController@removeMember` | Remove member. Same 1:1 DM refusal. |
| GET | `admin/chat/people` | `admin.chat.people` | `permission:chat.view` | `ChatController@people` | The staff roster (`ChatDirectory`): active users whose roles hold `chat.view`, minus the caller, 20 rows, `?q=` over name and email. With `?conversation=`, it is the add member picker instead — authorised with `policy:addMember` and with that room's existing members removed. |
| POST | `admin/chat/dms` | `admin.chat.dms.store` | `permission:chat.view` plus `policy:startDirectMessage` | `ChatController@storeDirectMessage` | Open a direct message. `user_ids[]`: one is a 1:1 DM (idempotent, 200 when it already existed), two or more a group DM (`name` optional). 422 if a target cannot reach the chat. |
| GET | `admin/chat/conversations/{conversation}/messages` | `admin.chat.messages.index` | `permission:chat.view` plus `policy:view` | `ChatController@fetchMessages` | History fetch. `?before_id=` cursor plus `?after_id=` for polling fallback. Max 50. |
| POST | `admin/chat/conversations/{conversation}/messages` | `admin.chat.messages.store` | `permission:chat.view` plus `throttle:chat(60/min)` | `ChatController@storeMessage` | Send message. `body` max 4000, `parent_id` optional, `attachments[]` optional. `ShouldBroadcastNow` publish. |
| PUT | `admin/chat/messages/{message}` | `admin.chat.messages.update` | `permission:chat.view` plus `throttle:chat` | `ChatController@updateMessage` | Edit own message. Sets `edited_at`. |
| DELETE | `admin/chat/messages/{message}` | `admin.chat.messages.destroy` | `permission:chat.view` | `ChatController@destroyMessage` | Soft delete. Keeps thread parent visible as `[deleted]`. Not on the 60/min limiter, tighter `throttle:admin` applies. |
| POST | `admin/chat/messages/{message}/reactions` | `admin.chat.messages.reactions` | `permission:chat.view` plus `throttle:chat` | `ChatController@toggleReaction` | Add or remove own emoji. Whitelist plus `emoji` regex. |
| POST | `admin/chat/messages/{message}/attachments` | `admin.chat.attachments.store` | `permission:chat.view` plus `throttle:chat` | `ChatController@storeAttachment` | Upload attachment to a message. Max 10 MB, whitelist (`image`, `pdf`, `doc`), executable MIME blocked, stored via `Storage` with `disk` plus `path`. |
| GET | `admin/chat/attachments/{attachment}` | `admin.chat.attachments.show` | `permission:chat.view` plus `signed` plus policy | `ChatController@showAttachment` | Signed download or inline preview. `URL::signedRoute` with 60 min expiry, `Content-Disposition` honouring `is_inline`. |
| POST | `admin/chat/messages/{message}/entity-links` | `admin.chat.entity-links.store` | `permission:chat.view` | `ChatController@storeEntityLink` | Attach `Product` etc to a message. Validated linkable tuple, emitted in `NewChatMessage`. |
| DELETE | `admin/chat/messages/{message}/entity-links/{link}` | `admin.chat.entity-links.destroy` | `permission:chat.view` | `ChatController@destroyEntityLink` | Detach an entity link. |
| GET | `admin/chat/conversations/{c}/threads/{parent}` | `admin.chat.threads.show` | `permission:chat.view` | `ChatController@fetchThread` | Fetch one thread by `parent_id`. |
| POST | `admin/chat/conversations/{c}/typing` | `admin.chat.typing` | `permission:chat.view` | `ChatController@typingHeartbeat` | Staff typing heartbeat. Presence channel, not on chat limiter. |
| POST | `admin/chat/conversations/{c}/read` | `admin.chat.read` | `permission:chat.view` | `ChatController@markRead` | Move `last_read_message_id` forward only. Dropped read reduces `unread` to 0. |
| POST | `admin/chat/inbox/{c}/assign` | `admin.chat.inbox.assign` | `permission:chat.view` plus `policy:operate` | `ChatController@assignOperator` | Assign the room to the caller. |
| POST | `admin/chat/inbox/{c}/transfer` | `admin.chat.inbox.transfer` | `permission:chat.view` plus `policy:operate` | `ChatController@transferConversation` | Transfer to another department. |
| POST | `admin/chat/inbox/{c}/close` | `admin.chat.inbox.close` | `permission:chat.view` plus `policy:operate` | `ChatController@closeConversation` | Close the inbox room, log to `audit_log`. |
| POST | `admin/chat/inbox/{c}/convert` | `admin.chat.inbox.convert` | `permission:chat.view` plus `policy:operate` | `ChatController@convertToTicket` | Create a ticket from the transcript via `TicketService::createFromChat`. |
| GET | `admin/chat/{chat}` | `admin.chat.show` | `permission:chat.view` | `ChatController@show` | Legacy `ChatSession` show fallback. Where numeric `chat`. Kept behind the more specific `/admin/chat/channels` so the Slack engine registers first. |

### Client routes: `routes/chat.php`

| Method | URI | Name | Auth | Controller | Notes |
|---|---|---|---|---|---|
| POST | `chat/guest-auth` | `chat.guest-auth` | unauthenticated, `throttle:30,1` | `ChatGuestAuthController` | Issue or renew a guest token for the widget session. |
| POST | `chat/start` | `chat.start` | unauthenticated, `throttle:30,1` | `ChatWidgetController@start` | Create a `customer_inbox` room. Body: `department`, `name` or `email` or `customer_id`, initial body. Returns conversation id plus `guest_token` the browser stores in session. |
| GET | `chat/{conversation}/messages` | `chat.messages` | guest token pinned to that one conversation | `ChatWidgetController@messages` | Transcript fetch for the guest or authenticated customer. |
| POST | `chat/{conversation}/messages` | `chat.send` | guest token | `ChatWidgetController@send` | Customer or guest send. Same 4000 char body limit. |
| POST | `chat/{conversation}/messages/{message}/attachments` | `chat.attach` | guest token | `ChatWidgetController@attach` | Customer upload, same 10 MB and MIME rules. |
| GET | `chat/{conversation}/attachments/{attachment}` | `chat.attachment` | guest token | `ChatWidgetController@attachment` | Customer attachment download via same signed path policy. |
| POST | `chat/{conversation}/typing` | `chat.typing` | guest token | `ChatWidgetController@typing` | Customer typing heartbeat to the same presence typing channel. |
| POST | `chat/{conversation}/rate` | `chat.rate` | guest token | `ChatWidgetController@rate` | Rate a closed conversation. |

**Starting again after a close.** `start` reuses the session's conversation only while it is open; a CLOSED one falls through and mints a new conversation, overwriting both session keys. No new endpoint was needed — what was missing was any way to ask a second time. The session still names the closed room, so the widget bound to it, `openTranscript()` hid the intro form and `applyAvailability()` short-circuited, leaving the customer with a rating widget and no way back short of clearing cookies. `#client-chat-restart` ("Start a new chat", shown beside the rating on any closed conversation) calls `resetForNewChat()`: it leaves the old websocket channel, clears the transcript and the old guest token, and re-checks office hours so the intro form is only offered when the desk will accept it.

Every mutate in the admin group except `destroyMessage` and the `typing` / `read` / `presence` heartbeats is wrapped in `throttle:chat` **without** the `throttle:admin`. That `withoutMiddleware` call is load bearing, the group header caps non GET admin requests at 30/min, which would otherwise bite before the 60/min chat budget and silently lose a composed message. Deviation 5 explains why chat writes are set looser (60 vs 30) and deviation 6 why the `chat` limiter is registered in `AppServiceProvider::boot()` rather than `bootstrap/app.php`.

---

## 9. Operations: Windows Reverb Service and IIS WebSocket Proxy

This app is served by IIS on Windows Server 2022. `php artisan reverb:start` is a foreground long running process. Without a supervisor it works until the first reboot, crash, or app pool recycle and then silently falls back to polling. The scripts are the deployment, not the docs.

### `scripts/reverb-service.ps1`: supervise Reverb

Prefers NSSM, falls back to Task Scheduler.

| Concern | How the script handles it |
|---|---|
| Absolute paths | Resolves an absolute `php.exe` path and an absolute `artisan` path and quotes both. Nothing is on `PATH` under the IIS app pool identity, and the application directory contains a space (`Local Sites`) that silently breaks unquoted Windows argument quoting without any diagnostic. These traps have already cost the updater a release. |
| NSSM branch | `nssm install HostVexaReverb <php> "<artisan>" reverb:start --host=127.0.0.1 --port=8081`, plus `AppDirectory`, `DisplayName`, `Start SERVICE_AUTO_START`, `AppExit Default Restart`, `AppRestartDelay 1000`, stdout and stderr to `storage\logs\reverb.log`. `sc.exe` is the fallback if NSSM is absent. |
| Scheduler branch | `New-ScheduledTaskAction -Execute <php> -Argument <same> -WorkingDirectory <appRoot>` with an `AtStartup` trigger, `SYSTEM` principal, `RestartInterval 1 minute`, `RestartCount 999`, `ExecutionTimeLimit 0`, `MultipleInstances IgnoreNew`. |
| Safety | Nothing happens without a switch. No argument or `-WhatIf` only prints the resolved interpreter, the resolved `artisan`, the working dir, the service name, and the exact command line and whether a supervisor already exists. `-Register` requires elevation. `-Run` foregrounds the process for a smoke test. `-Status` reports `Get-Service` or `Get-ScheduledTask`. `-Unregister` removes it. |

Typical use on the IIS host:

```powershell
powershell -File scripts\reverb-service.ps1 -WhatIf      # inspect only
powershell -File scripts\reverb-service.ps1 -Register    # install, requires elevation
powershell -File scripts\reverb-service.ps1 -Status      # verify Running
powershell -File scripts\reverb-service.ps1 -Run         # foreground smoke
Get-Service HostVexaReverb
```

### `scripts/iis-reverb-proxy.ps1`: expose Reverb through IIS

Reverb speaks the Pusher protocol on `/app/*` for client sockets and `/apps/*` for server publish. Behind IIS those prefixes must be reverse proxied to the loopback port Reverb is listening on, and IIS must be willing to hand the connection to the WebSocket protocol.

Two prerequisites:

1. The **WebSocket Protocol** Windows feature (`IIS-WebSockets` / `Web-WebSockets`). Without it IIS answers the `Upgrade` handshake with `400` and the browser quietly falls back to polling.
2. **Application Request Routing (ARR)** with its proxy enabled. Without ARR a rewrite rule of type `Rewrite` to an `http://` URL is not a visible browser error, IIS returns `500.19` for the whole site, because the rule references a module that is not there.

That second point is why the proxy rule is deliberately **not** shipped inside `public/web.config`. A rule that breaks every page of the panel on machines without ARR is a worse default than a chat that falls back to polling. This script adds it only when the prerequisites are present, after a timestamped backup of `web.config`.

The rule the script adds, as the **first** rewrite rule so the Laravel front controller (`^(.*)$`) does not swallow `/app/...` first:

```xml
<rule name="Reverb WebSocket Proxy" stopProcessing="true">
  <match url="^(app|apps)(/.*)?$" />
  <action type="Rewrite" url="http://127.0.0.1:8081/{R:0}" />
</rule>
```

Plus `<webSocket enabled="true" />` under `<system.webServer>`.

Script surface:

```powershell
powershell -File scripts\iis-reverb-proxy.ps1 -WhatIf             # report prerequisites, print the rule, do nothing
powershell -File scripts\iis-reverb-proxy.ps1 -Apply              # write the rule, backup first, throw if ARR or WebSockets missing
powershell -File scripts\iis-reverb-proxy.ps1 -Remove             # remove it again
powershell -File scripts\iis-reverb-proxy.ps1 -InstallPrerequisites
```

When ARR is not installed the script refuses to add the rule and suggests the documented fallback: expose Reverb on its own TLS port instead of proxying at all. Open the port on the firewall, give Reverb a certificate (`REVERB_SERVER_HOST=0.0.0.0` plus TLS options in the Reverb server config), set `VITE_REVERB_PORT` to that TLS port and `VITE_REVERB_SCHEME=https`, then `npm run build`. The chat bypasses IIS entirely.

Public client configuration behind the proxy:

```ini
VITE_REVERB_HOST=your.public.hostname
VITE_REVERB_SCHEME=https
VITE_REVERB_PORT=443
```

Then rebuild assets (`npm run build`). Before the proxy was added IIS gave `400` for the `Upgrade` handshake. After `400` should disappear. Verify the handshake once the rule is in place:

```bash
curl -i -N -H "Connection: Upgrade" -H "Upgrade: websocket" \
     -H "Sec-WebSocket-Version: 13" \
     -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" \
     http://127.0.0.1/app/$REVERB_APP_KEY
# Expect: 101 Switching Protocols
# 400 means the WebSocket feature is off
# 404 means the proxy rule is missing
# 500.19 means ARR is not installed or its proxy is not enabled
```

Dispatch reference: enabling ARR's proxy is `IIS Manager > server node > Application Request Routing Cache > Server Proxy Settings > tick "Enable proxy"`, which is what `scripts/iis-reverb-proxy.ps1` gates on.

---

## 10. Search and Pagination

`GET /admin/chat/search?q=&channel=&from=&to=` queries `chat_conversation_messages` with `LIKE '%q%'`. This is a **leading wildcard scan** that cannot use any index on `body`. That is an accepted v1 tradeoff at expected volume.

Mitigations that ship:

- The conversation whitelist is always resolved **first** from `ChatConversationPolicy::view()` before the `LIKE` touches any row. The query never scans the whole table unfiltered. The scoping is view based, not participant only, so publicly readable channels without joining are included (deviation 8), which matches the rest of the policy and fixes what was otherwise a second visibility rule.
- The query is additionally bounded by `channel`, `from`, `to` date filters when provided, then capped with `LIMIT 30`, ordered `created_at DESC` with id cursor.
- Archived rooms are searchable too. A hit from an archived room carries `conversation_archived: true` and links into the frozen room (deviation 9).
- The code comment in `ChatController@search` calls out the scan.

Conversation history is 50 messages per fetch, oldest first is a scroll top trigger:

```
GET /admin/chat/conversations/{id}/messages?before_id=123
GET /admin/chat/conversations/{id}/messages?after_id=456    # polling fallback when Echo is down
```

Keyboard shortcuts: `j` or `k` navigate messages, `r` opens the thread for the focused message, `e` edits own message, `cmd+K` opens the channel switcher. Vanilla JS, no SPA.

---

## 11. Notifications and Preferences

Three notifications ship, each on `database` plus `broadcast` to `App.Models.User.{id}`:

- `ChatMentionNotification` for `@user` and `@channel` parses,
- `ChatReplyNotification` for thread replies,
- `ChatAssignmentNotification` for inbox assignments.

Preference gating goes through `NotificationPreferenceService::isEnabled($user, $type)` where the types are `chat.mention`, `chat.reply`, `chat.assign`. The service intentionally has **no type whitelist**, an unmapped type with no `NotificationPreference` row defaults to `true` (`app/Services/NotificationPreferenceService.php:66-71`), so these three work as is with per user opt out rows and need no migration and no `SETTINGS_KEY_MAP` entry. Adding them there would have required a matching `settings` row read by `AppSettings::bool`, and a missing row silently flips the global default. Notifications **must** stay on `default` so the `emails,default` scheduled worker drains them (see section 5). Self mentions do not notify and edits do not double notify.

---

## 12. Security

Covered in `tests/Feature/Chat/ChatSecurityTest.php`.

- Message bodies are sanitised server side with `symfony/html-sanitizer`. Script tags are stripped, only a narrow safe tag list renders. Writing `<script>alert(1)</script>` returns a body without `script`.
- Markdown code blocks and inline formatting render safely. XSS via markdown is blocked in the same sanitiser pass.
- Rate limiting: `throttle:chat` is 60/min per user, defined in `AppServiceProvider::boot()` (deviation 6), applied with `withoutMiddleware('throttle:admin')` so the admin group 30/min never bites first (deviation 5). The 61st write returns `429`. `destroyMessage`, typing and presence heartbeats are deliberately not on the chat limiter.
- Length caps: `body` 4000, channel name derived slug capped at 50 via the channel slug regex, group DM capped at 50 members. Invalid payloads return `422`.
- Attachments: max 10 MB, whitelist (`image/png`, `image/jpeg`, `image/gif`, `image/webp`, `application/pdf`, plus document mimes, executable MIME is rejected), stored via `Storage::disk` with an absolute path helper on the model, access by `URL::signedRoute` with a 60 min expiry, unsigned or expired URLs return `403`. No absolute path is stored.
- Channel slug collision returns `422`. The derived slug check is what prevents it, with the display name allowed to be human.
- CSP is set globally by `SecurityHeaders` middleware, no page scoped tightening is shipped (deviation 3). AdminLTE depends on `'unsafe-inline'` in `script-src` and `style-src`, and browsers intersect multiple CSP headers, so a chat only tightening would have dropped that token and broken the chat page. The Reverb host and port are validated strictly before widening `connect-src` (see `ReverbConfig`).
- Guest attachment uploads are guest token scoped to one conversation. The controller proves the attachment belongs to the room the caller can read before serving it.

---

## 13. Attached States: Polling Fallback, Loading, and Errors

When Echo is connected the UI subscribes to the presence and private channels and shows live typing and reactions. When it disconnects:

- A `Realtime disconnected: polling` banner appears at the top of the message pane.
- The client polls `GET /admin/chat/conversations/{id}/messages?after_id={lastSeen}` every 5 seconds while that conversation is open. Polling is suspended when the tab is hidden via `visibilitychange` so hidden tabs do not burn the API.
- Failed sends show a **Retry** button and a toast. Archive or delete goes through a confirmation dialog.
- Empty states: empty channel list shows "No channels yet", empty message pane shows "No messages yet", inbox empty shows "No conversations in queue". Each has a call to action to create or join.
- Loading uses skeleton placeholders for the sidebar and the message list.
- The typed message body is retained on 429 or 422 so the browser does not lose composition.

---

## 14. Rebuild Assets

`public/build/` is tracked. The updater never runs `npm`. Rebuilding is a scheduled Todo 19 style task always in an **isolated worktree** so a interrupted `npm run build` does not corrupt the main worktree in the middle of other work:

```bash
git worktree add ../tmp-build HEAD
cd ../tmp-build
npm ci
npm run build
git -C ../tmp-build add public/build
git commit -m "chore: rebuild public/build"
git -C ../tmp-build push
```

`ChatQueueDisciplineTest` also asserts `npm run build` succeeds when Echo modules change and that `web/app` assets are not shipped empty.

---

## 15. Accepted Deviations

These 14 items were recorded during reconciliation on 2026-09-12. Final verification must not reject them.

1. **`.env.example` keeps `BROADCAST_CONNECTION=log`**. The plan said never leave it as `log`. Left deliberately, with a rationale comment at `.env.example:54-57`. With `ShouldBroadcastNow` plus `connect_timeout: 2`, defaulting a fresh clone to `reverb` before the Windows service is registered would block every message send on a dead socket. `log` degrades to working if non realtime polling. Flip to `reverb` as the post install step once the service is registered.

2. **Reconciliation did not re prove the full unfiltered suite**. The 372 Chat tests and 9 `SeederIntegrityTest` cases were green at reconciliation. The bare unfiltered run timed out past 120s. The 1372 / 1375 baseline with three documented pre existing failures stands unverified at this doc.

3. **No new CSP is shipped for chat.** The plan said add CSP headers for chat pages. The app already emits a blocking CSP via `SecurityHeaders` middleware. Browsers intersect multiple policies, so a second page scoped header would drop the `'unsafe-inline'` AdminLTE depends on and break the chat page. The chat page is asserted covered by the existing policy. A nonce based tightening is real work but app wide.

4. **Channel name regex is enforced on the derived slug, not the display name.** Applied literally to the name, `^[a-z0-9][a-z0-9_\-]*$` refuses `Deploys` and breaks four `ChatControllerTest` cases. Display names remain human, slugs are validated.

5. **`throttle:chat` loosens the chat write cap from 30/min to 60/min.** Deliberate. The pre existing `throttle:admin` capped chat writes at 30/min, below what a person types in a busy channel, and a 429 loses a composed message. Chat writes are now `throttle:chat` at 60/min and specifically `withoutMiddleware('throttle:admin')` so the tighter one does not bite first.

6. **The `chat` limiter is defined in `AppServiceProvider::boot()`, not `bootstrap/app.php`.** Every other limiter lives there, and `RateLimiter::for()` inside `withMiddleware()` runs before the facade root is bound, so `bootstrap/app.php` would not work reliably.

7. **Customer sees `Support is typing`, never a staff name.** One `TypingIndicator` payload serves both the staff presence channel and the guest private channel, so staff watching the same inbox also see `Support`. Chosen over leaking a real employee name into an unauthenticated browser, matching what `forClient()` already does to the transcript. Staff channels and DMs are unaffected. The customer can still see the operator numeric `user_id` because `chat.js:928` needs it to ignore an operator own typing, disclosed, not hidden.

8. **Search is scoped to conversations the user may READ, not only those they PARTICIPATE in.** The plan said always constrain to participant conversations before the `LIKE`. Participant only would hide publicly readable channels and would make search near useless while creating a second visibility rule beside `ChatConversationPolicy::view()`. The scope is view based, private channels and DMs remain participant only, pinned by two named tests, proven by removing the scoping which turns five tests red.

9. **Archived conversations are searchable** by anyone entitled to read them, consistent with archive meaning freeze not delete. Hits carry `conversation_archived: true`.

10. **`catalog_products/show.blade.php` is not a timeline surface and `CustomerContact` has no show view.** `MessageEntityLink::LINKABLE_TYPES` whitelists `Product`, `CatalogProduct` is a different model, so that page can never hold a link. Contacts surface on the customer page, matching `MessageEntityLink::url()` for three usable surfaces, not five.

11. **The audit table is `audit_log`, not `activity_log`.** `audit_log` carries `entity_type` and `entity_id` these events need, `activity_log` is rendered back to customers on the client dashboard where internal chat admin does not belong.

12. **Three `@include` lines are present in the working tree but not yet committed**: `customers/show.blade.php:655`, `tickets/show.blade.php:957`, `products/show.blade.php:289`. Each file carries another agent session uncommitted work, so staging them would bundle unreviewed changes. The entity timeline is **dead code until those lines land**. Todo 21 or the owning session commits them.

13. **Staff watching a customer inbox see `Support` on live websocket messages, but real names after an HTTP fetch.** `NewChatMessage` and `ChatMessageEdited` broadcast on one `private-chat.conversation.{id}` channel both guest and operator subscribe to, so a single payload serves both audiences and is rendered `forClient()` for `customer_inbox`. A second staff only channel would be a design change, not a patch, and is consistent with deviation 7.

14. **CSP publishes both `ws://` and `wss://` for the configured host and port.** Slightly wider than strictly needed when IIS terminates TLS and Reverb is http only, but harmless. The host is strictly validated. With no Reverb configured, `connect-src` stays exactly `'self'`.

---

## 16. Evidence and Verification

| Check | How to run |
|---|---|
| Route table verbatim | `php artisan route:list --path=chat` |
| Channel auth closures delegate to policy | `grep -n "Broadcast::channel" routes/channels.php` and `php artisan test --filter=ChatBroadcastAuth` |
| No queued chat event | `php artisan test --filter=ChatBroadcastQueueTest` plus `grep -rn "ShouldBroadcast" app/Events/Chat --include="*.php"` shows only `ShouldBroadcastNow` |
| Jobs table stays empty | `DB::table('jobs')->count() === 0` after `ChatService::sendMessage` |
| Only allowed queues are `emails,default` | `php artisan test --filter=ChatQueueDisciplineTest` and `grep -rn "onQueue(" app --include="*.php"` shows no chat queue name |
| Scheduled worker is `emails,default` | `select-string "queue:work --queue=emails,default" routes/console.php` line 175 |
| Fresh install viable | `composer seed:smoke` plus `php artisan test --filter SeederIntegrityTest` plus `php artisan migrate:fresh --seed && php artisan migrate:rollback --step=7 && php artisan migrate` |
| Browser CSP is tight | Inspect any chat page `Content-Security-Policy: ... connect-src` header, expect `'self'` without Reverb and `'self' ws://host:port wss://host:port` when valid |
| No cross channel leak | `php artisan test --filter=ChatSearchTest` includes the non participant private message invisibility case |

Historical last verified counts cited in evidence files at this doc date: 372 Chat tests passing, 9 `SeederIntegrityTest` cases passing, `seed:smoke` ALL PASS, `npm run build` exit 0. Re verify with the commands above rather than quoting this page.

Evidence files live under `.omo/evidence/slack-like-chat-system/`. See that directory `README.md` for the index of all task, fix, and verification evidence.
