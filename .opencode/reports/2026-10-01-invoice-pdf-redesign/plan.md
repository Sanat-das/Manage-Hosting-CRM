# Plan: Invoice PDF redesign to GST-compliant standard

Goal: Update `resources/views/admin/invoices/pdf.blade.php` to a GST-compliant standard
invoice layout: settings-driven seller + bank block, INR currency, status markers,
amount in words — without breaking the 3 render callers or existing PDF tests.

## Acceptance

- `php artisan test` full suite green (settings suite, InvoiceDocumentGstTest,
  InvoiceEmailAttachmentTest, PortedTablesTest, AmountInWordsTest).
- `php vendor/bin/pint --dirty` clean.
- Real DomPDF render of the view produces a PDF containing the ₹ glyph (DejaVu Sans),
  seller block, status marker, amount-in-words line (verify script).
- Admin settings Billing tab shows the new "Invoice Seller & Bank Details" card;
  values persist (Settings page Save All) and appear on the PDF.

## Contracts (frozen 2026-10-01)

### Settings — typed Spatie properties on `app/Settings/BillingSettings.php`
Keys (all nullable string): `seller_name`, `seller_address`, `seller_gstin`,
`seller_phone`, `seller_email`, `bank_name`, `bank_account_holder`, `bank_account_no`,
`bank_ifsc`.

- Registered in `app/Support/AppSettings.php` `TYPED_KEYS` map => `BillingSettings::class`.
- Seeded by one additive migration calling `SettingsPropertySeeder::seedMissing([BillingSettings::class])`
  (mirror `database/migrations/2026_09_04_000001_seed_branding_settings.php`).
- Tab placement automatic via `BillingSettings::group() === 'billing'`.
- Fallbacks on the PDF: `seller_name` -> `config('app.name')`; every other field hidden
  when empty; bank block hidden when all bank_* empty.

### PDF template (`resources/views/admin/invoices/pdf.blade.php`)
- View data unchanged: `$invoice` (+ `$gstBreakdown`). All `customer.user` access null-safe
  (`?->`) — client pdf() does not load `customer.user`.
- Preserve exact strings (tests assert them): `Place of Supply: {code}`, `GSTIN: {tax_id}`,
  and the single tax row `Tax (CGST + SGST)` (intra) / `Tax (IGST)` otherwise, shown when
  `$gstBreakdown['tax'] > 0`.
- `@include('partials._selected_options', ...)` kept byte-identical.
- Money cells rendered `₹{{ number_format(x, 2) }}`.
- Status marker from `$invoice->status` (draft/sent/paid/overdue/partial/void/cancelled):
  paid -> green PAID badge; void/cancelled -> red badge + "NOT VALID FOR PAYMENT" strip;
  others -> neutral badge.
- Amount in words: `\App\Support\AmountInWords::convert($invoice->total)` labelled
  "Amount in words:" under the totals block.
- Payment terms line "Payment due by {due_date}" when `due_date` set.
- Font stack must include a ₹-capable face (DejaVu Sans; DomPDF default).

### Utility (`app/Support/AmountInWords.php`)
- `public static function convert(float $amount): string` — INR convention:
  "Rupees {whole} and {paise} Paise Only", "Rupees {whole} Only" when paise = 0;
  Indian numbering (lakh/crore). New unit tests required.

## Tasks

- [ ] A settings surface (general): BillingSettings keys + AppSettings TYPED_KEYS +
      migration + Billing-tab card in settings index blade.
      check: settings suite + AdminSettingsInventoryTest green; pint clean
- [ ] B AmountInWords utility + unit tests
      check: `php artisan test --filter=AmountInWordsTest`; pint clean
- [ ] C PDF template redesign + InvoiceDocumentGstTest additions
      check: InvoiceDocumentGstTest / InvoiceEmailAttachmentTest / PortedTablesTest green; pint clean
- [ ] D verify whole (verify): full suite, pint, real DomPDF render probe (₹ glyph)

## Decisions
- Settings read directly in the blade via `AppSettings::get()` (request-cached; view already
  reads `config('app.name')`) — no controller/service churn across 3 callers.
- Single tax row kept per user; HSN/SAC deferred (no schema field exists).
- Amount in words over invoice total (not balance due) — standard GST practice.

## Open questions
None.

## Follow-up review round (2026-10-01, post-review)
User reviewed INV-2627-00004 PDF; approved suggestions 2,4,5,6,8 (Ref line, thead frame,
tax-rate label, INR on Total, smaller option lines). Implemented in one slice: 5 blade
edits + 3 new tests + 1 assertion. Deferred: HSN/SAC, client-route draft gate (decision),
seller/customer data entry. Evidence: 25/25 filter tests, pint clean.

## Risks
- AdminSettingsInventoryTest / NewSettingsGroupsTest may hardcode the key inventory; update
  expectations to include the 9 new keys (legitimate change, not weakening).
- ₹ glyph depends on DomPDF font; verified in task D with a real render.
- Settings audit/concurrency suite may constrain the new keys — keep writes via the standard
  saveTyped path only.