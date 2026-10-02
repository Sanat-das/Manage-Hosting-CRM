# Plan: Product upgrade/downgrade engine — WHMCS parity

Date: 2026-09-30 · Repo: managehosting/app · Branch: (current)

## Goal

Turn the product-upgrade feature from path configuration into the full WHMCS-style
upgrade/downgrade lifecycle: client-requested, quote-previewed, prorated at WHMCS math,
invoiced on approval, applied on payment, credits to wallet on downgrade, auto-cancelled
on renewal, admin approval + manual upgrade. Config-options upgrades and promo codes are
documented as deferred (promotions engine does not exist in this app).

## Acceptance (whole)

`php artisan test` green, `vendor/bin/pint --dirty` clean, and a feature test proving the
full lifecycle: place → approve → invoice → pay → applied (price/product switched,
next_billing_date preserved), downgrade → credit in `customer_wallet`, stacking blocked,
renewal-cron cancellation of unpaid upgrade.

## WHMCS ground truth (docs.whmcs.com/9-0/products/upgrades-and-downgrades, UpgradeProduct API)

- credited   = old recurring ÷ days-in-cycle × days-until-next-due
- debited    = new price    ÷ days-in-cycle × days-until-next-due
- payable today = debited − credited (+ setup-fee difference when new setup fee is higher)
- net ≤ 0 → credit to client account balance (never auto-approved)
- Next Due Date does not change; free→paid sets it to +1 cycle from the upgrade date
- Changes apply when the new invoice is paid; unpaid upgrade orders are cancelled when the
  renewal cron generates the renewal invoice; one open upgrade per service.

## Contracts (frozen 2026-09-30)

### 1. Table `upgrade_requests` (migration `2026_09_30_000002_create_upgrade_requests_table`)
id, upgrade_no string unique (UPG-{YEAR}-{seq}, OrderNumberService 'UPG'),
order_id FK→orders, customer_id FK→customers, invoice_id FK→invoices nullable,
from_product_id FK→products, to_product_id FK→products,
upgrade_type string default 'product' (enum-ready for 'configoptions'),
status enum('pending','applied','cancelled') default 'pending',
billing_cycle string (snapshot of the order's cycle),
credited decimal(12,2), debited decimal(12,2), setup_fee decimal(12,2) default 0,
payable decimal(12,2) default 0, credit_amount decimal(12,2) default 0,
proration_days int default 0, period_days int default 0,
approved_at datetime nullable, applied_at datetime nullable, cancelled_at datetime nullable,
notes text nullable, timestamps. Indexes: order_id, customer_id, status.

Semantics: `pending` = awaiting approval (approved_at null) or awaiting payment (approved_at
set, invoice_id set). `applied` = changes live. `cancelled` = dead.

### 2. ProrationCalculator fix (app/Services/Billing/ProrationCalculator.php)
Upgrade branch becomes symmetric: charge = round(newAmount × remaining/total, 2) for BOTH
change types. Zero-period guard unchanged. `tests/Unit/ProrationCalculatorTest.php` updated
(test_upgrade_full_charge → test_upgrade_prorated_charge). No production callers today.

### 3. UpgradeQuoteService (new, app/Services/Billing/UpgradeQuoteService.php)
`quote(Order $order, Product $to, ?CarbonImmutable $changeDate = null): array` returns:
```php
[
  'prorated' => bool,          // AppSettings::get('product_prorated_charges', true)
  'period_days' => int,        // next_billing_date − (next_billing_date − cycle months); fallback last_billing_date, then order created_at
  'proration_days' => int,     // next_billing_date − change_date (0 when no next_billing_date → not prorated)
  'credited' => float,         // old unit_price × remaining/total (full old price when not prorated)
  'debited' => float,          // new cycle price × remaining/total (full when not prorated)
  'setup_fee_difference' => float, // max(0, new setup_fee − old setup_fee) — product change only, 0 when not prorated? NO: always charged when higher
  'total' => float,            // debited − credited + setup_fee_difference
  'payable' => float,          // max(0, total)
  'credit' => float,           // max(0, −total)
  'change_type' => 'upgrade'|'downgrade'|'equal', // by normalized monthly price (price ÷ CYCLE_MONTHS[cycle])
  'from' => ['product_id','product_name','unit_price','billing_cycle'],
  'to'   => ['product_id','product_name','price','billing_cycle','setup_fee'],
]
```
From-side unit price = the order's served OrderItem unit_price (the WHMCS "Recurring Amount").
To-side price = ProductPricing row for the order's billing_cycle; null → quote throws
DomainException "Target product has no pricing for the {cycle} cycle". Null next_billing_date
(free service) → not prorated, credited 0, debited full price. Timezone: Asia/Kolkata.

### 4. UpgradeRequestService (new, app/Services/Billing/UpgradeRequestService.php)
- `place(Order $order, Product $to, ?string $notes = null): UpgradeRequest`
  Guards, in order: settings `product_enable_upgrades`; downgrade → `product_enable_downgrades`;
  enabled path (ProductUpgradePath) from→to exists (and to product active); order status active
  (also allow pending/paid? NO — active only); target price exists for cycle; **no open request**
  (status pending, or applied with a newer pending) for the order → reject "Previous upgrade
  for this service is still pending or unpaid." (WHMCS error shape). Persists the quote snapshot.
- `approve(UpgradeRequest $r): void` — the approval + invoicing step.
  - payable > 0 → invoice via BillingService::createWithItems: customer_id, order_id = order id,
    amount payable, status sent, due_date now()+7d, notes "Upgrade {upgrade_no}: {from} → {to}",
    one line "Upgrade from {from} to {to} — prorated (N days)" unit_price = payable, product_id = to
    product id. Sets invoice_id, approved_at.
  - credit > 0 → customer_wallet row (type 'credit', balance_type 'credit', amount = credit,
    description "Downgrade credit — {upgrade_no} ({from} → {to})") and apply immediately
    (status applied, applied_at) — approval IS the credit grant (WHMCS: credits never auto-approved).
  - total == 0 → apply immediately, no invoice, no credit.
- `apply(UpgradeRequest $r): void` — switch the order's served item (non-addon item whose
  product_id == from_product_id): product_id, product_name, unit_price = to price for cycle,
  total = unit_price × quantity; config_options snapshot carried over unchanged; billing_cycle
  unchanged; next_billing_date preserved; free→paid sets next_billing_date = changeDate + cycle.
  OrderActivityLogger entry. Status applied + applied_at.
- `cancel(UpgradeRequest $r, ?string $reason = null): void` — status cancelled + cancelled_at + note.
- `cancelUnpaidForOrder(Order $order, ?DateTimeInterface $asOf = null): int` — cron hook: cancel
  every pending request on the order with invoice_id set whose invoice is not fully paid. Returns count.

Auto-approval rule (used by controllers): payable > 0 AND ! AppSettings::get('product_approval_required', false)
→ approve() at place time. Credit requests NEVER auto-approved.

### 5. Routes
- Client (append to routes/client.php; T4 owns that file):
  GET `client/hosting/{order}/upgrade` `client.hosting.upgrade`
  POST `client/hosting/{order}/upgrade` `client.hosting.upgrade.store`
  Order binding: scope to the authenticated customer (follow HostingController pattern).
- Admin (NEW file routes/admin/upgrade-requests.php, T5 owns):
  GET `admin/upgrade-requests` `admin.upgrade-requests.index` (permission product-upgrades.view)
  GET `admin/upgrade-requests/{upgradeRequest}` `admin.upgrade-requests.show` (view)
  POST `admin/upgrade-requests/{upgradeRequest}/approve` `admin.upgrade-requests.approve` (manage)
  POST `admin/upgrade-requests/{upgradeRequest}/cancel` `admin.upgrade-requests.cancel` (manage)
  GET `admin/orders/{order}/upgrade` `admin.orders.upgrade` (view) + POST `admin.orders.upgrade.store` (manage)
  Route file mirrors routes/admin/product-upgrades.php group (web+auth+admin+throttle:admin).
  ⚠️ bootstrap/app.php wiring of the new route file = orchestrator integration task.

### 6. Billing bridge + emails (T6)
- `app/Listeners/ApplyUpgradeOnInvoicePaid.php` — InvoicePaid → find pending request with
  invoice_id == invoice; if unpaid→ no (it just paid, isFullyPaid true); apply().
  Registered in EventServiceProvider under InvoicePaid (after AdvanceOrderOnPayment).
- `processRecurringBilling`: after createWithItems for an order's renewal → cancelUnpaidForOrder($order).
- Emails (EmailTemplateSeeder rows + UpgradeEmailService mirroring OrderEmailService):
  `upgrade_requested` (client, on place), `upgrade_applied`, `upgrade_cancelled`.
  Variables: customer base (BuildsEmailVariables) + upgrade_no, from_product_name, to_product_name,
  amount, credit_amount, next_due_date, order_number.

### 7. Permissions
Reuse `product-upgrades.view` / `product-upgrades.manage` (precedent O4: no new permission strings).
Client side: customer.record middleware scoping (existing).

## Task graph

- T1 migration + UpgradeRequest model + Order relation          (deps: —)
- T2 ProrationCalculator fix + UpgradeQuoteService + unit tests (deps: —)
- T3 UpgradeRequestService (place/approve/apply/cancel/cron)    (deps: T1, T2)
- T4 Client upgrade UI (routes, controller, views, hosting button) (deps: T3 API)
- T5 Admin upgrade UI (list/approve/cancel, manual upgrade)     (deps: T3 API)
- T6 InvoicePaid listener + cron hook + email templates/service (deps: T3 API)
- T7 Feature tests end-to-end                                   (deps: T3–T6)
- T8 Verify whole: full suite + pint + adversarial review       (deps: T7)

Wave 1: T1 ∥ T2. Wave 2: T3 ∥ T4 ∥ T5 ∥ T6 (contract-frozen). Wave 3: T7. Wave 4: T8.

## File ownership (one writer per file)

T1: migration (new), app/Models/UpgradeRequest.php (new), app/Models/Order.php (relation only)
T2: app/Services/Billing/ProrationCalculator.php, tests/Unit/ProrationCalculatorTest.php,
    app/Services/Billing/UpgradeQuoteService.php (new), tests/Unit/UpgradeQuoteServiceTest.php (new)
T3: app/Services/Billing/UpgradeRequestService.php (new), tests/Unit/UpgradeRequestServiceTest.php (new, unit-safe parts)
T4: routes/client.php, app/Http/Controllers/Client/UpgradeController.php (new),
    resources/views/client/upgrades/* (new), resources/views/client/hosting/show.blade.php (button)
T5: routes/admin/upgrade-requests.php (new), app/Http/Controllers/Admin/UpgradeRequestController.php (new),
    app/Http/Controllers/Admin/OrderController.php (manual-upgrade actions only),
    resources/views/admin/upgrade_requests/* (new), resources/views/admin/orders/show.blade.php (entry)
T6: app/Listeners/ApplyUpgradeOnInvoicePaid.php (new), app/Providers/EventServiceProvider.php,
    app/Services/Billing/BillingService.php (cron hook only), app/Services/UpgradeEmailService.php (new),
    database/seeders/EmailTemplateSeeder.php (3 rows)
INTEGRATOR (orchestrator): bootstrap/app.php wiring for routes/admin/upgrade-requests.php,
  final assembly, reconciliation.
T7: tests/Feature/UpgradeLifecycleTest.php (new)
T8: verify subagent

## Deferred (documented divergences)

- Config-options upgrades (type='configoptions'): engine ready via upgrade_type column; UI deferred.
- Promotion codes on upgrades: no promotions engine in this app; product promo_price is a sale price,
  not a coupon. Deferred.
- Triennial billing cycle: WHMCS has it; app vocabulary does not. Deferred.

## Post-verification extension (2026-10-01) — cycle-change upgrades SHIPPED

WHMCS `newproductbillingcycle` parity implemented:
- Migration `2026_10_01_000001_add_to_billing_cycle_to_upgrade_requests` (nullable string; applied to dev DB, batch 9).
- `UpgradeQuoteService::quote(..., ?string $toCycle = null)`: debit prorates against the NEW cycle
  length (`to_period_days`), credit against the old; `cycle_changed` flag; change_type uses the target
  cycle's months. Null toCycle = byte-identical to the pre-extension behavior.
- `place(..., ?string $toBillingCycle)` persists it; `apply()` switches item + order `billing_cycle` and
  re-anchors `item.last_billing_date` to `next_due − newCycleMonths` (FIX: without the anchor the
  once-per-cycle renewal guard compares the old cycle's last bill against the new cadence and skips the
  first new-cycle renewal — a year of revenue). Free→paid + cycle change sets due = +new cycle months.
- Client + admin manual forms enumerate (product, cycle) pairs per target, each with its own quote;
  `to_billing_cycle` validated nullable|in recurring cycles.
- Tests: +10 (quote proration cases, place/apply cycle switch, free→paid +12 mo, anchor + first-renewal
  money proof via processRecurringBilling, client/admin pair listing, E2E monthly→annual journey
  renewing at the annual price). Regression: 69 upgrade tests / 522 assertions green.

## Open questions

None blocking. Remaining boundary decisions: config-options upgrades and promotions require entities/UI
the app does not yet have (an option-diff pricing engine and a promotions administration surface
respectively); both are separate features to size as their own waves.

## Contract — config-options upgrades (type='configoptions') — SHIPPED 2026-10-01

WHMCS `type=configoptions` parity — implemented and verified (all slices green before the full-suite run):
- Migration `2026_10_01_000002_add_options_to_upgrade_requests` (nullable json `options`; dev DB batch 10).
- `quote(..., ?array $toSelections = null)`: to-side = ProductPricing.price + OptionPricingResolver::adjustment;
  null param = byte-identical legacy behavior. `option_adjustment` in the return shape.
- `place(..., ?array $toSelections = null)` persists `options`; upgrade_type 'configoptions' when selections
  set and product unchanged, else 'product'. approve() line suffix ' (configuration change)'.
- `apply()` with options: unit_price = base + adjustment, total × quantity, item config_options = full
  OrderConfigSnapshot::capture() of the TARGET product; cycle-change/anchor/free→paid logic unchanged.
- Client two-step flow: per-target cards with customer-editable option pickers (preselected from the served
  item's snapshot when the product is unchanged) → POST client.hosting.upgrade.preview → confirm view with
  the exact live quote + per-option old→new rows → POST store (same hidden payload; pending guard on
  double-confirm). ADMIN single-step per contract §4 note.
- ADMIN always passes toSelections `[]` (never null) so the target's FIXED options are priced and the
  snapshot is rewritten (fixes the earlier product-switch carry-over blemish); client passes null only when
  the target has no links.
## Product upgrade-paths tab — SHIPPED 2026-10-01

The product owner's model: manage each product's upgrade/downgrade lists ON the product.
- New "Upgrade Paths" tab on the product edit page (admin/products/{product}/edit) — a separate
  card below the main form (own add/remove forms; no nested-form issues, no JS dependency), wired
  into the existing tab navigation (5th tab, `edit-tab-upgrade-paths`).
- Two columns: **Upgrade products** (paths with direction ∈ {upgrade,both}) and **Downgrades
  products** (direction ∈ {downgrade,both}) — each lists its targets with a direction badge, an
  inline Remove (DELETE), and an Add form (target select of active products except self + direction
  select + Add). A product can have multiple products in each list.
- Routes: POST admin/products/{product}/upgrade-paths (store), DELETE …/upgrade-paths/{path}
  (destroy), permission products.edit. Controller: storeUpgradePath + destroyUpgradePath (scoped
  to the product) + edit() passes the paths + available targets.
- The standalone admin/product-upgrades page remains for cross-product management; the tab is the
  per-product view of the same data.
- Tests: ProductUpgradePathsTabTest (11) — tab renders two columns; add creates with direction and
  appears in the right column; remove deletes; invalid direction rejected; self-target rejected;
  main product form saves independently.
- Live browser: tab renders with both columns + add forms at desktop and mobile (screenshot
  product-upgrade-paths-tab-mobile-*.jpg); native <select> add/remove interaction is tool-blocked
  in the browser panel and covered by the feature tests instead (stated honestly).

## Direction dropdown removed from the tab — 2026-10-01
already declares the direction. Removed it: each column's add form now carries a hidden
`direction` (upgrade for the Upgrade column, downgrade for the Downgrades column), so adding to
a column IS adding to that list. `storeUpgradePath` validates direction `in:upgrade,downgrade`
('both' is set from the standalone product-upgrades page, which keeps the full select). New test
`test_add_rejects_both_from_the_tab`. 12 tab tests green; live browser re-verified (no dropdown,
target select + Add only).

## External state note — 2026-10-01

A migration not created by this feature, `2026_10_01_000004_add_change_type_to_upgrade_requests`
(nullable `change_type` string on upgrade_requests), appeared in the tree (product owner / another
session). It is valid + reversible; applied to the dev DB (batch 12). Nothing reads the column yet —
the quote computes change_type in PHP — but it is now part of the schema. suite-run-10's 2
deploy-safety failures were caused by it being pending; resolved by `migrate`. The 2
UpdateServiceBranchFallbackTest failures in the same run were flaky git-dependent tests (passed in
run-9 and on isolated re-run).

## Per-product upgrade AND downgrade lists — SHIPPED 2026-10-01

The product owner's model: a product can be upgraded only to products on its predefined upgrade
list, and downgraded only to products on its predefined downgrade list.
- `product_upgrade_paths.direction` enum('upgrade','downgrade','both') default 'both'
  (migration 2026_10_01_000003; dev DB batch 11). Admin CRUD (product-upgrades page) configures
  the direction per path; demo seeder paths carry explicit directions.
- Offer rule (client index + admin upgrade, identical): a pair is offered only if the path is
  enabled AND direction matches the pair's role — downgrade pairs need direction ∈ {downgrade,both}
  AND the global `product_enable_downgrades` master switch; upgrade/equal pairs need direction ∈
  {upgrade,both}. The per-product lists are the authority; the global toggle is a kill-switch.
- Enforcement at the chokepoint: `UpgradeRequestService::place()` re-checks direction (a hand-crafted
  POST cannot file an upgrade to a product off the lists) — "This product is not on the {upgrade|downgrade}
  list for {product}."
- Client wizard step 1 groups into "Upgrade to" / "Downgrades to" sections when both roles exist;
  step 3 shows a direction banner. Admin manual upgrade converted to the SAME three-step wizard
  (Plan → Configure with option pickers → Review & Confirm with required confirm checkbox →
  place+approve). Options available to client (step 2) and admin (step 2).
- Tests: UpgradePathDirectionTest (6) + place() direction enforcement (3) + client direction tests (5)
  + admin wizard tests (3 new, existing adapted). Upgrade regression: 98 → 101 tests green.
- Live browser verification: admin wizard all three steps + confirm enforcement; client step 1
  two-section grouping (Upgrade to / Downgrades to) with a scratch upgrade product; cleanup reverted.

## Wizard UX — SHIPPED 2026-10-01 (client upgrade flow → 3-step setup widget)

Request: "product upgrade should be like a multi steps setup widget, so user can see what is going on
step by step. at final step user must confirm all to complete the process." Implemented server-rendered
(zero new JS), stateless (selections travel in form payloads; no session cart):
- **Step 1 Plan** (client.hosting.upgrade): one form, one radio per (product × cycle) pair — value
  `"{product_id}:{cycle}"` parsed by configure() (avoids hidden-field collisions), per-pair quote cards,
  downgrade gate, empty state unchanged; submit → configure.
- **Step 2 Configure** (NEW POST client.hosting.upgrade.configure): plan-summary card + option pickers
  for the CHOSEN product only, preselected from the served snapshot (product unchanged) or reposted
  payload (Edit Configuration round-trip via preselectionFrom()); submits to the existing preview route.
- **Step 3 Review & Confirm** (client.hosting.upgrade.preview): full money-exact review (quote breakdown
  incl. option_adjustment + per-option old→new rows + proration) + "What you are confirming" checklist +
  REQUIRED confirmation checkbox — enforced server-side `'confirm' => ['required','accepted']`
  ("You must confirm the upgrade details to proceed."); store() unchanged otherwise; back links:
  Plan (safe GET) and Edit Configuration (full-payload POST back to configure).
- Shared `_steps` partial (Plan / Configure / Review & Confirm, completed/current/upcoming, aria-current).
- Admin flow untouched (single-step explicit action; no checkbox).
- Tests: ClientUpgradeFlowTest reworked to the wizard + 3 new (store without confirm rejected; configure
  renders pickers for only the chosen product; repopulation from returned payload); E2E client journeys
  now send confirm=1. Upgrade regression: 98 tests / 710 assertions green.
- Live browser verification (dev env): all three steps rendered, server-side rejection without the
  checkbox ("Please check this box if you want to proceed."), filed request UPG-2026-00002 verified in
  DB (pending, options persisted, ₹500 credit held for approval), cleanup reverted (0 paths, 0 requests,
  downgrades off). Screenshot: .openchamber/screenshots/upgrade-wizard-step3-confirmed-*.jpg.

## Quantity scaling — SHIPPED 2026-10-01 (money-correctness close-out)

WHMCS prorates the RECURRING amount (unit × quantity); the quote priced per unit, so a qty > 1 order
underpaid its upgrade delta by (qty − 1) × delta while renewals billed qty × unit price. Fix, at the
single convergence point after both proration branches in `UpgradeQuoteService::quote()`: credited and
debited (already rounded per unit) are scaled by the served item's quantity (`max(1, quantity)`);
setup-fee difference stays flat (charged once per order); per-unit rates (`from.unit_price`, `to.price`,
`option_adjustment`) remain unscaled for display; `quantity` added to the return shape (money fields are
now aggregate — documented in the docblock). All qty-1 behavior byte-identical (every existing test
untouched). New proofs: qty-2 quoted delta 133.32 vs per-unit 66.66; qty-2 invoice amount = aggregate
133.32 via approve(); setup diff not doubled. Upgrade regression: 95 tests / 694 assertions green.

### 1. Migration `2026_10_01_000002_add_options_to_upgrade_requests` (additive, reversible)
`options` json NULLable on upgrade_requests (the client's selection set keyed by target-product link id,
snapshotted at request time). Applied to dev DB after the wave.

### 2. UpgradeQuoteService::quote(Order $order, Product $to, ?CarbonImmutable $changeDate = null,
   ?string $toCycle = null, ?array $toSelections = null)
- `toSelections === null` → byte-identical behavior (all existing tests untouched).
- else `toUnitPrice = (float) ProductPricing(cycle).price + OptionPricingResolver::adjustment($to, $toSelections, $cycle)`
  and debited uses toUnitPrice in every branch (prorated / full / free). credited stays the item's
  unit_price (already includes the CURRENT options). to.setup_fee etc. unchanged. Return shape gains
  'option_adjustment' => float (0 when toSelections null). change_type monthly-normalized on toUnitPrice.

### 3. UpgradeRequestService
- `place(Order $order, Product $to, ?string $notes = null, ?string $toBillingCycle = null, ?array $toSelections = null)`
  → passes to quote(); persists `options` = $toSelections. upgrade_type = 'configoptions' when the
  request has options AND $to->id === order product id, else 'product' — or always 'product' when a
  product switch is involved; controller decides? NO — service decides: options set && product unchanged
  → 'configoptions', else 'product'.
- `apply()`: when `$request->options !== null`:
  - unit_price = pricing.price + resolver adjustment for ($request->to_product_id, $request->options, target cycle)
  - total = unit_price × quantity
  - item config_options = OrderConfigSnapshot::capture($to, null, $request->options, target cycle) (the FULL shape)
  - cycle-change/free→paid/anchor behaviors unchanged.
- Invoice line description gains " + options" suffix… keep minimal: append " (configuration change)" when
  upgrade_type configoptions.

### 4. Client flow (two-step, stateless)
GET client/hosting/{order}/upgrade: existing (product×cycle) target listing; each OFFERED target card
additionally renders the TARGET product's customer_editable option pickers (inputs name=options[{linkId}];
discrete = radio/select of link values, continuous = number input; pre-select from the served item's
snapshot when product unchanged: value_id(s)/selected per link id; fixed links are NOT rendered — resolver
prices them automatically). All targets share one form (fields per target: to_product_id + to_billing_cycle
+ options[...] namespaced per target is impossible in one form — SO: one form PER target card, each posting
the same route with its own target id) → POST `client.hosting.upgrade.preview` (NEW) → renders
confirm view with the EXACT live quote breakdown (product/cycle/old options → new options per-option
price unit + total option adjustment + prorated credited/debited/payable/credit + want-to-proceed note);
confirm form posts `client.hosting.upgrade.store` WITH THE SAME hidden payload (re-validated there;
double-confirm hits the pending guard → friendly error).
- store() gains the preview's payload shape; validates options per link type (discrete ids as integer[];
  continuous as numeric) — resolver tolerates labels.
- Admin manual form (OrderController::upgrade/storeUpgrade): same two-step on admin.orders.upgrade +
  admin.orders.upgrade.store (preview route not needed on admin — same GET can render confirm? No —
  admin gets the same preview POST → confirm view under admin.orders.upgrade.preview? Keep ADMIN SINGLE-
  STEP: the manual form renders pickers + live quote is base-only; POST places+approves with the options;
  the invoice line itemizes the adjusted amount; acceptable for staff (they see the invoice). Documented.)

### 5. Tests
Quote: option delta prorated (e.g. +200/mo option on 20/30 days → debited side +133.33 → payable delta;
toSelections null unchanged). Service: place persists options + upgrade_type configoptions; apply()
rewrites unit_price + config_options snapshot + total; product-switch + options (unit_price = new base +
options). Client: preview renders exact amounts; confirm places with pending/approval flow; validation
errors on bad option values. Admin: manual store with options. E2E: upgrade RAM option mid-cycle →
invoice = prorated delta → pay → applied: snapshot + unit_price updated, renewal bills new unit_price.
Quantity > 1 note: per-unit math (consistent with the existing product-proration engine; document).