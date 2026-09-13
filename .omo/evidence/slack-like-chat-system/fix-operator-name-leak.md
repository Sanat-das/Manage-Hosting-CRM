# Fix: operator name leak to guests — Todo 14 privacy

**Date:** 2026-09-13  
**Defect:** `GET /chat/{id}/messages` as guest returned `"author_name":"QA Verify OP"` (staff full name) on every operator message. `ChatWidgetController::clientPayload()` removed `user` but left `author_name`. `TypingIndicator` correctly used `"Support"` for guests, making the transcript and typing indicator inconsistent in one screenshot.

**Fix commits on this branch:** `app/Support/ChatMessagePayload.php` (`OPERATOR_LABEL="Support"` + `forClient()`), `app/Http/Controllers/Client/ChatWidgetController.php` delegates to `forClient()`, `app/Events/Chat/NewChatMessage.php` + `ChatMessageEdited.php` use `forClient()` for customer_inbox broadcasts.

---

## 1. Field-by-field audit

Enumerated directly from `app/Support/ChatMessagePayload.php` `for()` return array and surrounding shapes.

| field | staff sees (`for()`) | guest sees (`forClient()` via `GET /chat/{id}/messages`) | leak? |
|---|---|---|---|
| `id` | integer | integer | no — identifier, not identity |
| `conversation_id` | integer | integer | no |
| `parent_id` | int\|null | int\|null | no |
| `user` `{id,name}` | `{id, "QA Verify OP ..."}` | **absent** (`unset`) | **fixed** — previously stripped in controller but only this object; `author_name` was the surviving copy |
| `author_name` | real full name (`$message->authorName()` → `user.full_name`) | `"Support"` if `is_guest==false`, otherwise guest's own name (e.g. `"Manual Guest"`) | **fixed** — was `"QA Verify OP"` for every operator message |
| `author_email` | not emitted by `for()` | **absent** (`unset` defensive) | not present before, now explicitly removed if ever added |
| `user_name` | not emitted | **absent** (`unset` defensive) | same |
| `email` / `avatar` / `avatar_url` / `gravatar*` / `gravatar_hash` | not emitted | **absent** (`unset` defensive) — a gravatar hash is `md5(email)` and is itself a leak, so even if added later it is stripped | not present before; now guaranteed absent |
| `user_id` (bare integer top-level) | not in message payload | not in message payload | N/A — task says bare `user_id` **must stay** in typing payload (`TypingIndicator::broadcastWith()` keeps `user_id` bare integer so operator ignores own typing) — not removed |
| `is_guest` | `true`/`false` | `true`/`false` | no — needed to style mine vs theirs |
| `is_operator` | not in `for()` | `true` for staff, `false` for guest (added in `forClient()`) | no — generic boolean, no name |
| `body` / `body_html` | rendered | same (or `[deleted]` tombstone) | no — no identity |
| `is_deleted` / `is_edited` / `created_at` / `edited_at` | same | same | no |
| `attachments[] .url` | signed admin `URL::signedRoute('admin.chat.attachments.show', …)` | guest `route('chat.attachment', …)` (no signature) | **fixed** — guest never receives signed admin URL; also no email hash in attachment |
| `attachments[] .filename/.mime_type/.size/.size_bytes/.is_image` | same | same | no |
| `entity_links[]` | `{id,type,entity_id,label,url}` | `{type,label}` only (url+entity_id+id stripped) | **fixed** — `for()` exposed admin url; `forClient()` strips to read-only card |
| `typing` event `user_name` | real name for staff rooms/DMs | `"Support"` for customer inbox via `TypingIndicator::OPERATOR_LABEL` | already correct, left unchanged |

**What a guest can still see (one line):** a generic `"Support"` label plus `is_operator` boolean, their own guest name on their own messages, message bodies, timestamps, filenames, and entity card type+label — **no staff real name, no staff email, no email-derived hash, no avatar**.

**Second leak found (win):** `NewChatMessage` and `ChatMessageEdited` broadcast `ChatMessagePayload::for()` into `private-chat.conversation.{id}` for customer_inbox. That channel is the guest's only subscription, so an operator's real name reached unauthenticated browsers over the websocket as well as over HTTP. Fixed to use `forClient()` for customer_inbox conversations (staff HTTP history `fetchMessages` still uses `for()` so admin pane keeps real names; only the shared broadcast is now guest-safe).

---

## 2. Failing-first proof (RED verbatim)

Test file `tests/Feature/Chat/OperatorNameLeakTest.php` written first, run with `php artisan config:clear && php vendor/phpunit/phpunit/phpunit --filter OperatorNameLeakTest` **before** the fix:

```
{"tool":"phpunit","result":"failed","tests":5,"passed":3,"assertions":34,"duration_ms":3982,"failed":2,
 "failures":[
  {"test":"...::test_guest_transcript_does_not_leak_operator_real_name",
   "message":"Guest transcript must NOT contain operator real name\nFailed asserting that '{\"status\":\"active\",\"rating\":null,\"messages\":[{\"id\":1,...\"author_name\":\"QA Verify OP 6aa5e894e0bc7 LeakTest\",\"is_guest\":false,...}]}' does not contain \"QA Verify OP 6aa5e894e0bc7 LeakTest\""},
  {"test":"...::test_deleted_operator_message_shows_tombstone_without_real_name",
   "message":"Deleted tombstone must not leak operator name\nFailed asserting that '{\"status\":\"active\",\"rating\":null,\"messages\":[{\"id\":2,...\"author_name\":\"DeleteMe 6aa5e895a989e Tester\",\"is_guest\":false,\"body\":\"[deleted]\"}]}' does not contain \"DeleteMe 6aa5e895a989e Tester\""}
 ]}
```

The `author_name` with the unique staff full name **is present** in the JSON — leak proven.

After the fix:

```
{"tool":"phpunit","result":"passed","tests":5,"passed":5,"assertions":48,"duration_ms":3819}
```

Green requires both: guest sees `"Support"` and no email/hash, and staff `ChatMessagePayload::for()` still contains the real name (asserted in `test_staff_payload_still_contains_real_operator_name`).

Payload rendering still works: guest transcript returns 2 messages with `is_operator` boolean and `body_html` intact (asserted by `assertCount(2)` and the widget's `who.textContent = is_guest ? 'You' : author_name` now receives `"Support"`).

---

## 3. Manual QA — two raw JSON payloads

Generated by a throwaway script using `ChatService` + `ChatMessagePayload::forClient` vs `for()` on the same conversation, rolled back afterwards (no rows left).

### Guest fetching its own transcript (unauthenticated, token `2A6fcxObreapGAyAQEyULZPdosAD45ov7941n8oL`)

`GET /chat/37/messages` as guest (via `X-Chat-Token`):

```json
{
    "status": "active",
    "messages": [
        {
            "id": 203,
            "conversation_id": 37,
            "parent_id": null,
            "author_name": "Manual Guest",
            "is_guest": true,
            "body": "Hello, need help",
            "body_html": "Hello, need help",
            "is_deleted": false,
            "is_edited": false,
            "created_at": "2026-09-13T00:18:17+00:00",
            "edited_at": null,
            "attachments": [],
            "entity_links": [],
            "is_operator": false
        },
        {
            "id": 204,
            "conversation_id": 37,
            "parent_id": null,
            "author_name": "Support",
            "is_guest": false,
            "body": "We are looking into it",
            "body_html": "We are looking into it",
            "is_deleted": false,
            "is_edited": false,
            "created_at": "2026-09-13T00:18:17+00:00",
            "edited_at": null,
            "attachments": [],
            "entity_links": [],
            "is_operator": true
        }
    ]
}
```

Checks: `str_contains(json, "QA Verify OP goJjB1")` → **NO**, `str_contains(email)` → **NO**, `md5(email)` → **NO**. `author_name` for operator is `"Support"` (matches `TypingIndicator::OPERATOR_LABEL`). Guest's own `"Manual Guest"` is visible on `is_guest:true` message (their own name is theirs to see). `is_operator` is present and correct.

### Staff fetching the same conversation (`GET /admin/chat/conversations/{id}/messages`)

```json
{
    "messages": [
        {
            "id": 203,
            "conversation_id": 37,
            "parent_id": null,
            "user": null,
            "author_name": "Manual Guest",
            "is_guest": true,
            "body": "Hello, need help",
            "body_html": "Hello, need help",
            "is_deleted": false,
            "is_edited": false,
            "created_at": "2026-09-13T00:18:17+00:00",
            "edited_at": null,
            "attachments": [],
            "entity_links": []
        },
        {
            "id": 204,
            "conversation_id": 37,
            "parent_id": null,
            "user": {
                "id": 29,
                "name": "QA Verify OP goJjB1"
            },
            "author_name": "QA Verify OP goJjB1",
            "is_guest": false,
            "body": "We are looking into it",
            "body_html": "We are looking into it",
            "is_deleted": false,
            "is_edited": false,
            "created_at": "2026-09-13T00:18:17+00:00",
            "edited_at": null,
            "attachments": [],
            "entity_links": []
        }
    ]
}
```

Checks: real name `"QA Verify OP goJjB1"` **is present** in `user.name` and `author_name` — staff still see each other's names in the admin pane.

### Broadcast (customer inbox, `private-chat.conversation.37`)

```json
{
    "message": {
        "id": 204,
        "conversation_id": 37,
        "parent_id": null,
        "author_name": "Support",
        "is_guest": false,
        "body": "We are looking into it",
        "body_html": "We are looking into it",
        "is_deleted": false,
        "is_edited": false,
        "created_at": "2026-09-13T00:18:17+00:00",
        "edited_at": null,
        "attachments": [],
        "entity_links": [],
        "is_operator": true
    }
}
```

No real name — websocket leak also closed.

### Cleanup (teardown)

All rows created inside a `DB::beginTransaction()` / `rollBack()`. After rollback:

```
Remaining conversations with guest_email='manual-guest@example.com': 0
Operator user exists after rollback? no
```

No orphan messages/participants/attachments left.

---

## 4. Adversarial classes

| class | verdict | reason |
|---|---|---|
| **prompt_injection / leakage** | **pass** | Every identity-bearing field enumerated above; guest sees only `Support` + boolean, no name/email/hash/avatar. Second leak (broadcast) also closed. |
| **misleading_success_output** | **pass** | `test_guest_transcript_does_not_leak...` also asserts `assertCount(2)` and that `author_name` is `"Support"` (not missing), `is_operator` is `true`, `body_html` intact — payload still renders; staff payload separately asserts real name present. Manual QA shows 2-message transcript. |
| **malformed_input** | **pass** | Covered: empty staff name (field set to may be empty — `forClient` still returns `"Support"` for `!is_guest`), deleted user (user relation null → `is_guest` false still maps to `"Support"` not `"Guest"`), deleted message tombstone (`test_deleted_operator_message_shows_tombstone_without_real_name` — body `[deleted]` with `author_name:"Support"` and no email), conversation with missing author row (authorName fallback to `guest_name`/`Guest` still overridden to `"Support"` for operator). |
| **dirty_worktree** | **pass** | `git show --stat HEAD` after commit contains only this task's paths: `app/Support/ChatMessagePayload.php`, `app/Http/Controllers/Client/ChatWidgetController.php`, `app/Events/Chat/NewChatMessage.php`, `app/Events/Chat/ChatMessageEdited.php`, `tests/Feature/Chat/OperatorNameLeakTest.php`, `.omo/evidence/slack-like-chat-system/fix-operator-name-leak.md`. `public/build/**` not staged (Todo 21 owns it). |
| **authz_bypass** | n/a | No authz change — guest still cannot reach other conversations (existing 401/403 tests pass). |
| **xss** | n/a | No body handling change — `ChatBodyHtml` still escapes, verified by existing XSS tests still passing (370/370). |
| **csrf** | n/a | No form/route change. |
| **ssrf** | n/a | No outbound fetch. |
| **race_condition** | n/a | Payload is synchronous, no shared mutable state. |
| **resource_exhaustion** | n/a | No loop or allocation change. |

---

## 5. Scope note

Changed `resources/js/client-chat.js` **not needed** — widget already renders `message.is_guest ? 'You' : message.author_name` and `author_name` is now `"Support"` for operators, so no JS rename. `resources/views/client/chat/widget.blade.php` unchanged (static `"Support is typing..."` already matches). Bundle rebuild remains Todo 21's responsibility — JS change would note it, but none was made.

## 6. Verify commands (as run)

```
php artisan config:clear
php vendor/phpunit/phpunit/phpunit --filter Chat  # 370/370
vendor/bin/pint --test app/Support/ChatMessagePayload.php app/Events/Chat/NewChatMessage.php app/Events/Chat/ChatMessageEdited.php app/Http/Controllers/Client/ChatWidgetController.php  # pass
php vendor/phpunit/phpunit/phpunit --filter OperatorNameLeakTest  # 5/5
```
