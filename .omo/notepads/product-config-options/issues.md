# Issues — product-config-options

Problems and gotchas encountered during work on this plan.

_Auto-scaffolded by /start-work. Append new entries below - never overwrite._

---
## 2026-08-06 - ProductConfigOptionsIntegrationTest
- Pre-existing schema/model mismatch: `server_groups` (no `updated_at`) and `server_group_members` (no timestamps) cannot be written via Eloquent `::create()` because both models keep default `$timestamps = true`. Tests work around it with `timestamps = false`; the admin ServerGroupController::store create path is likely throwing in production too — worth a follow-up fix (add timestamp columns or set `$timestamps = false` on the models).

## 2026-08-06 - FIXED: ServerGroup/ServerGroupMember timestamps
- RESOLVED: Added `public $timestamps = false;` to both `app/Models/ServerGroup.php` and `app/Models/ServerGroupMember.php` (schema has no `updated_at` on `server_groups`, no timestamps at all on `server_group_members`).
- `ServerGroupController::store()`/`update()` now work via the standard `create()` path (previously would throw "unknown column updated_at").
- `tests/Feature/ProductConfigOptionsIntegrationTest::makeServerGroupMember()` updated to use `ServerGroup::create()` / `ServerGroupMember::create()` directly — removes the per-instance `timestamps = false` workaround and proves the fix.
- Full suite re-run: 136/136 GREEN (438 assertions).
