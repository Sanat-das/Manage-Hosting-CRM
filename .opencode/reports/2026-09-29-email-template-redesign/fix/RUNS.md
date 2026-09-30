# Fix verification log — 2026-09-29

Fixes applied: `<br>` → newline in both `toPlainText()` (MED), credentials heading
moved into `WelcomeMailer::credentialsBlock()` (LOW), demo ticket-reply footer
stripped in `TicketMailService::body()` (LOW). Fix 4 not touched.

## 1. php -l (all four files)
```
No syntax errors detected in app\Services\Concerns\BuildsEmailVariables.php
No syntax errors detected in app\Services\Provisioning\WelcomeMailer.php
No syntax errors detected in database\seeders\EmailTemplateSeeder.php
No syntax errors detected in app\Services\TicketMailService.php
```

## 2. vendor/bin/pint --dirty
```
PASS  ..... 6 files
```
No files fixed (PASS, not FIXED) — the changed files were already pint-clean.

## 3. Plain-text regression proof
`php .opencode/reports/2026-09-29-email-template-redesign/fix/plaintext-check.php`
→ exit 0
```
PASS: BuildsEmailVariables invoice_created footer (3 lines)
PASS: WelcomeMailer service_activated footer (3 lines)
PASS: plain-text derivation keeps address / email / phone on separate lines.
```

## 4. Empty-credentials proof
`php .opencode/reports/2026-09-29-email-template-redesign/fix/service-activated-empty.php`
→ exit 0
```
PASS: rendered service_activated body with empty credentials contains no 'Your service credentials' heading.
```

## 5. Drift checks (re-run, untouched)
```
php .opencode/reports/2026-09-29-email-template-redesign/chat-body-identity.php
  seeder  chat_transcript body: 5820 bytes sha256=30624588...82c
  migration chat_transcript body: 5820 bytes sha256=30624588...82c
  PASS: chat_transcript bodies are byte-identical.        (exit 0)

php .opencode/reports/2026-09-29-email-template-redesign/render-check.php
  canonical: 12 templates checked, 0 leftover placeholder(s)
  demo:      7 templates checked, 0 leftover placeholder(s)
  PASS: every placeholder in every shipped template is supplied by its sender.  (exit 0)
```

## 6. Targeted test suite
`php artisan test --filter="WelcomeEmailCredentials|ProxmoxCredentialDelivery|HypervCredentialDelivery|ChatTranscript|TicketMail|OutboundSignature"`
→ exit 0
```
Tests: 116 passed (292 assertions)
```
Suites: WelcomeEmailCredentialsTest, ProxmoxCredentialDeliveryTest,
HypervCredentialDeliveryTest, ChatTranscriptEmailTest, ClientChatTranscriptTest,
OutboundSignatureTest, TicketMailParserTest, TicketMailTest, TicketMailboxRoutingTest.

## 7. git status --short
```
 M app/Services/Concerns/BuildsEmailVariables.php
 M app/Services/Provisioning/WelcomeMailer.php
 M app/Services/TicketMailService.php
 M database/migrations/2026_09_15_000005_backfill_chat_transcript_email_template.php
 M database/seeders/Demo/NotificationEmailSeeder.php
 M database/seeders/EmailTemplateSeeder.php
?? .opencode/reports/2026-09-29-email-template-redesign/
```
Deviation: `backfill_chat_transcript_email_template.php` and
`Demo/NotificationEmailSeeder.php` were already modified (uncommitted) before
this fix session — they belong to the prior redesign work that produced this
report dir (chat-body-identity.php and render-check.php verify them, and both
still PASS). This session edited exactly the four scoped files; no edit was made
to the two pre-existing files, and pint reported PASS rather than FIXED for them.