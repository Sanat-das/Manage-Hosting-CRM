# Plan — WHMCS-style add-on billing, Indian-GST compliant

Repo: `C:\Users\Administrator\Local Sites\managehosting\app` (ManageHostingCRM, Laravel ^13.8, PHP ^8.3, Blade + AdminLTE 4.1 + BS 5.3, PHPUnit 12).
Stage-3 plan. Read-only phase. No app code was modified.
**Write-path note:** this planner's write permission is confined to `C:\Users\Administrator\.opencode\plan`. The requested in-repo paths `.opencode/reports/2026-09-28-addon-billing/{plan,ledger}.md` were **not** written — copy with:
`New-Item -ItemType Directory -Force .opencode/reports/2026-09-28-addon-billing; Copy-Item "$HOME\.opencode\plan\2026-09-28-addon-billing\*.md" .opencode/reports/2026-09-28-addon-billing\`

---

## 1. Goal & business rules as they will be implemented

Sell and manage WHMCS-style add-ons against a service, billed on the add-on's own cycle, with Indian GST and per-financial-year invoice numbering.

WHMCS semantics mapped 1:1 onto this app:

| WHMCS concept | This app | Evidence |
| --- | --- | --- |
| Add-on definition (product-scoped or global) | `product_addons` row — already exists, admin CRUD live | `app/Models/ProductAddon.php:12`, `app/Http/Controllers/Admin/AddonController.php:21`, migration `2026_07_30_120010_create_product_tables.php:89` |
| Add-on service (a live add-on attached to a service) | **`order_items` row** with `parent_item_id` + `product_addon_id` | see §3 anchor justification |
| Parent service | the parent's `order_items` row (`parent_item_id`) | `order_items` is the per-service billing unit: `BillingService::processRecurringBilling` iterates `$order->items` (`app/Services/Billing/BillingService.php:590`), `OrderService::transition` seeds one schedule per item (`app/Services/OrderService.php:122-134`) |
| Add-on is its own billable line, own cycle/price | its own `order_items` row with its own `billing_cycle`/`unit_price`/`next_billing_date` | `BillingService::createInvoiceForOrder` emits one invoice line per order item (`:312-325`) |
| Setup fee = one-time charge, never renews | a sibling `order_items` row with `billing_cycle='one_time'` (`CYCLE_MONTHS['one_time'] = 0`) | `app/Models/Order.php:61-68`; renewal skips `cycleMonths <= 0` (`BillingService.php:594-597`) |
| Order-time selection | add-on picker on storefront product page + admin order line editor + API `lines[].addons[]` | `app/Http/Controllers/Client/StoreController.php:78`, `app/Http/Requests/OrderRequest.php:93` |
| Post-signup admin add, **no proration**, own next due = today + cycle | `AddOnService::attach()` | new, §3 |
| Renewal on its own cycle through the existing engine | free — `processRecurringBilling` already per-item | `BillingService.php:566-702` |
| Cancel add-on at period end, audit-logged | `AddOnService::cancel()` sets `next_billing_date = null` + `activity_log` row | new |
| Parent suspension/termination cascades | free for order-level status; explicit for auto-termination | `BillingService.php:575-578` (status gate) + §3 cascade helper |

GST rules:
- Place of supply is the customer's state code on **every** invoice. Manual invoices currently pass none → IGST default. Fix in S1.
- Invoice numbering: consecutive, unique, **per financial year (Apr–Mar)**, ≤16 chars, race-safe. Format `INV-{FY}-{seq5}`, FY label `YYZZ` of the FY containing the invoice date (2026-09-28 → `2627` → `INV-2627-00001`, 14 chars).
- Invoice documents show place of supply and the customer's GSTIN (`customers.tax_id`). GSTIN is already rendered in the PDF (`resources/views/admin/invoices/pdf.blade.php:46-47`); the admin show page does not render it.
- HSN/SAC → SA,S3 (no product field exists today).

---

## 2. Acceptance criteria (whole feature)

Run from the repo root, PowerShell.

```powershell
php artisan test --filter AddOnOrderItemSchemaTest      # additive migration + relations
php artisan test --filter AddOnOrderTimeTest           # add-on becomes its own line at order time
php artisan test --filter AddOnSetupFeeTest            # setup fee billed once, counted 1, never renewed
php artisan test --filter AddOnRecurringBillingTest    # add-on renews on its OWN cycle
php artisan test --filter AddOnAttachTest              # live attach invoices now, no proration
php artisan test --filter AddOnCancelTest              # cancel stops renewal + audit row
php artisan test --filter AddOnCascadeTest             # parent termination ends add-on schedules
php artisan test --filter InvoiceNumberFormatTest      # FY-scoped, consecutive, <=16 chars, race-safe
php artisan test --filter ManualInvoicePlaceOfSupplyTest  # manual invoice no longer IGST-by-default
php artisan test                    # full suite, no new failures
vendor/bin/pint --test              # format clean
bash scripts/seed-smoke.sh          # migrations/seeders changed (S1)
php artisan test --filter SeederIntegrityTest   # only if a permission string is added — plan adds none
```

Observable outcomes:
- `invoice_no` matches `/^INV-\d{4}-\d{5}$/` and its 4 digits are the FY label of the invoice's own `created_at`; two invoices created in the same FY are consecutive; the 16-char cap holds.
- One order with one product + two selected add-ons (one with a setup fee) produces 4 `order_items`: parent, add-on A, add-on B, one `one_time` setup line. Only the setup line has `next_billing_date = null` and `billing_cycles_count = 0`.
- `php artisan billing:recurring` run twice with the add-on due produces exactly one renewal invoice whose amount equals the add-on price × qty (no setup fee).
- Attaching to an active order produces one invoice immediately whose amount = add-on price × qty (+ setup fee), with the customer's place of supply applied; a second call for the same add-on on the same parent is rejected.
- Cancel sets `next_billing_date = null`; the next `billing:recurring` run invoices nothing for that line; an `activity_log` row with `action = 'addon.cancelled'` exists.

---

## 3. FROZEN CONTRACTS

### 3.0 Anchor decision (justified, not assumed)

**Add-on service = `order_items` row.** Evidence:
- The subscription layer is **dormant**: `subscription_periods` / `subscription_changes` / `usage_records` are written only by `database/seeders/Demo/ServiceSeeder.php:521,577`; no controller, service, job or command writes them. `subscription_changes.change_type = 'addon'` has **no creation flow**.
- `service_instances` is **live but is the provisioning record**, not a billing record: written by `HostingService`/provisioning dispatchers, consumed by `ProvisioningModule` implementations and `RunVmOperation` (`app/Contracts/Integrations/ProvisioningModule.php:22`). No billing code reads it.
- All renewals already run off `order_items` (`BillingService.php:590-641`), one schedule per item, seeded at activation (`OrderService.php:122-134`).
→ Anchoring on `order_items` gives renewal, per-item cancel and order-level cascade **for free** and creates no second billing engine. Rejected: mirroring add-ons into `subscription_periods` (no consumer, duplicate engine).

### 3.1 Migration A — `order_items` (S1)

`database/migrations/2026_09_28_000100_add_addon_columns_to_order_items.php` — additive, reversible, one table, one writer.

```php
public function up(): void
{
    Schema::table('order_items', function (Blueprint $table) {
        $table->foreignId('parent_item_id')->nullable()->after('order_id')
            ->constrained('order_items')->nullOnDelete();
        $table->foreignId('product_addon_id')->nullable()->after('parent_item_id')
            ->constrained('product_addons')->nullOnDelete();
    });

    Schema::table('order_items', function (Blueprint $table) {
        $table->index(['order_id', 'parent_item_id']);
        $table->index('product_addon_id');
    });
}
```
`down()`: guard with `Schema::hasColumn`, drop the two indexes, then `dropConstrainedForeignId('product_addon_id')` and `dropConstrainedForeignId('parent_item_id')`.

Notes: `nullOnDelete` (not cascade) is deliberate — an add-on line keeps its price/name when the definition or the parent row is hard-deleted, and self-referencing FKs with `SET NULL` are safe under MySQL multi-row deletes. No `is_addon` boolean: derivable as `product_addon_id !== null` (fewer moving parts).

### 3.2 Migration B — `invoices` (S2)

`database/migrations/2026_09_28_000200_add_place_of_supply_to_invoices.php`

```php
$table->string('place_of_supply_code', 2)->nullable()->after('igst_amount');
```
`down()`: drop the column, guarded. Justification: a tax invoice must carry place of supply; deriving it at render time from the customer's *current* `state_code` would change a historical document (snapshot violation). The column is written from the `$customerStateCode` argument `createWithItems`/`updateWithItems` **already receive** (`BillingService.php:60,122`).

### 3.3 Eloquent — frozen additions

`app/Models/OrderItem.php`
```php
#[Fillable([... , 'parent_item_id', 'product_addon_id'])]      // existing list + 2
protected $casts = [ ... , 'parent_item_id' => 'integer', 'product_addon_id' => 'integer'];

public function parentItem(): BelongsTo   // OrderItem, 'parent_item_id'
public function addon(): BelongsTo        // ProductAddon, 'product_addon_id'
public function childAddons(): HasMany    // OrderItem, 'parent_item_id'
public function isAddon(): bool           // $this->product_addon_id !== null
```

`app/Models/ProductAddon.php`
```php
public function orderItems(): HasMany     // OrderItem, 'product_addon_id'
public function scopeActive(Builder $q): Builder                       // where status = 'active'
public function scopeApplicableTo(Builder $q, int $productId): Builder // active AND (product_id IS NULL OR product_id = $productId)
```

`app/Models/Invoice.php` — `#[Fillable(... 'place_of_supply_code' ...)]` (S2).

### 3.4 Array / payload shapes crossing boundaries

**Session cart entry (client storefront + admin cart)** — additive key, always present:
```php
'addons' => [ ['addon_id' => int, 'quantity' => int], ... ]   // default []
```
Merge identity is extended: two entries merge only when `product_id`, `billing_cycle`, `domain`, `options` **and** `addons` are equal (extend `StoreController.php:146-149`; admin cart equivalent).

**Admin order form / API `lines[]`** — additive keys, `OrderRequest`:
```php
'lines.*.addons'            => ['sometimes','array','max:20'],
'lines.*.addons.*.addon_id' => ['required','integer','exists:product_addons,id'],
'lines.*.addons.*.quantity' => ['required','integer','min:1','max:'.Order::MAX_QUANTITY],
```
plus a `withValidator` check: each `addon_id` must be `status = 'active'` and `product_id` null or equal to the line's `product_id`. Absent key → byte-identical behaviour (API additive only).

**Order-time payload consumed by the service** — frozen:
```php
/** @var array<int, array{addon_id:int, quantity:int}> $selections */
AddOnService::materialize(Order $order, OrderItem $parentItem, array $selections): array
```

**Invoice line payload** — **unchanged**. `createWithItems` already accepts `description|quantity|unit_price|total|product_id|config_options` (`BillingService.php:83-103`). Add-on lines reuse it verbatim; no new keys.

**Add-on `order_items` rows, frozen field values:**
- recurring line: `product_id = parentItem->product_id`, `product_name = $addon->name`, `product_addon_id`, `parent_item_id`, `billing_cycle = $addon->billing_cycle`, `quantity`, `unit_price = $addon->price`, `total = price × qty`, `recurring_cycles_limit = 0`, `next_billing_date` NULL at creation (seeded at activation by `OrderService`), `billing_cycles_count = 1`.
- setup line (created only when `setup_fee > 0`): same links, `product_name = "{$addon->name} — Setup Fee"`, `billing_cycle = 'one_time'`, `quantity = 1`, `unit_price = total = $addon->setup_fee`, `recurring_cycles_limit = 0`, `next_billing_date` NULL, `billing_cycles_count = 0`.

**Why `product_id = parentItem->product_id` (decisive):** `GstTaxService::calculateItemTax` resolves the product's GST treatment from `$item['product_id']` (`:137-146`) and under `tax_mode = 'per_product'` returns **no tax at all** when the product is absent (`$applyGst` stays false, `:154-159, 179-181`). A null `product_id` would therefore bill add-ons tax-free under `per_product` mode. Inheriting the parent product's GST treatment is also the tax-correct reading (the add-on is part of the parent supply; SAC 998315). Trade-off: `Product::invoiceItems()` attribution now includes add-on revenue — acceptable, note in §6.

### 3.5 Service signatures — frozen

`app/Services/Billing/AddOnService.php` (**new**, one writer)
```php
final class AddOnService
{
    public function __construct(private readonly BillingService $billing) {}

    /** Active add-ons orderable with this product (product-scoped + global). */
    public function applicableFor(Product $product): Collection;

    /** Add-on purchase rows hanging off one parent service line. */
    public function forParent(OrderItem $parentItem): Collection;

    /** Order-time expansion. @return array<int, OrderItem> recurring + setup lines */
    public function materialize(Order $order, OrderItem $parentItem, array $selections): array;

    /** Post-signup attach to an ACTIVE order; bills the current period immediately, no proration. */
    public function attach(Order $order, OrderItem $parentItem, ProductAddon $addon, int $quantity = 1, ?User $actor = null): OrderItem;

    /** Stop renewing at period end. Audit-logged. No refund, no void. */
    public function cancel(OrderItem $addonItem, ?string $reason = null, ?User $actor = null): void;
}
```
`attach()` guards (throw `InvalidArgumentException`): `$parentItem->order_id === $order->id`; `$parentItem->product_addon_id === null`; `$addon->status === 'active'`; `$addon->product_id === null || (int) $addon->product_id === (int) $parentItem->product_id`; `in_array($order->status, [Order::STATUS_ACTIVE, Order::STATUS_SUSPENDED], true)`; not already attached (`forParent()` does not contain `$addon->id`).
`attach()` effects: create the recurring line with `parent_item_id`/`product_addon_id` and `next_billing_date = today('Asia/Kolkata')->addMonths(CYCLE_MONTHS[$cycle] ?? 0)` (NULL for `one_time`); create the setup line when `setup_fee > 0`; `$invoice = $this->billing->createAddonChargeInvoice($recurring, $setup, $notes)`; `$this->billing->syncOrderSummary($order)`.

`app/Services/Billing/BillingService.php`
```php
public function createAddonChargeInvoice(OrderItem $addonItem, ?OrderItem $setupFeeItem = null, ?string $notes = null): Invoice;
public function resolvePlaceOfSupply(int $customerId): string;  // normalized customer code, else the company state code
public function syncOrderSummary(Order $order, ?string $lastBillingDate = null): void;  // visibility private -> public, body unchanged
public function generateNumber(): string;                       // body now delegates; signature unchanged
private function linePayload(OrderItem $item, string $fallbackCycle): array;  // extracted from createInvoiceForOrder
private function endChildAddonSchedules(OrderItem $parent): void;             // S2 cascade
```
`createAddonChargeInvoice` uses `linePayload()` (same description format as `createInvoiceForOrder`), `status = Invoice::STATUS_SENT`, `due_date = today + 7 days`, `order_id = $addonItem->order_id`, and `resolvePlaceOfSupply((int) $addonItem->order->customer_id)`.
`resolvePlaceOfSupply` is also refactored into the renewal path so `BillingService.php:669` reads `$this->resolvePlaceOfSupply((int) $order->customer_id)` — same behaviour, one source of truth.

`app/Services/OrderNumberService.php` (one writer)
```php
public function next(string $prefix = 'ORD'): string;   // UNCHANGED — do not repurpose
public function nextForFinancialYear(string $prefix, DateTimeInterface $date, int $pad = 5): string; // INV-2627-00001
public static function financialYearLabel(DateTimeInterface $date): string;                          // '2627'
```
Implementation reuses the existing `sequences` row-lock transaction (`OrderNumberService.php:27-49`). Sequence key for invoices: `INV-{FY}` (e.g. `INV-2627`), independent of the vestigial seeded `order_no` row and of the `INV` key `next()` uses. `financialYearLabel`: month ≥ 4 → `substr(Y,2).substr(Y+1,2)`, else `substr(Y-1,2).substr(Y,2)`.

### 3.6 Routes & permissions — frozen

S1 adds **no** routes and **no** permission strings.
S2, inside the existing group in `routes/admin/orders.php`:
```php
Route::post('orders/{order}/addons', [OrderAddonController::class, 'store'])
    ->middleware('permission:orders.edit')->name('orders.addons.store');
Route::delete('orders/{order}/addons/{orderItem}', [OrderAddonController::class, 'destroy'])
    ->middleware('permission:orders.edit')->name('orders.addons.destroy');
```
**Permission decision: reuse `orders.edit`** (declared `database/seeders/AdminLteRbacSeeder.php:48`, granted to `admin` via `'permissions' => $all`, `:192`). Attaching/cancelling is an order-edit operation; a new string would force `AdminLteRbacSeeder` churn plus the `SeederIntegrityTest` inventory/label/route gates (`tests/Feature/SeederIntegrityTest.php:354-413`) for no security gain. Cost of being wrong: a role that may edit orders but should not touch add-ons gains that ability — mitigation is one line in the seeder + role sync if the owner objects.

Client-side surface: no new client route in S1/S2 (client self-service add-ons → S3).

### 3.7 API — additive only

`app/Http/Controllers/Api/OrderController.php:77` already type-hints `OrderRequest`, so the optional `lines[].addons[]` key arrives with zero API-specific code. No endpoint signature, response field or status code changes. `POST /api/orders/{order}/addons` → S3.

---

## 4. Staged task graph

Ownership rule: one writer per file. No two tasks in a stage touch the same path. Migrations on the same table never run in parallel.

### S1 — "Add-ons are sellable at order time; invoice numbering is India-correct" (independently shippable)

| ID | Deliverable | owns (exclusive) | deps | acceptance | files |
| --- | --- | --- | --- | --- | --- |
| **T1.1** | Additive `order_items` add-on columns + model relations | `database/migrations/2026_09_28_000100_*.php`, `app/Models/OrderItem.php`, `app/Models/ProductAddon.php`, `tests/Feature/AddOnOrderItemSchemaTest.php` | — | `php artisan test --filter AddOnOrderItemSchemaTest` | 4 |
| **T1.2** | `AddOnService` (applicableFor / forParent / materialize) | `app/Services/Billing/AddOnService.php`, `tests/Unit/AddOnServiceTest.php` | T1.1 | `php artisan test --filter AddOnServiceTest` | 2 |
| **T1.3** | FY invoice numbering + place-of-supply fix on manual invoices | `app/Services/OrderNumberService.php`, `app/Services/Billing/BillingService.php`, `app/Http/Controllers/Admin/InvoiceController.php`, `tests/Unit/InvoiceNumberFormatTest.php`, `tests/Feature/ManualInvoicePlaceOfSupplyTest.php` | — | `php artisan test --filter "InvoiceNumberFormatTest\|ManualInvoicePlaceOfSupplyTest\|OrderNumberServiceTest\|BillingServiceTest"` | 5 |
| **T1.4** | Order-time entry points carry add-on selections | `app/Http/Controllers/Client/StoreController.php`, `app/Http/Controllers/Admin/CartController.php`, `app/Http/Controllers/Admin/OrderController.php`, `app/Http/Requests/OrderRequest.php`, `app/Http/Controllers/Api/OrderController.php` | T1.2 | `php artisan test --filter AddOnOrderTimeTest` | 5 |
| **T1.5** | Add-on pickers in the storefront product page and admin order line editor | `resources/views/client/store/product.blade.php`, `resources/views/admin/orders/create.blade.php` | T1.4 (field names frozen in §3.4) | `npm run build` (only if a Vite entry changes; Blade-only otherwise) + manual: add a product with an add-on, confirm the picker renders posted `lines[0][addons][0][addon_id]` | 2 |
| **T1.6** | Stage-1 verification | read-only | T1.1–T1.5 | `php artisan test && vendor/bin/pint --test && bash scripts/seed-smoke.sh` | 0 |

S1 total: **18 files** (14 app/migration/view + 4 test), 5 producer tasks.

### S2 — "Admin manages a live add-on; GST documents" (depends on S1)

| ID | Deliverable | owns (exclusive) | deps | acceptance | files |
| --- | --- | --- | --- | --- | --- |
| **T2.1** | `invoices.place_of_supply_code` migration + fillable + write in create/update | `database/migrations/2026_09_28_000200_*.php`, `app/Models/Invoice.php` | T1.3 | `php artisan test --filter AddOnInvoiceSchemaTest` | 2 |
| **T2.2** | `add()`/`cancel()` on `AddOnService` + `createAddonChargeInvoice` + `syncOrderSummary` visibility | `app/Services/Billing/AddOnService.php`, `app/Services/Billing/BillingService.php` | T2.1, T1.2 | `php artisan test --filter "AddOnAttachTest\|AddOnCancelTest\|AddOnSetupFeeTest"` | 2 |
| **T2.3** | Admin route + controller for attach/cancel | `routes/admin/orders.php`, `app/Http/Controllers/Admin/OrderAddonController.php` | T2.2 | `php artisan test --filter AddOnAdminRoutesTest` (+ `php artisan test --filter SeederIntegrityTest`) | 2 |
| **T2.4** | Cancel button + add-on rows on the order page | `resources/views/admin/orders/show.blade.php` | T2.3 | `php artisan test --filter AddOnAdminRoutesTest`; manual: cancel an add-on, row shows cancelled | 1 |
| **T2.5** | Place of supply + GSTIN on invoice documents | `resources/views/admin/invoices/show.blade.php`, `resources/views/admin/invoices/pdf.blade.php` | T2.1 | `php artisan test --filter InvoiceDocumentGstTest` | 2 |
| **T2.6** | Parent auto-termination ends child add-on schedules | `app/Services/Billing/BillingService.php` | T2.2 | `php artisan test --filter AddOnCascadeTest` | 1 |
| **T2.7** | Stage-2 verification | read-only | T2.1–T2.6 | `php artisan test && vendor/bin/pint --test && bash scripts/seed-smoke.sh` | 0 |

S2 total: **10 files**, 6 producer tasks. `BillingService.php` is a single writer inside each stage; S2 serialises T2.2 before T2.6.

### S3 — explicitly deferred (do not start)

Unit/integration tests for each stage live beside their stage (`tests/Unit`, `tests/Feature`), never in a shared file: each stage creates its own new test files, ownership follows §4. Test-file budget: 1 new test file per producer task, 2 for T1.3 and T2.2.

---

## 5. Test plan

### S1
- `AddOnOrderItemSchemaTest` (Feature, RefreshDatabase): columns exist, FK nullable, `parentItem()`/`childAddons()`/`addon()`/`isAddon()` resolve, `applicableFor` returns product-scoped + global active add-ons and excludes inactive/other-product ones.
- `AddOnServiceTest` (Unit): `materialize()` creates one recurring line + one `one_time` setup line only when `setup_fee > 0`; frozen field values from §3.4 asserted one by one; `recurring_cycles_limit = 0`.
- `AddOnOrderTimeTest` (Feature): storefront `POST` add-to-cart with `addons[]` → `placeOrder` → 2 `order_items`; merge identity does not collapse two different add-on sets; admin order form and API `lines[].addons[]` produce the same shape.
- `AddOnSetupFeeTest` (Feature): with `setup_fee > 0`, after two `processRecurringBilling` runs the first invoice contains the setup line and the second does not; `billing_cycles_count` on the setup line stays 0.
- `AddOnRecurringBillingTest` (Feature): add-on on an annual cycle next to a monthly parent → a due parent month does not invoice the add-on; when the add-on's own date arrives it invoices alone at its own price; `syncOrderSummary` keeps the order's next date = earliest item date.
- `InvoiceNumberFormatTest` (Unit): regex; FY label at 2026-03-31 → `2526` and 2026-04-01 → `2627`; 16-char cap; two `generateNumber()` calls are consecutive and distinct; a lock/transaction test proving concurrent delivery is serialized (mirrors `tests/Unit/OrderNumberServiceTest.php:39-45`).
- `ManualInvoicePlaceOfSupplyTest` (Feature): admin creates a manual invoice for a customer whose `state_code` equals the company's → CGST/SGST, `place_of_supply_code` set; different state → IGST; GSTIN present in the PDF HTML.
- Existing suite must stay green (see §5.3).

### S2
- `AddOnAttachTest` (Feature): attach to active order → invoice exists immediately, status `sent`, amount = price × qty (+ setup), `next_billing_date = today + cycle`, **no proration line**; attach to a pending/terminated order rejected; duplicate attach rejected; a `one_time` add-on gets `next_billing_date = null`.
- `AddOnCancelTest` (Feature): cancel → `next_billing_date = null`, an `activity_log` row with `action = 'addon.cancelled'` and the actor id, `syncOrderSummary` moved the order date; cancelling a non-add-on line throws.
- `AddOnCascadeTest` (Feature): an add-on whose parent auto-terminates stops renewing (child `next_billing_date` null); order suspension already stops all items (regression assertion).
- `AddOnAdminRoutesTest` (Feature): both routes are 403 without `orders.edit` and 302 with it; the controller resolves the order and item correctly.
- `InvoiceDocumentGstTest` (Feature): place-of-supply line and GSTIN render on `admin.invoices.show` and the PDF view; absent GSTIN → line omitted, no error.
- `AddOnInvoiceSchemaTest` (Feature): column present, nullable, survives `createWithItems` with and without a state code.

### 5.3 Existing tests that will break or must change

**Must be rewritten (they encode the superseded format):**
- `tests/Unit/BillingServiceTest.php:24-33` `test_invoice_number_format_pattern` — simulates `INV-{Y}-{5}` locally. Passes unchanged but becomes false documentation; rewrite to the FY format **and make it call `generateNumber()`** so it can actually fail.
- `tests/Unit/BillingServiceTest.php:35-51` `test_invoice_number_padding` — same; the `99999` row must be replaced (FY label occupies the second segment).

**Must NOT change (verified safe):**
- `tests/Unit/OrderNumberServiceTest.php:54-65` — calls `next('INV')`; `next()` keeps its format, so it stays green. This is the reason `next()` must not be repurposed.
- Literal `INV-*` fixtures (`AdminInvoiceTest.php:59,89,121`, `AdminSearchTest.php:108`, `BillingSearchProvidersTest.php:54-337`, `AdminInvoiceGenerateSendTest.php:140-271`, `AdminOrderFlowTest.php:826`, `PortedTablesTest.php:50`, and the `'INV-'.Str::random()` helpers) — they insert the column directly; the unique index tolerates them.
- `tests/Unit/BillingServiceTest.php:613` — uses `generateNumber()` as an opaque string.

**To be confirmed by the executor before T1.3 lands (unknown, not guessed):**
```
rg -n "igst_amount|IGST|state_code" tests/Feature/AdminInvoiceTest.php tests/Feature/AdminInvoiceGenerateSendTest.php tests/Feature/GstStateCodeNormalizationTest.php
```
Any assertion expecting IGST on a manual invoice is **superseded by this plan** and must be updated to the customer's state (or the company-state fallback) — list it in the verification report rather than weakening the new assertion.
- `tests/Feature/SeederIntegrityTest.php` — unaffected: S1/S2 add no permission string and no seeder row.

---

## 6. Risks, out of scope, assumed defaults

### Risks & mitigations
| # | Risk | Mitigation |
| --- | --- | --- |
| R1 | Manual-invoice state-code fix silently changes GST on an existing test or a live workflow that relied on IGST-by-default | T1.3's grep gate above; the fallback `resolvePlaceOfSupply` never yields a *lower* rate than the company state, it yields intra-state — cheapest legitimate outcome; verification reports every changed expectation |
| R2 | The FY change breaks an external consumer of `INV-{calendarY}-` (a stored report, a customer's saved PO, a bank reconciliation string) | Format stays `/^INV-\d{4}-\d{5}$/`; only the meaning of segment 2 changes (FY label). Grep before landing: `rg -n "INV-[0-9]{4}" app resources database tests` |
| R3 | `AddonController::destroy()` hard-deletes a definition; `nullOnDelete` then strips provenance from live order lines | Accepted for S1/S2. Recommendation for S3: soft-delete or block deletion when `orderItems()` exists. Current behaviour is already unsafe for any future reporting |
| R4 | Product-scoped add-on reused across products: `applicableFor` is per-product, but a supplier add-on can be attached to the wrong product | Server-side guard in `attach()` and in `OrderRequest::withValidator` (§3.4/§3.5) — never trust the form |
| R5 | Add-on rows carry the parent's `product_id` → they appear in product-attribution revenue | Accepted, and tax-required (see §3.4). Document in the S1 verification report; if attribution must exclude them, filter `whereNull('product_addon_id')` in the report query (S3) |
| R6 | `syncOrderSummary` visibility change (private → public) is a public-API widening | Signature and body unchanged; no caller breaks. Alternative (duplicating the min-date logic) rejected as a second source of truth |
| R7 | Renewal of an add-on whose parent was individually ended (cycle-limit exhaustion) keeps billing | Deliberate, flagged as an open question O2 — WHMCS treats the add-on as its own life; auto-termination *is* cascaded |
| R8 | Parallel writers on `BillingService.php` | S1 T1.3 and S2 T2.2/T2.6 both touch it → strictly serialised across stages, never concurrent. Inside S2, T2.2 merges before T2.6 starts |

### Explicitly out of scope
Client self-service add-on purchase/cancel UI · proration and mid-cycle credits · HSN/SAC (`products.hsn_sac`, SAC 998315 default) and any GSTR-1/HS summary export · add-on configurable options and per-add-on promo pricing · `welcome_email_template_id` dispatch on attach (the column exists, is not read) · `POST /api/orders/{order}/addons` · writing `subscription_periods` / `subscription_changes` (including `change_type = 'addon'`) · rewriting `AddonController::destroy()` to soft-delete · add-on quantity > 1 UI affordance beyond the frozen `quantity` key · renewal-reminder/e-mail templates for add-on invoices.

### Policy defaults assumed (cost of being wrong)
| # | Default | Cost if wrong |
| --- | --- | --- |
| P1 | **No proration** on mid-cycle attach (spec) | Under/over-billing of a partial period; a credit-note process would be needed to fix (S3) |
| P2 | Attach invoice status = `sent`, due today + 7 days | The customer sees an invoice before an admin reviews it; a one-line change to `draft` if the owner prefers |
| P3 | Add-on lines inherit the parent product's `product_id` (and therefore its GST type) | If a business sells add-ons with a different SAC/rate, per-line overrides are needed; tax-correct under the current engine |
| P4 | Add-on definitions are global-or-product-scoped exactly as today; no per-customer pricing | A negotiated add-on price needs the `override` mechanism (S3) |
| P5 | `recurring_cycles_limit = 0` (unlimited) for add-ons | A capped add-on cannot be expressed; a column on `product_addons` would be needed |
| P6 | Cancellation is "at period end", never a refund/void | A cancelled add-on still owes its current period — matches WHMCS; a customer expecting a pro-rata refund needs a credit note |

### Open questions for the orchestrator
- **O1** — Manual-invoice GST: does the business want *any* invoice to default to the company state when the customer's `state_code` is empty (this plan's behaviour), or to hard-fail the invoice creation until the customer's state is set? (Recommended: default, mirrors `BillingService.php:669`.)
- **O2** — Should an add-on stop when its parent hits its `recurring_cycles_limit`? This plan says no (§6 R7).
- **O3** — Should the setup fee be a separate invoice line (this plan) or rolled into the add-on's first recurring line? Separate is WHMCS-correct and needs no extra column.
- **O4** — Permission granularity: reuse `orders.edit` (this plan) or introduce `orders.addons` and accept the seeder + `SeederIntegrityTest` churn?
