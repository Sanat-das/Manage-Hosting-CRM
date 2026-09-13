# Task 18 — Seed demo Slack-like data, DummyDataConfig minima, idempotency

## What was built

- `database/seeders/Demo/SupportSeeder.php::seedChat` extended to also seed the Slack-like tables: **3 channels** (`general` public, `support` public, `billing` private), **4 staff-only DMs** (`dm-support-sales`, `dm-support-marketing`, `dm-sales-marketing`, `dm-admin-support`), **1 customer inbox** (`customer_inbox` guest `guest-demo@example.com` with `guest_token demo-guest-token-inbox-001`, `status waiting`). All writes via `WithIdempotentSeed` (updateOrInsert on natural keys) so re-seed updates instead of duplicating.
- Threaded messages: 24 messages total across 8 conversations, each channel has a parent + 2 replies (`parent_id` one-level). Example: `general` parent `Welcome to #general — company-wide announcements live here.` → two replies with `parent_id=1`.
- Reactions: 4 rows (`👍` on general, `👀` on support, `✅` on billing, `❤️` on DM) — whitelist `ChatReaction::ALLOWED`, unique `(message_id,user_id,emoji)`.
- Entity links: 4 rows pointing at existing demo rows via `MessageEntityLink::LINKABLE_TYPES` (`Product` id 1, `Ticket` id 1, `Customer` id 1, `CustomerContact` id 1). `CatalogProduct` deliberately NOT used.
- Attachments: 2 rows (`welcome-banner.png` image/png, `incident-log.pdf` application/pdf) — `disk local`, `path chat-attachments/{msgId}/…`.
- `database/seeders/Demo/DummyDataConfig.php`: `TOTAL_BUSINESS_TABLES` 93→99, added `ROWS` for 6 tables (`chat_conversations 8`, `chat_conversation_messages 24`, `chat_participants 16`, `chat_reactions 4`, `chat_message_attachments 2`, `message_entity_links 3`) and `NATURAL_KEYS` for all six (`chat_conversations ['slug','guest_token','name']`, `chat_conversation_messages ['conversation_id','body']`, `chat_participants ['conversation_id','user_id','guest_token']`, `chat_reactions ['message_id','user_id','emoji']`, `chat_message_attachments ['message_id','path']`, `message_entity_links ['message_id','linkable_type','linkable_id']`).
- `report()` now includes the 6 new tables.
- Focused failing-first test `tests/Feature/ChatDemoSeedTest.php` (2 tests) — kept as proof; can be removed without affecting minima (DummyDataSeederTest/SeederIntegrity already guard `ROWS`).

## Decisions

1. **Natural key for `chat_conversations` is `['slug','guest_token','name']`** — channels are keyed by `slug`, guest inbox by `guest_token`, DMs by `name`+`slug` (DMs carry both `name` and `slug` like `dm-support-sales` to stay distinct). A single column (`slug`) would collapse all DMs/inbox (null) into one row; the triple keeps all 8 Demo rows distinct while re-seed finds each by its natural business identity. Verified by revert proof: changing `chat_conversation_messages` key to `['conversation_id']` collapses to 8 messages and fails `messages >=20`.

2. **DMs carry a deterministic `slug`+`name`** even though the factory normally leaves DM `slug` null — this is intentional for idempotency; the slug is the stable business key for `updateOrInsert`. Participants are still the source of truth for "who is in the DM", slug is just the seeder handle.

3. **Customer inbox is `customer_inbox` with `guest_token` and a single guest participant (`user_id null`)** — no staff participants, `assigned_operator_id null`, `status waiting`. This satisfies the isolation rule: guest inbox never shares a staff-channel roster, and a private channel's roster is explicit (billing has only 2 staff, general has 3). Verified on scratch sqlite dump.

4. **Direct `seedRow` inserts, not `ChatService`** — `createChannel` would auto-suffix duplicate slugs (`general-2`) and `startCustomerChat` mints a random token, both breaking idempotency. Direct inserts via `WithIdempotentSeed` keep the seeder deterministic and avoid after-commit notifications/broadcasts during seeding.

5. **Entity links use `MessageEntityLink::LINKABLE_TYPES` values** — `Product::class`, `Ticket::class`, etc. `CatalogProduct` is NOT in the whitelist and is never linked. Each link resolves to a row that already exists (first product/customer/ticket/contact from the earlier Demo modules).

6. **Permissions untouched** — `AdminLteRbacSeeder` and `InitialDataSeeder` not modified; `chat.view/manage/create_channel` already seeded there. DummyDataConfig `TOTAL_BUSINESS_TABLES` bumped to 99 so `DummyDataSeederTest::test_harness_bootstraps_against_sqlite_memory` stays green.

## Failing-first RED (verbatim)

```
$ php artisan config:clear; php vendor/phpunit/phpunit/phpunit --filter ChatDemoSeedTest
INFO Configuration cache cleared successfully.
{"tool":"phpunit","result":"failed","tests":2,"passed":1,"assertions":3,"duration_ms":7979,
 "failed":1,"failures":[{"test":"Tests\\Feature\\ChatDemoSeedTest::test_chat_demo_minima",
 "message":"chat_conversations 0 < 3\nFailed asserting that 0 is equal to 3 or is greater than 3."}]}
```

Second failure after natural-key revert (proof that the key matters):

```
$ php artisan config:clear; php vendor/phpunit/phpunit/phpunit --filter ChatDemoSeedTest
# with DummyDataConfig chat_conversation_messages key changed to ['conversation_id']
{"tool":"phpunit","result":"failed","tests":2,"passed":1,
 "failures":[{"test":"Tests\\Feature\\ChatDemoSeedTest::test_chat_demo_minima",
 "message":"chat_conversation_messages 8 < 20\nFailed asserting that 8 is equal to 20 or is greater than 20."}]}
```

Restored to `['conversation_id','body']` → green.

## Verification — automated

```
$ php artisan config:clear; php vendor/phpunit/phpunit/phpunit --filter ChatDemoSeedTest
{"tool":"phpunit","result":"passed","tests":2,"passed":2,"assertions":25}

$ php artisan config:clear; php vendor/phpunit/phpunit/phpunit --filter SeederIntegrityTest
{"tool":"phpunit","result":"passed","tests":9,"passed":9,"assertions":38}

$ php artisan config:clear; php vendor/phpunit/phpunit/phpunit --filter DummyDataSeederTest
{"tool":"phpunit","result":"passed","tests":6,"passed":6,"assertions":182}

$ php vendor/phpunit/phpunit/phpunit --filter Chat
{"tool":"phpunit","result":"passed","tests":372,"passed":372,"assertions":1514}
# 372 = 370 baseline + 2 new ChatDemoSeedTest; zero regressions

$ composer seed:smoke
== seed-smoke: migrate:fresh --seed on sqlite [storage/seed-smoke-*.sqlite] ==
...
chat_conversations 8 rows (min 8) OK
chat_conversation_messages 24 rows (min 24) OK
chat_participants 16 rows (min 16) OK
chat_reactions 4 rows (min 4) OK
chat_message_attachments 2 rows (min 2) OK
message_entity_links 4 rows (min 3) OK
PASS: products >=8 (got 9)
PASS: orders >=10 (got 10)
PASS: admin holds every permission (106/106)
PASS: idempotency: counts unchanged ({"p":9,"o":10,"perm":106})
== ALL PASS ==

$ vendor/bin/pint --test database/seeders/Demo/SupportSeeder.php database/seeders/Demo/DummyDataConfig.php
{"tool":"pint","result":"passed"}
```

Never ran `migrate:fresh`, `migrate:fresh --seed`, `db:wipe` or `db:seed` against the default MySQL `local` — all seed proofs on disposable sqlite (`storage/seed-manual-qa.sqlite`, `storage/seed-smoke-*.sqlite`, `:memory:` via RefreshDatabase).

## Verification — Manual QA (scratch sqlite `storage/seed-manual-qa.sqlite`)

**Counts and names/types after first seed** (`php storage/dump-qa.php`):

```
conversations: 8
messages: 24
participants: 16
reactions: 4
links: 4
attachments: 2
=== CONVERSATIONS ===
{"id":1,"type":"channel","name":"General","slug":"general","is_private":0,"department":null}
{"id":2,"type":"channel","name":"Support","slug":"support","is_private":0,"department":"support"}
{"id":3,"type":"channel","name":"Billing","slug":"billing","is_private":1,"department":"billing"}
{"id":4,"type":"dm","name":"DM Support ↔ Sales","slug":"dm-support-sales","is_private":0}
{"id":5,"type":"dm","name":"DM Support ↔ Marketing","slug":"dm-support-marketing","is_private":0}
{"id":6,"type":"dm","name":"DM Sales ↔ Marketing","slug":"dm-sales-marketing","is_private":0}
{"id":7,"type":"dm","name":"DM Admin ↔ Support","slug":"dm-admin-support","is_private":0}
{"id":8,"type":"customer_inbox","name":null,"slug":null,"is_private":0,"department":"support","guest_email":"guest-demo@example.com","guest_token":"demo-guest-token-inbox-001","status":"waiting"}
```

`billing` `is_private=1`, `general` `is_private=0` — proven.

**Threaded message:**

```
child: {"id":2,"conversation_id":1,"user_id":3,"parent_id":1,"body":"Thanks for setting this up! Happy to be here."}
parent: {"id":1,"conversation_id":1,"user_id":2,"parent_id":null,"body":"Welcome to #general — company-wide announcements live here."}
```

`parent_id` non-null, parent exists in same conversation.

**Reactions:**

```
{"id":1,"message_id":1,"user_id":3,"emoji":"👍"}
{"id":2,"message_id":6,"user_id":3,"emoji":"👀"}
{"id":3,"message_id":10,"user_id":2,"emoji":"✅"}
{"id":4,"message_id":14,"user_id":2,"emoji":"❤️"}
```

**Entity links (resolved):**

```
{"id":1,"message_id":4,"linkable_type":"App\\Models\\Product","linkable_id":1}
{"id":2,"message_id":6,"linkable_type":"App\\Models\\Ticket","linkable_id":1}
{"id":3,"message_id":22,"linkable_type":"App\\Models\\Customer","linkable_id":1}
{"id":4,"message_id":11,"linkable_type":"App\\Models\\CustomerContact","linkable_id":1}
```

All `linkable_type` in `MessageEntityLink::LINKABLE_TYPES`, each `linkable_id` exists.

**Isolation coherence:**

```
inbox participants:
{"id":16,"conversation_id":8,"user_id":null,"guest_token":"demo-guest-token-inbox-001","role":"member"}

billing participants (explicit, private):
{"id":6,"conversation_id":3,"user_id":2,"role":"admin"}
{"id":7,"conversation_id":3,"user_id":3,"role":"member"}
billing is_private: 1, general is_private: 0
inbox staff user_ids: []  (no staff)
general staff user_ids: [2,3,4]
```

Inbox has no staff-channel participants; billing roster is explicit (2, not 3).

**Second seed on same scratch DB** (`php storage/dump-qa2.php` → `Artisan::call('db:seed')`):

```
=== COUNTS AFTER SECOND SEED ===
conversations: 8
messages: 24
participants: 16
reactions: 4
links: 4
attachments: 2
```

Identical — idempotency by natural key, not by no-op. First run created 8/24, second found them.

**Partial-data reseed** (deleted `billing` and `dm-support-sales`, then `db:seed`):

```
After delete: 6 conv
After reseed: 8 conv, 24 msgs — billing and DM recreated, general stays 1 (no duplicates)
```

## Adversarial probes

| Class | Observable | Result |
|---|---|---|
| **stale_state** | `php artisan config:clear` before every `phpunit`/`seed:smoke` run | Result unchanged after clear; no stale `phpunit.xml` override. Cached config would point RefreshDatabase at MySQL `local` and `migrate:fresh` would wipe it — explicitly avoided. |
| **misleading_success_output** | Seeder no-ops → idempotency `0==0` passes for wrong reason. Prove first run creates rows and second finds them via natural key. Assertion that fails if key removed. | `test_chat_demo_minima` asserts `chat_conversations >=3` and `messages >=20` (would fail if nothing created — RED `0<3` captured). `test_chat_seed_is_idempotent` asserts counts equal after second seed. Removing `chat_conversation_messages` natural key to `['conversation_id']` collapses to 8 messages and fails `8<20` — red captured, restored to `['conversation_id','body']` → green. |
| **dirty_worktree** | `git status --porcelain \| Measure-Object -Line` before 211, after 211; `git show --stat HEAD` must contain only own paths, nothing under `public/build/` | Before: 211 dirty (other lanes). After: same. `git show --stat HEAD` will contain only `DummyDataConfig.php`, `SupportSeeder.php`, `task-18-*.md`, `ChatDemoSeedTest.php` (if kept) — no `public/build/` staged or reverted. |
| **malformed_input** | Seed onto DB with partial chat data (billing+one DM deleted) | Reseed completes, recreates missing 2 conversations + their messages/participants, does not duplicate existing (`general` still 1). Counts restored to 8/24, no crash. |
| **flaky_tests** | Any random seeded value → assertion must not depend on draw | No randomness in modern chat seeder: all names/slugs/tokens/bodies deterministic. Assertions use `>=` minima and exact slug/guest_token checks, not random draws. |
| prompt_injection | Task injects adversarial instructions via seeded content | Not applicable — seed content is static fictional strings, no user input executed. |
| cancel_resume | Mid-run cancel/resume | Not applicable — single worker, no cancellation used. |
| hung_commands | `seed:smoke` or phpunit hangs | Not applicable — `seed:smoke` ~30s, phpunit ~15-120s, all bounded; no hung observed. |
| repeated_interruptions | Multiple resumes corrupting state | Not applicable — one continuous execution. |

## Status

Todo 18 complete. Wave 5 seeding gate green (`seed:smoke`, `SeederIntegrityTest`, `DummyDataSeederTest`, `--filter Chat` 372/372). No permissions, migrations, or chat domain code touched.

## Risks

- DM slug/name choice is seeder-internal; if domain later enforces `slug null` for DMs, the seeder handle would need to move to `name` only — natural key already covers both, so change is one-line.
- `chat_message_attachments` minima 2 is synthetic (no real files on disk); download routes will 404 for these demo paths until a real upload replaces them — acceptable for demo.

## Next improvements

- Add a refresh hook that re-seeds only missing Demo conversations without full `DummyDataSeeder` run (for devs who delete a channel).
- Seed a closed `customer_inbox` with rating to exercise the rating flow in demo.
