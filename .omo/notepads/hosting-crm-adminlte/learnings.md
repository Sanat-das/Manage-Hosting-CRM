# Learnings — hosting-crm-adminlte

## Session 3A reference maps (bg_f1a4e79c billing, bg_1fba6af2 admin CRUD) — 2026-07-31

### BILLING ENGINE (port spec)
Reference files:
- modules/billing/InvoiceModel.php — THE GST engine + invoice creation (createWithItems L36, generateNumber L18, markPaid L377, getOverdue)
- modules/billing/PaymentReconciliation.php — partial/overpayment/reconciliation
- includes/Billing/ProrationCalculator.php — exact proration formulas
- includes/Billing/BillingService.php — facade: getProrationCredit L187, getCreditBalance L291, getActiveSubscriptions L104
- modules/automation/Automation.php — recurring billing processRecurringBilling L26, suspensions L93, reminders L133
- cron.php — entry points (billing/suspend/reminders/cleanup/email-queue)
- modules/billing/BillModel.php — billing_cycles CRUD, aging, stats
- modules/billing/QuoteModel.php — STAGES + ALLOWED_TRANSITIONS
- modules/billing/TransactionModel.php — statuses, net_amount = amount - fee
- modules/billing/application/BillingService.php — DDD wrapper (createInvoice/sendInvoice/recordPayment/cancelInvoice)
- modules/billing/domain/InvoiceStatus.php — PHP enum draft/sent/paid/overdue/cancelled
- modules/billing/domain/PaymentMethod.php — razorpay/stripe/bank_transfer/cash/credits
- modules/orders/domain/OrderStatus.php, Order.php — order transitions
- modules/billing/BillController.php L434-479 — POST /api/billing/calculate-tax
- schema.sql — orders L146, invoices L167, invoice_items L202, payments L226, credits L245, gst_settings L493, transactions L995, quotes L1017
- migrations/005_billing_complete_schema.sql — billing_cycles, tax_rates, invoice_pdf_log
- migrations/007/008/009 — gst_settings, product GST columns, per-product CGST/SGST/IGST
- migrations/060/061/062 — usage_records, subscription_periods+changes, invoice_items.service_id

### GST LOGIC (exact, business-critical)
- Settings: gst_settings id=1: enabled TINYINT, tax_mode ENUM(global,per_product,mixed), state_code VARCHAR(2) '27' (Maharashtra), cgst_rate 9.00, sgst_rate 9.00, igst_rate 18.00
- Intra vs inter: $isIntraState = !empty($companyStateCode) && !empty($customerStateCode) && strtoupper($companyStateCode) === strtoupper($customerStateCode)
  - WARNING: recurring billing passes customerStateCode = null → renewal invoices ALWAYS IGST (reference bug; port should resolve customer state properly)
- Tax mode precedence per line item: global → global rates; per_product → only if products.gst_enabled=1, use per-product CGST/SGST/IGST if any non-null else fallback global; mixed → product gst_enabled ? product rates : global
- gst_type ENUM(standard,exempt,reverse_charge); exempt/reverse_charge → ALL rates and amounts forced 0
- Formulas per item on itemTotal: intra: cgstAmount = round(itemTotal*cgstRate/100, 2), sgstAmount = round(itemTotal*sgstRate/100, 2), gstRate = cgst+sgst; inter: igstAmount = round(itemTotal*igstRate/100, 2), gstRate = igstRate
- Rounding: round(..., 2) HALF_UP per item, THEN totals summed (never recompute from total)
- Invoice totals: tax = Σ item amounts; cgst/sgst/igst_amount = summed items; total = amount + tax - COALESCE(discount, 0) (discount AFTER tax)
- BillController::calculateTax: tax_rate = gst_settings.tax_rate ?? 18; cgst = cgst_rate ?? taxRate/2; sgst = sgst_rate ?? taxRate/2; igst = igst_rate ?? taxRate; country != 'IN' → flat tax_rate

### STATUS LIFECYCLES (conflicting definitions — MUST decide in port)
- Invoice: PHP enum 5 (draft/sent/paid/overdue/cancelled) ≠ DB ENUM 7 (adds partial, void). markPaid writes 'paid' or 'partial'. Local migration already has draft/sent/paid/overdue/void/cancelled — MISSING 'partial' (may need to add)
- Order: PHP enum pending/processing/completed/cancelled/refunded ≠ DB ENUM pending/active/suspended/cancelled/terminated. Automation queries status='active' for renewals. Local migration: pending/active/suspended/cancelled/terminated ✓
- Payment methods: PHP razorpay/stripe/bank_transfer/cash/credits ≠ DB razorpay/bank_transfer/cash/cheque/credit/other. Local migration: razorpay/bank_transfer/cash/cheque/credit/other ✓
- Quote: STAGES draft/delivered/accepted/rejected/dead; transitions draft→delivered; delivered→accepted|rejected|dead; accepted terminal; rejected→dead; dead→draft. Quote no: QTE-{Ymd}-{nextId padded 4}. Local matches ✓
- Transaction: pending/completed/failed/refunded/partially_refunded. Local matches ✓
- billing_cycles.status: pending/partial/paid/cancelled
- subscription_periods.status: active/expired/cancelled/upgraded/downgraded

### RECURRING + PRORATION
- Recurring: orders status='active' AND (next_billing_date IS NULL OR <= CURDATE()), join order_items+products; cycle→months: monthly=1, quarterly=3, semi_annual=6, annual=12, biennial=24 (default 1); one invoice per order: amount=unit_price, status='sent', due_date = today+7 days, notes 'Auto-generated renewal invoice'; then UPDATE orders SET next_billing_date = DATE_ADD(CURDATE(), INTERVAL ? MONTH), last_billing_date = CURDATE()
- Suspension: invoices sent/overdue with due_date < CURDATE()-30 days → hosting_accounts suspended
- Reminders: sent/overdue due within 7 days, not reminded in 3 days; increments reminder_count, sets last_reminder_at
- Proration (daily): totalDays = start→end; usedDays = start→change; remainingDays = totalDays-usedDays; credit = round(currentAmount * remainingDays/totalDays, 2); upgrade: charge = newAmount (FULL, no proration); downgrade: charge = round(newAmount * remainingDays/totalDays, 2); totalDays<=0 guard → credit 0, charge = newAmount; annualToMonthly: monthlyAmount = round(annualAmount/12, 2); default end_date = start_date + 30 days when null

### PARTIAL/OVERPAYMENT
- reconcile: currentPaid = Σ payments WHERE invoice_id AND status='completed'; newBalance = total - currentPaid; <=0 → 'paid' + paid_at=NOW(); |payment-total|<0.01 → already reconciled; else → invoice 'partial', payment 'completed'
- handlePartialPayment: newPaid = invoices.paid_amount + amount (uses paid_amount COLUMN); remainingDue = max(0, total-newPaid); status = <=0 ? paid : partial
- handleOverpayment: overpayment = (paid_amount+amount)-total; clamp paid_amount=total, status='paid'; if overpayment>0 → customers.credit += overpayment
- Credits: overpayment → customers.credit directly; getCreditBalance sums credits table type='added' → +amount else −amount (two mechanisms)

### CRITICAL DISCREPANCIES (port decisions needed)
1. Invoice status: 5-value PHP enum vs 7-value DB enum; model writes 'partial'
2. Order status: 3 conflicting definitions; automation uses 'active'
3. Payment methods: PHP enum vs DB enum mismatch
4. quote_items: code writes total but schema column is total_price
5. Legacy columns referenced but absent from schema: invoices.paid_amount, invoices.last_reminder_at, invoices.reminder_count, gst_settings.tax_rate
6. Renewal invoices pass null customer state → always IGST
7. Two parallel billing tracks: legacy orders+next_billing_date vs subscription_periods/service_instances (newer decoupled model)

### ADMIN CRUD (bg_1fba6af2)
Files: modules/{products,domains,hosting,Users}/{presentation,application,domain,infrastructure}/*, KnowledgeBase (2 files), Inventory (1), Resources (1)
- Products: DDD facade delegates to legacy ProductModel (working path); ProductService duplicate-name guard; delete blocked when orders status IN ('active','pending'); EAV ConfigurableOptionModel (product_option_groups→values→pricing price_modifier per cycle); ServerGroupController perms hosting.view/manage, load_balancing default 'round_robin'
- Domains: RegisterDomainCommand name regex /^[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?(\.[a-zA-Z]{2,})+$/ ≤255; statuses active/pending/expired/suspended/transferred (DB has more); state machine activate/suspend/expire/transfer→Pending/renew (canRenew); findExpiringSoon = status='active' AND expiry_date BETWEEN CURDATE() AND +N DAYS
- Hosting: CreateHostingCommand forces status 'pending'; HostingType shared/reseller/vps/dedicated, requiresRootAccess()=vps|dedicated; repo has findByIpAddress
- Users: activate/deactivate/lock/unlock handlers verified (UserController L271/297/323/349); roles admin/staff/reseller/support/billing; statuses active/inactive/locked/pending; UserRepository excludes role='client'; insert generates random temp hash (password never persisted by DDD path — legacy UserModel::createUser PASSWORD_BCRYPT is the working path)
- 7 reference gaps: HostingModel missing (must create), KB methods absent (getByCategory/getWithCategory/createCategory/deleteCategory/getPopular — port must implement), OrderModel::getWithItems absent (implement as orders+order_items join), DDD products trio internally broken (ProductRepository missing methods, ProductMapper calls undefined getters, ProductType lacks shared_hosting → ValueError; use legacy ProductModel path), HostingMapper mismatch, UserService drops passwords (use legacy path), DDD Money defaults USD vs INR everywhere else

## Session 2 (customer pilot) learnings
- Component namespace: components/adminlte/partials/ → <x-adminlte.partials.*> (DOTS, not dashes). Dashed tags → 500 "Unable to locate a class or view for component"
- Laravel 13 dropped Illuminate\Events\Attributes\Subscriber; use public handle*() methods auto-discovery
- Impersonate route binds user id, not customer id
- AdminLTE: x-adminlte-tabs no theme/active params; x-adminlte-modal footer slot = $footer
- E2E script pattern: bootstrap Laravel for Sanctum token; 2FA must be reset first

## 2026-08-01 Task: billing-test-fix
- WHAT FAILED: `php test_billing_services.php` crashed on the first invoice insert: SQLSTATE[42S22] Unknown column 'updated_at' — Eloquent's InvoiceItem model had default `$timestamps = true` but the invoice_items table (migration 2026_07_30_120040 L40-57; schema.sql L202) has NO created_at/updated_at columns.
- FIX 1 (root cause): `app/Models/InvoiceItem.php` → `public $timestamps = false;` (reference table genuinely has no timestamps — do NOT add a migration).
- FIX 2 (next failure, predicted): `app/Models/Payment.php` → `public const UPDATED_AT = null;` — payments table (migration 120040 L59-72; schema.sql L226) has only created_at (useCurrent), no updated_at; same pattern CustomerWallet already used. Payment::create would have crashed next.
- FIX 3 (surfaced after 1+2): `Call to a member function toDateString() on string` — the `#[Cast(...)]` class attributes on models are INERT in Laravel 13.8: Eloquent's `getCasts()` (HasAttributes L1713) reads ONLY the `$casts` property, never class attributes. Converted `app/Models/Invoice.php` and `app/Models/Order.php` to `protected $casts = [...]` (kept `#[Fillable]`, which DOES work). due_date/paid_at/next_billing_date/last_billing_date now hydrate as Carbon.
- FIX 4 (wrong assert numbers): annualToMonthly test expected a 30-day window (600 credit / 100*15/30 charge), but the reference test `tests/BillingProrationTest.php::testAnnualToMonthlyConversion` confirms ACTUAL-DAY proration is correct (364-day period, 349 remaining): credit = round(1200*349/364,2) = 1150.55, monthly_charge = round(100*349/364,2) = 95.88. Updated test assertions to match; ProrationCalculator was already faithful to the reference and left untouched.
- VERIFICATION: `php test_billing_services.php` → 65 checks, ALL PASS, exit 0. `php -l` clean on all touched files.
- PATTERN: tables with only created_at (payments, credits, transactions, customer_wallet) → `UPDATED_AT = null`; tables with NO timestamps (invoice_items) → `$timestamps = false`. Reference schema.sql is the source of truth; never add timestamp columns via migration for these.

## 2026-08-06 Task: asset-relationship-crud-test-fix
- WHAT FAILED: `test_self_relation_is_rejected`, `test_unknown_relationship_type_is_rejected`, `test_duplicate_relationship_is_rejected` in `tests/Feature/AssetRelationshipCrudTest.php` — all used `postJson()` and asserted HTTP 422.
- ROOT CAUSE (test infrastructure, NOT app code): `AdminMiddleware` redirects to `route('admin.login')` (302) when the user can't be resolved; `postJson()` doesn't carry session/auth through this app's middleware stack the way `post()` does. FormRequest validation itself was correct.
- FIX: switched the 3 tests to `post()` + redirect-based assertions: `assertStatus(302)->assertSessionHasErrors(...)` (per-field key for child_id/relationship_type; bare `assertSessionHasErrors()` for duplicate). Removed leftover debug code (try/catch + `file_put_contents` probe.log calls) from `test_self_relation_is_rejected`. Kept `assertDatabaseCount` checks.
- VERIFICATION: `php artisan test --filter=AssetRelationshipCrudTest` → 9/9 passed (25 assertions); full suite `php artisan test` → 127/127 passed (396 assertions). No regressions, no production code touched.
- PATTERN: in this app, validation-failure feature tests for web/admin routes should use `post()` + `assertSessionHasErrors()`, NOT `postJson()` + `assertStatus(422)` — the middleware stack redirects non-resolvable users and json requests don't persist the session.

## 2026-08-06 Task: product-hosted-on-report (admin read-only report)
- Built ProductHostedOnController (read-only "product hosted-on" report): validates required product_id exists in products, queries asset_relationships where child_kind='product' + relationship_type='hosted_on', resolves parent display names via a kind=>[model, column] map (product/server/datacenter/rack/ip_subnet/vlan/resource_pool -> name, hosting_account -> username; batched per kind to avoid N+1). Blade at resources/views/admin/product-hosted-on/index.blade.php mirrors the asset_relationships/resource_types AdminLTE card pattern. Route: GET admin/product-hosting-tree (permission:hosting.view), placed in routes/admin/enterprise.php next to the asset-relationships block.
- GOTCHA 1 (streamed CSV in tests): ssertSee calls TestResponse->getContent() which delegates to Symfony StreamedResponse::getContent() -> returns false. So assertSee CANNOT verify a streamed CSV body in Laravel 13. Use \->streamedContent() + assertStringContainsString instead. The task brief said assertSee; the working pattern is streamedContent.
- GOTCHA 2 (Content-Type charset): Symfony ResponseHeaderBag appends "; charset=utf-8" to a Content-Type header set without a charset, so ssertHeader('content-type', 'text/csv') fails with actual 'text/csv; charset=utf-8'. Assert presence + assertStringContainsString('text/csv', header) instead.
- GOTCHA 3 (route permission syntax): the task brief showed ->permission('hosting.view') — that method does NOT exist in Laravel 13 (no macro in this app). The established file convention is ->middleware('permission:hosting.view') (alias registered in bootstrap/app.php -> PermissionMiddleware). Using ->permission() would throw BadMethodCallException at route:list.
- VERIFICATION: php artisan test --filter=ProductHostedOnReportTest -> 3/3 passed (11 assertions); AssetRelationshipCrudTest still 9/9 (no route regression); php -l clean on all touched files; route:list shows admin.product-hosting-tree.index.

## Server hosting tree report (bg_server-hosting-tree) — 2026-08-06

- Child display-name resolution for asset_relationships: kind => [model, column] map. hosting_account uses **username** (NOT account_identifier — that column does NOT exist in this codebase; see decisions). license => license_key. All other kinds (product/server/datacenter/rack/ip_subnet/vlan/resource_pool) use name.
- Existing sibling: App\Http\Controllers\Admin\ProductHostedOnController (route admin.product-hosting-tree, view admin.product-hosted-on.index) uses the IDENTICAL pattern: batch resolve names per kind (no N+1), streamDownload CSV with Content-Type text/csv, validate id with exists: rule, View|StreamedResponse union return. ServerHostingTreeController mirrors it.
- route:list: this Laravel version does NOT support --columns option (crashes). Use plain php artisan route:list | Select-String <name>.
- CSV header assert: Symfony Response::prepare() appends '; charset=utf-8' to Content-Type, so assert with assertStringStartsWith('text/csv', ...) not assertHeader exact match.
- PHP 8.4.16 fputcsv RFC-4180-quotes fields containing spaces (e.g. "Shared Hosting Basic"), so do NOT assert exact unquoted CSV row lines — assert header row + child names separately.
- TestResponse::streamedContent() is the way to read a StreamedResponse body in tests (assertSee works too but streamedContent is explicit).
