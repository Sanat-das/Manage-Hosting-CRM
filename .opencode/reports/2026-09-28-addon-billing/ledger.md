# Ledger — add-on billing (WHMCS-style, Indian GST)

Append-only. One line per event. Fields: `plan, task, session_id, verdict, detail, commands, artifact`.
Verdicts: `pending` | `completed` | `rejected` | `verified` | `blocked`.
Append; never edit or delete a prior line.

Plan artifact: `C:\Users\Administrator\.opencode\plan\2026-09-28-addon-billing\plan.md`
Repo path once copied: `.opencode/reports/2026-09-28-addon-billing/plan.md`

```csv
plan,task,session_id,verdict,detail,commands,artifact
addon-billing,PLAN,ses_f18836c1fffeSZK7RzlJ543rc1,completed,Recon + frozen contracts + S1/S2/S3 task graph written; planner write path confined to ~/.opencode/plan so the in-repo report dir was NOT written,planned,plan.md
addon-billing,T1.1,-,pending,Add parent_item_id + product_addon_id to order_items (additive FK, nullOnDelete, 2 indexes); OrderItem + ProductAddon relations,php artisan test --filter AddOnOrderItemSchemaTest,database/migrations/2026_09_28_000100_add_addon_columns_to_order_items.php
addon-billing,T1.2,-,pending,AddOnService: applicableFor/forParent/materialize (one recurring line + one one_time setup line when setup_fee>0),php artisan test --filter AddOnServiceTest,app/Services/Billing/AddOnService.php
addon-billing,T1.3,-,pending,FY-scoped race-safe invoice numbering INV-{FY}-{seq5} via OrderNumberService::nextForFinancialYear; InvoiceController::store passes resolvePlaceOfSupply,php artisan test --filter "InvoiceNumberFormatTest|ManualInvoicePlaceOfSupplyTest|OrderNumberServiceTest|BillingServiceTest",app/Services/OrderNumberService.php
addon-billing,T1.4,-,pending,Cart/order/api entry points accept addons[] (session cart key + lines.*.addons rules + materialize call),php artisan test --filter AddOnOrderTimeTest,app/Http/Requests/OrderRequest.php
addon-billing,T1.5,-,pending,Add-on pickers on storefront product page and admin order line editor,npm run build (Blade-only: skip unless a Vite entry changes),resources/views/client/store/product.blade.php
addon-billing,T1.6,-,pending,Stage-1 adversarial verification (read-only),php artisan test ; vendor/bin/pint --test ; bash scripts/seed-smoke.sh,.opencode/reports/2026-09-28-addon-billing/verification.md
addon-billing,T2.1,-,pending,invoices.place_of_supply_code nullable column + Invoice fillable + written by createWithItems/updateWithItems,php artisan test --filter AddOnInvoiceSchemaTest,database/migrations/2026_09_28_000200_add_place_of_supply_to_invoices.php
addon-billing,T2.2,-,pending,AddOnService::attach/cancel + BillingService::createAddonChargeInvoice + syncOrderSummary private->public,php artisan test --filter "AddOnAttachTest|AddOnCancelTest|AddOnSetupFeeTest",app/Services/Billing/AddOnService.php
addon-billing,T2.3,-,pending,Admin attach/cancel routes (permission:orders.edit) + OrderAddonController,php artisan test --filter AddOnAdminRoutesTest ; php artisan test --filter SeederIntegrityTest,routes/admin/orders.php
addon-billing,T2.4,-,pending,Cancel action + add-on rows on the admin order page,php artisan test --filter AddOnAdminRoutesTest,resources/views/admin/orders/show.blade.php
addon-billing,T2.5,-,pending,Place of supply + customer GSTIN on invoice show and PDF views,php artisan test --filter InvoiceDocumentGstTest,resources/views/admin/invoices/show.blade.php
addon-billing,T2.6,-,pending,Parent auto-termination ends child add-on schedules (endChildAddonSchedules),php artisan test --filter AddOnCascadeTest,app/Services/Billing/BillingService.php
addon-billing,T2.7,-,pending,Stage-2 adversarial verification (read-only),php artisan test ; vendor/bin/pint --test ; bash scripts/seed-smoke.sh,.opencode/reports/2026-09-28-addon-billing/verification.md
addon-billing,T1.3-a,-,pending,Grep gate: find manual-invoice tests asserting IGST before T1.3 lands,rg -n "igst_amount|IGST|state_code" tests/Feature/AdminInvoiceTest.php tests/Feature/AdminInvoiceGenerateSendTest.php tests/Feature/GstStateCodeNormalizationTest.php,.opencode/reports/2026-09-28-addon-billing/plan.md
addon-billing,T1.3-b,-,pending,Supersession: rewrite BillingServiceTest::test_invoice_number_format_pattern and ::test_invoice_number_padding to the FY format and make them call generateNumber(),php artisan test --filter BillingServiceTest,tests/Unit/BillingServiceTest.php
addon-billing,S3,-,deferred,HSN/SAC products.hsn_sac (SAC 998315) + client self-service + proration + API add-on endpoint + welcome-email dispatch,-,.opencode/reports/2026-09-28-addon-billing/plan.md
```
addon-billing, T1.1, ses_f187d6a66ffewRTLpBd7w9krUg, verified, order_items add-on columns + OrderItem/ProductAddon relations + schema test, "php artisan test --filter AddOnOrderItemSchemaTest", database/migrations/2026_09_28_000100_add_addon_columns_to_order_items.php
addon-billing, T1.2, ses_f187721fdffeGlOMtaUc3moEUh, verified, AddOnService materialize/forParent/applicableFor + 10 unit tests, "php artisan test --filter AddOnServiceTest", app/Services/Billing/AddOnService.php
addon-billing, T1.3, ses_f187d6a61ffeV3nAEJg9nwcogB, verified, FY invoice numbering + place-of-supply on manual invoices + renewal product_id GST fix, "php artisan test --filter BillingServiceTest", app/Services/Billing/BillingService.php
addon-billing, T1.4, ses_f18740bdfffeMhfmxmH3SmmVq1, verified, add-ons at all four order entry points + total recompute + server-side validation + 9 feature tests, "php artisan test --filter AddOnOrderTimeTest", app/Http/Requests/OrderRequest.php
addon-billing, T1.4d, ses_f18649e5effexTK0tSxX4rsqSl, verified, setup-fee-never-renews and own-cycle renewal tests, "php artisan test --filter AddOnRecurringBillingTest", tests/Feature/AddOnRecurringBillingTest.php
addon-billing, T1.5, ses_f186aabbaffeYDGD8b1pM4uZRg, verified, storefront + admin order-form add-on pickers + UI tests, "php artisan test --filter AddOnPickerUiTest", resources/views/admin/orders/create.blade.php
addon-billing, T1.6, ses_f18611887ffeQRNLYeUbZ6FkGd, verified, adversarial verification: 6/6 falsification targets hold; 3 findings fixed (renewal product_id, admin-cart test, update-path test), "php artisan test", .opencode/reports/2026-09-28-addon-billing/plan.md
addon-billing, gate-full-suite, ses_f188dafb7ffeLUf6P9stPRYSPB, verified, full suite green after S1 integration, "php artisan test -> 2750 passed (13730 assertions)", .
addon-billing, gate-migration-rollback, ses_f188dafb7ffeLUf6P9stPRYSPB, verified, up + rollback + re-migrate on scratch sqlite, "php artisan migrate; php artisan migrate:rollback --step=1", database/migrations/2026_09_28_000100_add_addon_columns_to_order_items.php
addon-billing, gate-seed-smoke, ses_f18611887ffeQRNLYeUbZ6FkGd, verified, scratch-DB seed smoke ALL PASS (products 9, orders 10, perms 106/106, idempotent), "bash scripts/seed-smoke.sh", scripts/seed-smoke.sh
addon-billing, R1, ses_f188dafb7ffeLUf6P9stPRYSPB, reviewed, add-on billing-cycle review vs WHMCS docs (12-row comparison, 9 findings F1-F9), "websearch docs.whmcs.com; codegraph/reads", .opencode/reports/2026-09-28-addon-billing/review.md
addon-billing, R1-next, ses_f188dafb7ffeLUf6P9stPRYSPB, planned, next: F1 parent-cycle rule (addon pricing matrix), S2 attach/cancel, F3 advance invoice window, F6 dunning + F7 zero-value advance, "—", .opencode/reports/2026-09-28-addon-billing/review.md
addon-billing, T2-fix1, ses_f145e7c23ffemqOAROIqEgxAyC, verified, attach() recomputes orders.total from rows (fails without: 100 vs 200), "php artisan test --filter AddOnAttachTest", app/Services/Billing/AddOnService.php
addon-billing, T2-fix2, ses_f146951d0ffeSzSVwNWqayEt2R, verified, once-per-cycle renewal guard + inclusive window boundary (falsified 3 then green), "php artisan test --filter RenewalInvoiceWindowTest", app/Services/Billing/BillingService.php
addon-billing, T2-fix3, ses_f145e7c21ffepTLJXUGZjDySMb, verified, matrix sync wrapped in DB transaction, "php artisan test --filter AdminAddonPricingMatrixTest", app/Http/Controllers/Admin/AddonController.php
addon-billing, T2-fix4, ses_f144f3fa9ffeZ7r214zZqqTOlz, verified, generateInvoice: draft surfaced, non-draft live invoice refused (AdminInvoiceGenerateSendTest updated), "php artisan test --filter OrderGenerateInvoiceGuardTest", app/Http/Controllers/Admin/OrderController.php
addon-billing, T2-verify2, ses_f1441ce2bffeXIwqxfh6I3Xke6, verified, adversarial stage-2 verification: 4 findings raised (2 major fixed above), targeted 119 pass, "php artisan test --filter AddOn...", .opencode/reports/2026-09-28-addon-billing/review.md
addon-billing, gate-targeted, ses_f188dafb7ffeLUf6P9stPRYSPB, verified, all stage targeted tests pass, "php artisan test --filter <20 suites> -> 136 passed (737 assertions)", .
addon-billing, gate-rollback2, ses_f188dafb7ffeLUf6P9stPRYSPB, verified, migrate + rollback --step=2 + re-migrate on scratch sqlite, "php artisan migrate; migrate:rollback --step=2", database/migrations/2026_09_29_000200_add_place_of_supply_to_invoices.php
addon-billing, gate-full-suite2, ses_f188dafb7ffeLUf6P9stPRYSPB, verified, full suite green, "php artisan test -> 2822 passed (14160 assertions)", .
addon-billing, gate-seed-smoke2, ses_f188dafb7ffeLUf6P9stPRYSPB, verified, scratch-DB seed smoke ALL PASS (products 9, orders 10, perms 106/106, idempotent), "bash scripts/seed-smoke.sh", scripts/seed-smoke.sh
addon-billing, gate-pint-stage, ses_f188dafb7ffeLUf6P9stPRYSPB, verified, pint clean on 22 stage PHP files, "vendor/bin/pint --test <22 stage files> -> PASS", .
addon-billing, t20, ses_f188dafb7ffeLUf6P9stPRYSPB, verified, real Chrome order via admin UI (Dusk): ORD-2026-00001 total 398.00, add-on row parent-linked, INV-2627-00001 draft amount 398.00 tax 71.64 pos 27, "php artisan dusk --filter=AddOnOrderDuskTest -> 1 passed (14 assertions)", tests/Dusk/AddOnOrderDuskTest.php
addon-billing, t21, ses_f135f537bffeJNmLgtQ5GQSPBt, verified, admin form preview includes checked add-ons+setup (Dusk 469.64/352.82, falsified), "php artisan dusk --filter=AddOnOrderDuskTest", resources/views/admin/orders/create.blade.php
addon-billing, t22, ses_f135f5376ffe6rHsCf1iuVmjg3, verified, storefront cart/checkout add-on preview lines via previewLinesFor (falsified 3-fail), "php artisan test --filter AddOnCartPreviewTest", app/Services/Billing/AddOnService.php
addon-billing, t23, ses_f135f5373ffepg3zfOaJE9FwaN, verified, admin cart checkout preview lines (falsified), preview==placeOrder total 175.00, "php artisan test --filter AddOnAdminCartPreviewTest", app/Http/Controllers/Admin/CartController.php
addon-billing, t24, ses_f188dafb7ffeLUf6P9stPRYSPB, verified, integrated: 69 phpunit + 2 dusk green, pint 10 files PASS, "php artisan test --filter AddOn... + dusk", .
