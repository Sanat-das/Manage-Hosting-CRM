# Verification: Remove Domain Registration & Addons & Extras product groups

Date: 2026-09-30 · Branch: main

## Result: PASS

## Gates
- `vendor/bin/pint --dirty` → PASS, 11 files, clean.
- `php artisan test --filter=DummyDataSeederTest` → 6 passed (182 assertions).
- `php artisan test --filter=SeederIntegrityTest` → 9 passed (38 assertions).
- `php artisan test` (full) → 2830 passed (14205 assertions), 0 failures.
- `bash scripts/seed-smoke.sh` → ALL PASS (products ≥6 got 7, orders ≥10 got 10, perms 106/106, idempotency unchanged; scratch sqlite only).
- Dev DB read: `product_groups` slugs = exactly [shared-hosting, reseller-hosting, vps-hosting, dedicated-servers].
- `database/seeders` grep for `domain-registration|addons-extras|Demo SSL & Backup|Demo .com Domain` → zero hits outside the new migration's down().
- `2026_08_19_000100_add_domain_registration_product.php` → unmodified (not in `git diff --name-only`).
- Migration up/down round-trip executed on dev DB: migrate removed groups (tinker [0,0]); rollback restored 2 groups + canonical product; re-migrate removed them again. End state: groups absent.
- No scratch files remain (no `storage/*.sqlite`, no `storage/derive_counts.php`).

## Accepted consequences
- Client domain checkout (`DomainController::registerStore`) now returns "temporarily unavailable" where the group is absent — dormant flow, no crash.
- Migration `up()` deletes every product in both groups (including any custom live products); `down()` restores only the canonical Domain Registration product. Snapshot `products` + pivots before deploying to a populated DB. `order_items` keep name snapshots (nullable product_id, no FK) so history is preserved.
- `products` seeds 7 rows vs `PRODUCTS`=6 (pre-existing legacy pack) — `>=` contract holds; smoke reports 7.

## Notes
- `config/adminlte.php` dirt is pre-existing/unrelated — untouched.
- During verification a concurrent session modified `app/Support/Branding.php` + `public/web.config` (email-template work) — outside this change, no overlap with product-group surface.
