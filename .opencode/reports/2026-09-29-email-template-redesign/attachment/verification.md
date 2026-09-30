# Verification — Invoice email PDF attachment

Date: 2026-09-29
Task: `App\Services\InvoiceEmailService::send()` attaches the invoice PDF to all three invoice emails
Files changed: `app/Services/InvoiceEmailService.php`, `tests/Feature/InvoiceEmailAttachmentTest.php` (new)

## Sanity — how SendEmail consumes the pushed attachment contract

`app/Jobs/SendEmail.php:46-57` — constructor, 9th positional param confirmed:

```php
public function __construct(
    public string|array $toEmail,
    public string $subject,
    public string $body,
    public ?string $fromEmail = null,
    public array $headers = [],
    public array $cc = [],
    public array $bcc = [],
    public ?string $htmlBody = null,
    public array $attachments = [],
    public ?string $logBody = null,
)
```

`app/Jobs/SendEmail.php:194-196` — `sendRich()` loops and applies each attachment:

```php
foreach ($this->attachments as $attachment) {
    $this->applyAttachment($message, $attachment);
}
```

`app/Jobs/SendEmail.php:214-221` — `applyAttachment()` resolves the file from disk, reads `disk`/`path`/`filename`/`mimeType`:

```php
$disk = Storage::disk($attachment['disk'] ?? 'local');
$absolutePath = $disk->path($attachment['path']);
$options = array_filter(['as' => $attachment['filename'] ?? null, 'mime' => $attachment['mimeType'] ?? null]);

$message->attach($absolutePath, $options);
```

The service pushes `disk: local`, `path: invoice-emails/invoice-<no>.pdf`, `filename`, `mimeType: application/pdf`, `isInline: false`, `contentId: null` — matches what the job reads (file on disk, not bytes).

## Checks

### 1. php -l (lint)
```
No syntax errors detected in app/Services/InvoiceEmailService.php
No syntax errors detected in tests/Feature/InvoiceEmailAttachmentTest.php
```
Output saved: `lint.txt`

### 2. pint --dirty
First run fixed 2 style issues in the two changed files (docblock param alignment, blank-line-at-eof). Re-run:
```
PASS  ... 8 files
```
Output saved: `pint-dirty.txt`. Note: pint also normalized pre-existing concat-spacing drift inside `InvoiceEmailService.php` (required for the file to pass the gate).

### 3. New tests
`php artisan test --filter=InvoiceEmailAttachment` →
```
PASS  Tests\Feature\InvoiceEmailAttachmentTest
✓ invoice created email carries a downloadable pdf attachment
✓ invoice overdue reminder email carries a downloadable pdf attachment
Tests: 2 passed (8 assertions)
```
Output saved: `test-invoice-email-attachment.txt`

### 4. Regression
`php artisan test --filter="Email|Order|ClientStoreNotification|AdminOrderPostActions"` →
```
Tests: 317 passed (1375 assertions)
```
Covered send paths: AdminInvoiceGenerateSendTest (send invoice emails customer, resend sent invoice), ClientStoreNotificationTest (placing an order emails the confirmation and the invoice, broken mailer never costs the order), AdminOrderPostActionsTest (send invoice emails customer when invoice generated, send confirmation emails customer), OrderEmailService flow (ClientOrderPageTest), QueueSchedulerTest (send email job persists log and sends), TicketMailTest (send email job attaches a file from disk).
Output saved: `test-regression-filter-email-order.txt`

### 5. git status --short
```
 M app/Services/Concerns/BuildsEmailVariables.php      (pre-existing, not touched)
 M app/Services/InvoiceEmailService.php                ← this change
 M app/Services/Provisioning/WelcomeMailer.php         (pre-existing, not touched)
 M app/Services/TicketMailService.php                  (pre-existing, not touched)
 M database/migrations/2026_09_15_000005_backfill_chat_transcript_email_template.php (pre-existing, not touched)
 M database/seeders/Demo/NotificationEmailSeeder.php   (pre-existing, not touched)
 M database/seeders/EmailTemplateSeeder.php            (pre-existing, not touched)
?? .opencode/reports/2026-09-29-email-template-redesign/
?? tests/Feature/InvoiceEmailAttachmentTest.php        ← this change
```
The six `M` files other than `InvoiceEmailService.php` were already modified in the working tree before this task (1219 insertions across the template/seeder redesign — see `git diff --stat`); pint analyzed them but fixed nothing in them. Only the two intended files carry this task's changes.
Output saved: `git-status.txt`