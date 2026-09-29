# Review — add-on billing cycle vs WHMCS (S1)

Date: 2026-09-29 · Reviewer: build (read-only pass) · Scope: the billing cycle/lifecycle of S1 add-ons, compared with WHMCS 8.x/9.x product add-ons.
Ground truth: WHMCS docs — Product Addons, Prorata Billing, Cancellations (Addon Termination), Billable Items (invoice generation), all retrieved 2026-09-29.

## Verdict

The renewal-cycle mechanics we shipped are correct and test-backed: own-cycle renewal, one-time setup never renews, per-item due dates, billing stops with the order status, and the order summary tracks the earliest item date. In WHMCS terms, however, we implemented the **"add-on ordered later" model for every purchase** — WHMCS syncs an add-on bought *with* a product to the parent's cycle when the add-on's pricing matrix offers it. That is the one behavioural divergence I would fix before calling this WHMCS-style; the rest are lifecycle features (attach/cancel, dunning, prorata, welcome email) that are staged or missing.

## Comparison

| # | WHMCS behaviour (source) | Ours (S1) | Verdict |
| --- | --- | --- | --- |
| 1 | Add-on bought **with** a product: uses the **parent's cycle** when the add-on's pricing matrix offers it, else the add-on's minimum term | Always the add-on's own `billing_cycle` (`AddOnService::materialize`, plan §3.4) | **Diverge** — monthly add-on + annual hosting = 12 invoices/yr here, 1 in WHMCS |
| 2 | Add-on bought **later** (admin "New Addon", client "View Available Addons"): own cycle + own Next Due Date | Not implemented (S2 routes reserved, plan §3.6) | **Missing** (staged) |
| 3 | Setup fee charged on order; price on the set frequency | Separate `one_time` row, never renews (`AddOnService`, test `AddOnRecurringBillingTest`) | **Match** |
| 4 | Prorata: **opt-in** per add-on ("sync addon due date with the parent service"), default anniversary billing | No proration at all (plan P1) | **Match by default**, opt-in missing |
| 5 | Renewals billed on each item's own cycle/next-due date; items due the same day consolidated into one invoice **per client** | Each item renews on its own cycle; one invoice **per order** per run (`processRecurringBilling`) | **Match** in cadence, coarser invoice grouping |
| 6 | Invoice generated **X days before** the due date (Invoice Generation setting); due on the due date | Invoice generated **on** the due date, due = today+7 (`BillingService.php:568-570,676`) | **Diverge** |
| 7 | Nonpayment ladder: overdue → suspend → terminate, configurable | Status flip only (`OverdueInvoiceCheckCommand.php:22-36`); `hosting_suspend_on_overdue` / `automation_overdue_actions` exist but are read nowhere | **Missing** (pre-existing) |
| 8 | Add-on terminated with the parent service ("when WHMCS terminates a service, it also cancels the associated addons"); add-ons are separate records with Active/Suspended/Cancelled/Terminated + Termination Date | Billing stops via order status gate; add-on rows share the parent product's auto-terminate term; no per-add-on status/date | **Billing-equivalent**, model thinner |
| 9 | Add-on can be suspended for nonpayment; per-addon option "Suspend Parent Product if addon overdue" | No add-on-level status or parent-suspend rule | **Missing** |
| 10 | Per-add-on "Allow Multiple Quantities" toggle | Quantity always allowed 1–99 | Superset (no toggle) |
| 11 | Add-on welcome email (`welcome_email_template_id` equivalent) | Column exists on `product_addons`, never dispatched | **Missing** (staged) |
| 12 | On-demand (early) renewals, per add-on, WHMCS 8.9+ | Not implemented | **Missing** (new feature, not staged) |

## Findings (severity)

- **F1 major — order-time cycle does not follow the parent.** `AddOnService::materialize` sets `billing_cycle = $addon->billing_cycle` unconditionally. WHMCS's rule needs a per-cycle price matrix on `product_addons` (today: one cycle + one price, `database/migrations/2026_07_30_120010_create_product_tables.php:89`), or at minimum a per-add-on "follow parent cycle" flag with a fallback price. Repro: order a monthly add-on on an annual product → two items with different cycles (asserted deliberately in `AddOnRecurringBillingTest`).
- **F2 major — later attach / client self-serve absent.** No route or UI; plan §4 S2/S3. Repro: `php artisan route:list | findstr addons` shows only the catalog CRUD.
- **F3 medium — no advance invoice generation.** `processRecurringBilling` selects `next_billing_date <= today` (`BillingService.php:578`) and sets `due_date = today + 7` (`:676`). A WHMCS-style "generate N days before due" setting does not exist.
- **F4 medium — no prorata opt-in** (plan P1 documents the intentional no-proration default).
- **F5 medium — no add-on status/history.** Cancellation (S2) will clear `next_billing_date`; WHMCS keeps Cancelled/Terminated records with dates. Audit trail exists only via `activity_log` when S2 lands.
- **F6 medium — dunning ladder missing.** `hosting_suspend_on_overdue` (`app/Settings/HostingSettings.php:20`) and `automation_overdue_actions` (`app/Settings/AutomationSettings.php:26`) are declared and read by nothing in `app/`. Repro: enable either in Settings → no suspend/terminate automation runs; only status flips.
- **F7 minor — zero-value add-on gets stuck due.** `$total <= 0` items are skipped with `$errors++` *before* the due-date advance (`BillingService.php:610-616`), so a ₹0 (or fully discounted) add-on stays due forever and increments the error count on every run. Fix: advance the schedule without invoicing.
- **F8 minor — no "Allow Multiple Quantities" toggle** (always on).
- **F9 minor — `welcome_email_template_id` never dispatched.**

## What is verified good (with evidence)

- Activation seeds every item from its own cycle, `one_time` → null: `app/Services/OrderService.php:122-134,403-408`.
- Renewal: per-item due check, one_time skipped, per-item advance, earliest-date summary: `BillingService.php:590-641,682-691,708-725`; tests `AddOnRecurringBillingTest` (3), `AddOnOrderTimeTest` (9), `AddOnServiceTest` (10), full suite 2750 passed.
- Money coherence: order total = Σ item totals; invoice `amount` = that; `total = amount + tax − discount`: `BillingService.php:80` + `AddOnOrderTimeTest`.
- GST on every invoice path incl. renewals and manual: `InvoiceController.php:142,239`, `BillingService.php:655,752`.
- Billing stops on suspend/terminate/cancel: order-status gate (`BillingService.php:575-578`); auto-termination ends each item's schedule (`:786-826`), and add-on rows inherit the parent product's term so they end with the parent.

## Recommended next (in order)

1. **F1** — extend `product_addons` to a per-cycle pricing matrix (or add `follow_parent_cycle` + per-cycle fallback), implement WHMCS's rule, test both paths.
2. **S2 as planned** — attach/cancel routes + UI, `invoices.place_of_supply_code`, GSTIN/place of supply on documents (closes F2 partly, F5).
3. **F3** — invoice-generation window setting (`generate_days_before_due`) wired into the recurring selection.
4. **F6 + F7** — dunning ladder (`automation_overdue_actions`) and the zero-value advance fix; both small, test-backed.
5. Optional: **F4** prorata flag, **F8** quantity toggle, **F9** welcome email dispatch.
