<?php

/**
 * Regression proof for the plain-text `<br>` fix.
 *
 * Before the fix, `toPlainText()` stripped `<br>` without leaving a separator,
 * so the footer of every HTML template mail rendered as one mashed line
 * (address + email + phone concatenated). This feeds the real `invoice_created`
 * body (with sample address/email/phone) through both `toPlainText()`
 * implementations and asserts the three values land on three separate lines.
 *
 * Run: php .opencode/reports/2026-09-29-email-template-redesign/fix/plaintext-check.php
 */

$root = dirname(__DIR__, 4);

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Concerns\BuildsEmailVariables;
use App\Services\Provisioning\WelcomeMailer;
use Database\Seeders\EmailTemplateSeeder;

$failures = 0;

/**
 * @return array<string, array{subject: string, body: string}>|null
 */
function seededTemplates(): ?array
{
    $const = (new ReflectionClass(EmailTemplateSeeder::class))->getReflectionConstant('TEMPLATES');
    $list = $const?->getValue();

    if (! is_array($list)) {
        return null;
    }

    $byName = [];

    foreach ($list as $entry) {
        if (is_array($entry) && isset($entry['name'], $entry['body'])) {
            $byName[$entry['name']] = $entry;
        }
    }

    return $byName;
}

function renderBody(string $body, array $vars): string
{
    return preg_replace_callback(
        '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
        static fn (array $m): string => $vars[$m[1]] ?? '',
        $body,
    ) ?? $body;
}

/**
 * Assert the address, email and phone samples each sit on their own line in
 * the footer region of the plain text (no concatenation). The address sample
 * appears only in the footer, so it locates the region; the email sample also
 * appears in a mailto link elsewhere, so only the three lines starting at the
 * address line are inspected.
 */
function assertSeparateLines(string $plain, array $samples, string $label): bool
{
    $lines = preg_split('/\r?\n/', $plain) ?: [];

    $addressLine = null;

    foreach ($lines as $i => $line) {
        if (str_contains($line, $samples['address'])) {
            $addressLine = $i;
            break;
        }
    }

    if ($addressLine === null) {
        echo "FAIL: {$label}\n";
        echo '  address sample not found in plain text at all'."\n";
        echo '  plain   : '.var_export($plain, true)."\n";

        return false;
    }

    // The footer renders address / email / phone consecutively (the template
    // joins them with <br>), so the region is the three lines starting at the
    // address line — one per sample, in template order.
    $region = [];

    foreach (array_keys($samples) as $offset => $name) {
        $index = $addressLine + $offset;
        $region[$name] = $index < count($lines) ? $lines[$index] : '';
    }

    $mashed = [];

    foreach ($region as $name => $line) {
        foreach ($samples as $otherName => $otherValue) {
            if ($otherName !== $name && str_contains($line, $otherValue)) {
                $mashed[] = sprintf('%s line also carries %s', $name, $otherName);
            }
        }
    }

    if ($mashed !== []) {
        echo "FAIL: {$label}\n";
        echo '  mashed : '.implode('; ', $mashed)."\n";
        echo '  region : '.var_export($region, true)."\n";

        return false;
    }

    echo "PASS: {$label}\n";

    return true;
}

$templates = seededTemplates();

if ($templates === null || ! isset($templates['invoice_created']) || ! isset($templates['service_activated'])) {
    echo "FAIL: could not read seeder TEMPLATES (invoice_created / service_activated missing)\n";
    exit(2);
}

$samples = [
    'address' => '123 Test Street',
    'email' => 'billing@example.test',
    'phone' => '+1 555 010 0999',
];

$vars = [
    'company_address' => $samples['address'],
    'company_email' => $samples['email'],
    'company_phone' => $samples['phone'],
];

// ── Check 1: BuildsEmailVariables::toPlainText on the invoice_created body ──
$probe = new class
{
    use BuildsEmailVariables;

    public function p(string $h): string
    {
        return $this->toPlainText($h);
    }
};

$invoiceHtml = renderBody($templates['invoice_created']['body'], $vars);
$invoicePlain = $probe->p($invoiceHtml);

if (! assertSeparateLines($invoicePlain, $samples, 'BuildsEmailVariables invoice_created footer (3 lines)')) {
    $failures++;
}

// ── Check 2: WelcomeMailer::toPlainText on the service_activated body ──
$reflection = new ReflectionClass(WelcomeMailer::class);
$mailer = $reflection->newInstanceWithoutConstructor();
$mailerPlainMethod = $reflection->getMethod('toPlainText');

$welcomeHtml = renderBody($templates['service_activated']['body'], $vars + ['name' => 'Test']);
$welcomePlain = $mailerPlainMethod->invoke($mailer, $welcomeHtml);

if (! assertSeparateLines($welcomePlain, $samples, 'WelcomeMailer service_activated footer (3 lines)')) {
    $failures++;
}

if ($failures > 0) {
    echo "\n{$failures} check(s) FAILED\n";
    exit(1);
}

echo "\nPASS: plain-text derivation keeps address / email / phone on separate lines.\n";