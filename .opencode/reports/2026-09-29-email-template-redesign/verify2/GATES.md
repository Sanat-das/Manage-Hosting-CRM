verify2 — adversarial verification, 2026-09-29
Slice 1: soft-light email template redesign (EmailTemplateSeeder 10 HTML, Demo/NotificationEmailSeeder 6 HTML, chat_transcript migration twin)
Slice 2: invoice PDF attachment (InvoiceEmailService::send → admin.invoices.pdf via dompdf → local disk → 9th SendEmail arg)

GATE 1 php -l on all 8 files ............ PASS (8x "No syntax errors detected")
GATE 2 pint --dirty ..................... PASS (8 files, no fixes)
GATE 3 chat-body-identity.php ........... PASS (seeder 5847 B sha256 f0accc26… = migration 5847 B sha256 f0accc26…)
GATE 4 render-check.php ................. PASS (canonical 12 checked / 0 leaks; demo 7 checked / 0 leaks; demo footers stripped 0 surviving)
GATE 5 hex/style audit .................. PASS (style-audit-probe.php: 20 bodies audited, 17 HTML, 3 plain-text skipped;
       0x background:#0f172a in any file; palette = white/#f1f5f9/#f8fafc + pastels eff6ff/fef2f2/f0fdf4/fffbeb;
       CTA accents dc2626 x2, 166534 x1, b45309 x1 — all inside <a> anchors w/ white text, exempted;
       every HTML body: white header td, logo img width=140 + max-width, no white-on-white)
GATE 6 targeted tests ................... PASS (1114 passed, 4345 assertions; InvoiceEmailAttachmentTest both tests green)
GATE 7 full suite ....................... PASS (2830 passed, 14205 assertions, 1217.59s — 0 failures; full-suite.out)
GATE 8 git status/diffstat .............. PASS (exactly 7 M + 1 ?? test + report dir; pint changed nothing)

FALSIFY A degrade path .................. PASS (attachment-degrade-probe.php: send()=true, 1 job, attachments=[], no file, logged)
FALSIFY B SendEmail contract ............ CONSISTENT (constructor pos 9 attachments; handle→sendRich→applyAttachment reads disk/path/filename/mimeType; isInline/contentId documented-but-unread — pre-existing doc drift, service pushes false/null)
FALSIFY C logo on white header .......... PASS (logo = solid #2563eb pill w/ white text — visible on white; img constrained in all bodies; no white text in header band)
FALSIFY D demo whitelists ............... PASS (render-check.php:156-161 demo names share $whitelists + ['currency']; demo invoice_created → invoice_created whitelist line 83)
FALSIFY E dark stragglers ............... NONE (all #0f172a = color: text; accents only as anchor CTA backgrounds)
FALSIFY F no-items/no-order invoice ..... PASS (committed test invoice has no items, no order, tax 0; real dompdf render asserted via file existence; view never touches ->order; gst_breakdown accessor returns array; due_date cast date)
