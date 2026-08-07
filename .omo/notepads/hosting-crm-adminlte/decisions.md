# Decisions — hosting-crm-adminlte

## 2026-07-31 Session 3A discrepancy resolutions (billing port decisions)

1. INVOICE STATUS: Use 7-value superset draft/sent/paid/overdue/partial/void/cancelled. Local migration currently has draft/sent/paid/overdue/void/cancelled — ADD 'partial' (markPaid writes partial). PHP: plain string constants in model, not strict enum.
2. ORDER STATUS: Use DB enum pending/active/suspended/cancelled/terminated (local migration already matches). Recurring billing queries status='active'. DDD pending/processing/completed vocabulary NOT ported.
3. PAYMENT METHODS: Use DB enum razorpay/bank_transfer/cash/cheque/credit/other (local matches). PHP stripe/credits NOT ported; razorpay requires transaction_id.
4. QUOTE_ITEMS: schema column is total_price (migration 003). Port uses total_price consistently.
5. LEGACY COLUMNS: invoices.paid_amount, invoices.last_reminder_at, invoices.reminder_count are read at runtime — ADD to local invoices migration via new migration. gst_settings.tax_rate NOT in schema — BillController reads it; port reads settings('tax_rate') fallback 18 (config_tables already seeds tax_rate=18 in settings billing group).
6. RENEWAL IGST BUG: reference passes null customer state → always IGST. Port FIXES: resolve customer state from customer's city/state (customer_contacts or customers address fields) before GST calc; fall back to company state code (intra) only when customer state known. Document divergence in code comment.
7. TWO BILLING TRACKS: Port the legacy orders+next_billing_date track as PRIMARY (it is what automation/cron actually run). subscription_periods/service_instances track kept as the decoupled future path (migrations exist; no controllers yet).
8. CREDITS: overpayment → customers.credit (direct column). credits table = separate ledger with type added/used/expired/refund. Port both as reference does.
9. CURRENCY: INR everywhere (DDD USD not ported).
10. DELETE GUARD: product delete blocked when orders status IN (active,pending).
11. USERS: use legacy PASSWORD_BCRYPT create path (DDD temp-hash bug NOT ported). Roles: admin/staff/reseller/support/billing. Status active/inactive/locked/pending.
12. HOSTING: HostingModel missing in reference — port creates full logic (findByDomainAndCustomer, create, suspend/unsuspend, changePackage, findByIpAddress).
13. KB: reference methods absent — port implements getByCategory/getWithCategory/createCategory/deleteCategory/getPopular fresh.
14. ORDERS: OrderModel::getWithItems absent — port implements as orders+order_items join.

## Session 2 decisions (carried over)
- Component namespace <x-adminlte.partials.*> dots
- E2E via test_customers.php pattern; reset_2fa.php before admin login tests

## 2026-08-06 Server hosting tree report

- HostingAccount display name = **username**. Task spec said "account_identifier" but that column does not exist in this codebase (hosting_accounts has username/domain/panel_account_id). username is the natural account identifier and matches the existing ProductHostedOnController convention.
- Route uses ->middleware('permission:hosting.view') (file convention) instead of ->permission('hosting.view') shortcut suggested in spec — identical effect, matches every other route in enterprise.php.
- "Eager loading" from spec implemented as batch per-kind name resolution (AssetRelationship defines no Eloquent relations, so with() is meaningless; batch lookup avoids N+1).
