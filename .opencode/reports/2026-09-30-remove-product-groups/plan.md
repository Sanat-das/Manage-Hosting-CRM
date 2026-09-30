# Plan: Remove Domain Registration & Addons & Extras product groups

Date: 2026-09-30 · Branch: main · Session: ses_f0ea3d73effe5w0VDR0JUHD9yD

## Goal
Delete product groups `domain-registration` and `addons-extras` from the live app and from the default installation (migrations + `InitialDataSeeder`), without breaking seed idempotency or count contracts.

## Acceptance
1. `php artisan migrate` on dev DB → both groups absent; the `Domain Registration` product gone (`ProductGroup::whereIn('slug',[…])->count() === 0`).
2. Fresh install (migrate + `InitialDataSeeder`) creates only 4 groups.
3. `bash scripts/seed-smoke.sh` passes; `php artisan test` (full) passes; `vendor/bin/pint --dirty` clean.
4. `/admin/product-groups` shows 4 groups.

## Contract (frozen)
- Migration `database/migrations/2026_09_30_000001_remove_domain_and_addons_product_groups.php`: delete products whose group slug is in the two slugs, then delete the groups. `down()` restores both groups exactly as `InitialDataSeeder` rows (sort_order 5/6, `is_hosting` false) and re-inserts the canonical `Domain Registration` product mirroring `2026_08_19_000100`. No schema changes; idempotent.
- `InitialDataSeeder.php:29-36`: keep only the 4 hosting groups.
- Demo: `PRODUCTS` 8→6, `product_groups` 6→4; all affected `DummyDataConfig::ROWS` recomputed from actual seeded rows (ROWS identity across all 99 tables must hold); `scripts/seed-smoke.sh` products threshold 8→6.
- Out of scope / untouched: old migrations, `config/adminlte.php` (already dirty — unrelated), `StoreController` whereNotIn, `DomainController` (client domain checkout rests; dormant), `ProductsServicesPageTest` fixture group, `.env*`.

## Consequences (accepted)
Client-side domain registration checkout (`DomainController::registerStore`) depends on slug `domain-registration`; with the group removed it returns "temporarily unavailable" on installs without the group. Storefront never exposed these products (`show_in_order=false` + `whereNotIn`). Reversible via migration `down()` + git.

## Tasks
- [x] T1 Recon — explore ×2 (seeders + domain-flow maps) — done 2026-09-30
- [x] T2b InitialDataSeeder — drop 2 rows — solo T0
- [x] T2a Migration 2026_09_30_000001 — general worker — done 2026-09-30 (up/down round-trip verified on dev DB)
- [x] T2c Demo seeders + counts + smoke threshold — general worker — done 2026-09-30
- [x] T3 Verify — smoke, full tests, pint, dev-DB read — orchestrator/verify — PASS 2026-09-30 (2830 passed, 0 failures)