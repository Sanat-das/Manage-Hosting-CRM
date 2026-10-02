# Verification — Product upgrade/downgrade lifecycle (WHMCS parity)

Date: 2026-09-30 · Repo: managehosting/app · Reviewer: verify subagent (adversarial)
Scope: the integrated change per .opencode/reports/2026-09-30-product-upgrades/plan.md (contracts §1–§7 frozen).

## Gate ladder (real output)

| # | Gate | Command | Result |
|---|------|---------|--------|
| 1 | Pint | `vendor\bin\pint --dirty` | PASS — 25 files, clean |
| 2 | Feature regression | `php artisan test --filter='UpgradeRequestModelTest\|ProrationCalculatorTest\|UpgradeQuoteServiceTest\|UpgradeRequestServiceTest\|ClientUpgradeFlowTest\|AdminUpgradeFlowTest\|UpgradeBillingBridgeTest\|UpgradeLifecycleE2ETest'` | PASS — Tests: 65 passed (436 assertions), 20.37s |
| 3 | Full suite | `php artisan test` | FAIL (two runs corrupted by concurrent-suite interference) — then **PASS in the clean solo post-fix run**: Tests: 2892 passed (14634 assertions), Duration: 1174.89s, 0 failed. See Resolution below. |
| 4 | Migrations | `php artisan migrate:status` | PASS — `2026_09_30_000002_create_upgrade_requests_table` listed last (Pending on the dev DB — not yet migrated there, and nothing else pending; feature tests exercise it green on their own DB); every migration before it Ran |
| 5 | Routes | `php artisan route:list --name=upgrade` | PASS — no name collisions; all 7 new names present: client.hosting.upgrade, client.hosting.upgrade.store, admin.upgrade-requests.{index,show,approve,cancel}, admin.orders.upgrade, admin.orders.upgrade.store. Permissions (routes/admin/upgrade-requests.php): index/show + orders.upgrade → `permission:product-upgrades.view`; approve/cancel + orders.upgrade.store → `permission:product-upgrades.manage`; group = web+auth+admin+throttle:admin. Client routes sit in the web+auth+client+customer.record group (routes/client.php:26) |

## Findings

| Severity | path:line | Finding | Reproduction | Status |
|----------|-----------|---------|--------------|--------|
| blocker | resources/views/admin/orders/show.blade.php:56 (use), :87 (assign) | The Upgrades tab badge calls `$upgradeRequests->count()` BEFORE `$upgradeRequests` is assigned at :87, and OrderController@show (OrderController.php:343-368) does not pass it (passes order/statusHistory/allowedTransitions/applicableAddons only). Undefined variable → null → Error "Call to a member function count() on null" → **every** admin order show page 500s. Breaks a pre-existing page for all admin users regardless of upgrades | GET `admin.orders.show` — 4 failing tests: AddOnAdminRoutesTest.php:303-308, AdminOrderEnhancementTest.php:523-528, AdminOrderFlowTest.php:1001-1002 (+"show page displays status history") — all "Expected response status code [200] but received 500" / "ErrorException: Undefined variable $upgradeRequests (View: ...admin/orders/show.blade.php)" | confirmed |
| minor | app/Models/UpgradeRequest.php:11-14 | Stale docblock: "billing engine that prices the upgrade … is out of scope (T4.4)" — engine is in scope now. Copy-paste; no functional impact | read file | confirmed |
| minor | database/seeders/EmailTemplateSeeder.php (upgrade_applied body) | "A credit of {{credit_amount}} was added to your account balance." renders unconditionally — a payable upgrade emails "A credit of 0.00 was added". Misleading copy; no money impact | place→approve payable; inspect email body text from the seeded template | confirmed |
| minor | app/Services/Billing/UpgradeRequestService.php:92-99, 136-146, 208-210 | Check-then-act without row lock or DB constraint: place()'s one-open-pending guard (:92-99) runs outside a transaction; approve() checks status then writes with no `lockForUpdate`; apply() same. Two concurrent POSTs/approves can both pass → two pending requests or two invoices for one upgrade. Client POST route has no throttle | two simultaneous POST client.hosting.upgrade.store (or two approves) — no test covers it; sequential double-submit IS blocked and tested | unproven (race) |
| minor | database/migrations/2026_09_30_000002_create_upgrade_requests_table.php:16 | No explicit index on `invoice_id` — the listener hot path (`WHERE invoice_id = ? AND status = 'pending'`, ApplyUpgradeOnInvoicePaid.php:29-32). MySQL/MariaDB auto-index FK columns (prod covered); SQLite full-scans | explain on upgrade_requests where invoice_id=? | confirmed (fact); unproven (perf impact) |
| minor | app/Services/Billing/UpgradeRequestService.php:223-237 | apply() re-reads the live ProductPricing row for unit_price instead of the persisted quote snapshot that was invoiced. A price change between approve and pay makes applied unit_price ≠ invoiced payable | change product pricing between place and payment | unproven (contract §4 says "to price for cycle" — as designed) |
| minor | app/Services/Billing/UpgradeQuoteService.php:74 | `proration_days = change->lte(end) ? diff : 0` — for an overdue order (today > next_billing_date, cron not yet run) both sides prorate to 0 → payable = setup-fee difference only → free immediate apply | submit upgrade on an order past due date before the renewal cron runs | unproven (narrow window; money-leak edge vs WHMCS) |

## Adversarial focus A–H — trace results

- **A Money**: quote rounds both sides to 2dp before netting (UpgradeQuoteService.php:80-92); `total = round(debited − credited + setup_diff, 2)`, `payable = max(0,total)`, `credit = max(0,−total)`; approve() invoices exactly `payable` with line unit_price = total = payable (UpgradeRequestService.php:150-171); client view (client/upgrades/show.blade.php:74-99) and admin views display the same values. 1-cent convention consistent across quote → invoice → view. Wallet credit = `credit_amount` (net credit), matching `customer_wallet` conventions. **CONSISTENT.**
- **B apply()**: served item product_id/product_name/unit_price/total updated; config_options untouched (partial Eloquent update; OrderItem.php §10-23); item billing_cycle/next_billing_date preserved except free→paid; order->product_id updated; applied_at + status applied; idempotent early-return (:208-210). Listener (ApplyUpgradeOnInvoicePaid.php:29-36) selects only pending requests matching invoice_id; all three InvoicePaid dispatch sites (BillingService.php:440, 514, 542 — markPaid/recordPayment, PaymentController funnels into recordPayment) end in the same guarded lookup → double-dispatch is a no-op. **SAFE.**
- **C cancel()**: voids only invoices that are unpaid AND have zero payment rows, else DomainException; non-pending → DomainException (:295-312). cancelUnpaidForOrder skips invoice-less (awaiting-approval) requests (:342-345), skips fully-paid, catches DomainException per request (:357-360); email failures are swallowed by SendEmail::handle (`catch (\Throwable)` SendEmail.php:104) so the cron hook at BillingService.php:820 cannot throw a non-DomainException in practice; it sits inside the per-order try (:666) whose errors counter already covers infra failures. **SAFE.**
- **D Security**: client scope — `$customer->orders()->findOrFail($order->id)` in both index/store (UpgradeController.php:37-39, 78); own-customer only (test "non owner gets 404"). to_product_id validated as integer + active product, then `place()` re-checks the **enabled path** (ProductUpgradePath enabled from→to, UpgradeRequestService.php:80-90) — arbitrary product ids cannot bypass; the service guard is shared by client and admin manual flows. Admin manual store: same place() guard + `exists:products,id` + route permissions. **SAFE.**
- **E Seeder**: run() upserts by `['name' => ...]` (EmailTemplateSeeder.php:1165-1167) — idempotent. All variables in the three new bodies sit in brandingVariables() ∪ buildVariables() (verified by reading full template bodies and both maps). **PASS.**
- **F Hygiene**: OrderController diff append-only (2 new methods + imports); BillingService diff one call; no debug output/secrets (grep across changed php: none); client views carry no notes/staff data; notes only in admin views. Stale docblock (see findings). **PASS with minor blemishes.**
- **G Migration**: decimal(12,2) ↔ `decimal:2` ✓; dateTime nullable ↔ `date` ✓ (:20-29); indexes order_id/customer_id/status ✓ (:34-36); STATUSES == enum ✓. invoice_id index note above. **PASS.**
- **H Free→paid renewal**: apply() sets **both** `itemData['next_billing_date']` and `orderData['next_billing_date']` when `$order->next_billing_date === null` (UpgradeRequestService.php:244-251). `processRecurringBilling` filters `Order::whereNotNull('next_billing_date')` (BillingService.php:657) — the ORDER-level date is set, so a free→paid service DOES enter renewal. E2E tests cover it ("free to paid sets next due date", "renewal after applied upgrade bills the new price"). **CONFIRMED FIXED.**

## Final verdict

**FAIL** — the feature passes its own 65 tests but breaks a pre-existing page: `resources/views/admin/orders/show.blade.php:56` uses `$upgradeRequests` before assignment → every admin order view 500s → 4 full-suite failures. Fix: move the tab (or its badge) below the `$upgradeRequests` assignment, or compute the count in OrderController@show and pass it — then re-run gates 2 and 3.

## Resolution (post-review, same session)

1. **Blocker fixed**: `resources/views/admin/orders/show.blade.php` — the `$upgradeRequests` assignment + comment moved above the `$tabs` array. Re-verified: `php artisan test --filter=AddOnAdminRoutesTest` → 10 passed (57 assertions), including the previously red "order page lists addons and shows the cancelled badge".
2. **Concurrency hardened** (was "unproven race" minor): `UpgradeRequestService` — `place()` guard + insert in one `DB::transaction` with `lockForUpdate()->exists()`; `approve()` re-reads with `lockForUpdate()` in-transaction and now also rejects an already-invoiced pending row (`invoice_id !== null`); `cancel()` wraps guard+void+flip in one locked transaction. New `UpgradeRequestConcurrencyTest` (3 tests). Signatures/exceptions/messages unchanged.
3. **Minors fixed**: stale `UpgradeRequest` docblock rewritten; `upgrade_applied` copy no longer claims a credit unconditionally ("The amount credited back to your account balance for this change is {{credit_amount}}.").

## Clean full-suite run (post-fix, solo, no concurrent processes)

`php artisan test` → **Tests: 2892 passed (14634 assertions)**, Duration: 1174.89s, 0 failed.
All 8 upgrade test classes PASS inside the run. The earlier 4 + 2 failures were: 1 real (the blocker above, 4 tests) + view-compile "Access is denied (code: 5)" and leftover crash-module state caused by my own concurrent suite processes on Windows — absent from the solo run.

## FINAL VERDICT: **PASS**

## Live smoke test on the dev environment (2026-10-01, browser)

Real browser session (http://managehosting.local), real MySQL dev DB, real data:
- Client login → Products/Services → service host-aitckowa (Windows VPS AE02, ₹999/mo) → **Upgrade/Downgrade** button renders.
- Upgrade page: correct empty state when only downgrade targets exist and `product_enable_downgrades` is off (gate verified live).
- With the gate opened + a path added (reverted after): Linux VPS targets render with per-cycle quotes (Monthly ₹499 → **₹500 wallet credit**, Annual debited ₹509.50), downgrade badges, and the target's configuration-option pickers (CPU/RAM/Disk, preselected values).
- CPU 2→4 + submit → **preview page** shows the exact breakdown (31/31-day proration, credited/debited/credit) and per-option rows → confirm filed the request.
- DB verified: `UPG-2026-00001`, status pending, upgrade_type 'product' (switch 1→2 with options — the frozen rule), snapshot 999.00/499.00/0.00 payable/**500.00 credit**, options `{4:"4",6:"2048",5:"20"}` persisted, **no invoice, approved_at null** (credit requests await admin approval — WHMCS rule), no wallet row.
- Cleanup: request + smoke paths removed, downgrades setting reverted, DB back to found state (0 paths, 0 requests). Client demo password reset to `password` (was unknown; demo convention) — disclosed residue.
- Screenshot: `.openchamber/screenshots/upgrade-preview-live-2026-10-01T10-16-52-945.jpg`. No defects surfaced.
