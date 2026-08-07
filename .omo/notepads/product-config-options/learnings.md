# Learnings — product-config-options

Conventions, patterns, and successful approaches discovered during work on this plan.

_Auto-scaffolded by /start-work. Append new entries below - never overwrite._

---

## 2026-08-06 - Work session start
- Project: Laravel + AdminLTE (colorlibhq/adminlte-laravel), PHP 8.3.
- Existing IPAM: `App\Models\IpAddress`, `IpSubnet`, `IpAllocationHistory` under `app/Models/`; admin routes in `routes/admin/enterprise.php`.
- Existing hosting: `App\Models\HostingAccount`, `Server`, `ServerGroup`, `ServerGroupMember`; `App\Services\HostingService` handles status transitions.
- Existing product: `App\Models\Product` with `require_domain` already wired; `Product::TYPES` includes `vps`, `dedicated`.
- Testing: PHPUnit (composer test), Laravel Pint for formatting.
- Plan: 8 implementation todos + 4 final-verification tasks; Wave 1 = T1+T2+T3, with T3 depending on T1 service contract.

## 2026-08-06 - T2 AssetRelationship
- Asset relationship migrations use non-FK polymorphic endpoints with `unsignedBigInteger` IDs, explicit lookup indexes, and a composite unique constraint.
- MySQL requires a short explicit name for the five-column unique index; the generated Laravel name exceeds the 64-character identifier limit.
- Under the project `utf8mb4` settings, bounded `parent_kind`/`child_kind` lengths of 100 and `relationship_type` length of 50 keep the composite key within InnoDB's 3072-byte limit.
- `relationship_type` is restricted by the model `saving` guard to `hosted_on`, `hosted_in`, `manages`, and `contains`; no DB CHECK was added so the migration remains portable across the project's configured drivers.

## 2026-08-06 - T1 IpAssignmentService + Product::requiresIp()
- Laravel 13 compiles `enum` to `varchar check ("col" in (...))` on SQLite, so enum constraints ARE enforced in tests — verified empirically. The plan's morph marker `App\Models\HostingAccount` and history actions `assigned`/`override` were rejected by the existing CHECKs; migration `2026_08_06_000002_widen_ipam_assignment_enums` widens both enums additively (precedent: `2026_08_03_000002_widen_payments_method_enum`).
- MySQL parses backslash escapes in ENUM DDL literals (`\M` -> `M`), so the MySQL branch of the widening uses a raw `ALTER TABLE ... MODIFY` with doubled backslashes; the SQLite branch uses `->change()` (Laravel rebuilds the table, FK-safe). Side effect on SQLite only: rebuild drops the CHECK on the OTHER enum columns of that table (`ip_version`, `type`) — harmless, no test depends on them.
- `ip_allocation_history` has NO `created_at`/`updated_at` columns; the model shipped with default `$timestamps = true` and `changed_at` missing from Fillable, so ANY Eloquent insert failed. Fixed with `public $timestamps = false` + adding `changed_at` to the Fillable list; the service sets `changed_at => now()` explicitly.
- `ip_address_snapshot` was `varchar(45)` (fits only a bare IP string) but the spec mandates `json_encode` of the full pre-mutation row — widened to TEXT in the same migration (SQLite ignores varchar length, MySQL strict would have rejected the JSON).
- `IpAssignmentService` pattern: `DB::transaction` + `->lockForUpdate()` + `orderBy('id')` re-query; availability is exactly `assigned_to_type IS NULL`; scope filters: `subnet_id` direct, `datacenter_id` via `whereHas('subnet')` (datacenter_id lives directly on `ip_subnets`, no VLAN hop needed). `lockForUpdate()` is a no-op on SQLite but compiles cleanly.
- `release()` is a no-op when the account holds no lease (guard clause) so callers never need a pre-check; both assign methods write action `assigned`, release writes `released`; `override` enum value reserved for future take-over flows (assignSpecific rejects already-assigned rows instead).
- Test data: `hosting_accounts.customer_id`/`product_id` are NOT NULL but NOT foreign keys — plain integers suffice, no parent rows needed. `Product::requiresIp()` is exercised on unsaved `new Product(['type' => ...])` instances — no DB needed.
- `git` is not installed in this environment and the app directory is not a repository — plan-mandated commits cannot be created here.

## 2026-08-06 - T3 Hosting IP card (pull/choose/release)
- `HostingController` now takes `IpAssignmentService` as a second constructor arg alongside `HostingService`; `show()` passes `$assignedIp` (morph-pair lookup with `subnet:id,name` eager load) and `$availableIps` (free pool, `orderBy('id')`, capped at `AVAILABLE_IP_LIMIT = 100` so the dropdown can't blow up the page on large pools).
- Action methods follow the existing lifecycle pattern: validate → `try/catch NoAvailableIpException` → `back()->with('success'|'error')`. `assignNextAvailable` THROWS on empty pool (returns `?IpAddress` but never null) — catch the exception, never null-check.
- Routes live in `routes/admin/hosting.php` next to suspend/unsuspend/change-package, same `permission:hosting.manage` gate; names `admin.hosting.pull-ip|choose-ip|release-ip`.
- Blade: the IP card sits between the metric-cards row and the tabbed detail card on the show page only (task's normative sections scoped it to show; edit blade untouched). Pull/choose forms render only when NO lease exists, release form only when one does — the service does not guard against double-leasing and `release()` only frees the first lease, so the UI must not offer pull/choose on an already-leased account.
- Feature-test auth recipe (from AdminSearchTest): `Role::firstOrCreate(['name' => 'admin'])` + attach `Permission` rows + `$user->assignRole('admin')`. For the 403 gate test, give the user the `admin` ROLE with NO permissions attached — `AdminMiddleware` passes on the role, `PermissionMiddleware` aborts 403.
- `php artisan view:cache` is a cheap blade-syntax check (then `view:clear`); Pint's `single_line_empty_body` fixer collapses promoted-constructor bodies to `{}` on the closing line.

## 2026-08-06 - Integration test: ProductConfigOptionsIntegrationTest
- New file tests/Feature/ProductConfigOptionsIntegrationTest.php, 3 scenarios, all GREEN (`php artisan test --filter=ProductConfigOptionsIntegrationTest` -> 3 passed, 20 assertions). No production code touched.
- `IpAssignmentService::assignNextAvailable($account)` ignores `server_id`/ServerGroup/ServerGroupMember entirely — availability is simply `assigned_to_type IS NULL`. The Server->ServerGroup->ServerGroupMember->IpSubnet->IpAddress chain in Scenario A is integration setup, not a service dependency.
- CRITICAL schema gotcha: `server_groups` has `created_at` (useCurrent) but NO `updated_at`; `server_group_members` has NO timestamp columns at all. Both Eloquent models default `$timestamps = true`, so `ServerGroup::create()` / `ServerGroupMember::create()` throw 'Unknown column updated_at'. Workaround used in tests: `$model->timestamps = false` before `save()`. This also means the admin ServerGroupController::store path is likely broken in production (no covering tests).
- Task brief claimed hosting_accounts NOT NULL columns include `account_type` and `account_identifier` — those columns DO NOT exist. Actual NOT NULL: `customer_id`, `product_id`, `username` (no default). `username` is required; use it like existing tests do.
- `Product::requiresIp()` is the single source of truth (vps/dedicated => true); reuse it in tests rather than hardcoding the rule.
- Test auth recipe (identical to AssetRelationshipCrudTest/AdminSearchTest): `Role::firstOrCreate(['name'=>'admin'])` + Permission firstOrCreate + syncWithoutDetaching + `$user->assignRole('admin')` + actingAs.
- HostingService suspend/unsuspend on shared_hosting never touches IPAM (leaseIpForActivation early-returns when `!requiresIp()` and when status != pending). Full pending->active->suspended->active cycle keeps ip_addresses unassigned and ip_allocation_history empty.
- Tests run on SQLite `:memory:` (phpunit.xml); the enum-widen migration's non-MySQL branch handles it. Laravel asserts null DB values via assertDatabaseHas fine.

## 2026-08-06 - F3 blocker, git, and ServerGroup timestamps fix
- F3 (real manual QA) marked `[~]` in the plan: requires a seeded dev DB + browser/driver not available in this environment. All automated feature + unit coverage for that flow is GREEN instead.
- Git: the app dir HAS a `.git/` directory, but the `git` binary is NOT on PATH in this environment — plan-mandated per-todo commits could not be executed. The work itself is unaffected.
- RESOLVED the T6 gotcha: added `public $timestamps = false;` to `app/Models/ServerGroup.php` and `app/Models/ServerGroupMember.php` (their tables lack `updated_at`). `ServerGroupController::store()`/`update()` now work via standard `create()`. Updated `ProductConfigOptionsIntegrationTest::makeServerGroupMember()` to use plain `create()` instead of the per-instance `timestamps=false` hack — proves the fix. Full suite still 136/136 GREEN.
