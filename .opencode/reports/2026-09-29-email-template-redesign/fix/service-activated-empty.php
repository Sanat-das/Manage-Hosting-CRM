<?php

/**
 * Regression proof for the dangling "Your service credentials" heading.
 *
 * A compute VM that supplied no guest credentials renders `{{service_credentials}}`
 * as '' (documented in WelcomeMailer::deliveredCredentials), so a static heading
 * in the seeded template would ship orphaned. The heading now lives inside
 * WelcomeMailer::credentialsBlock() and is only emitted with a non-empty block.
 * This renders the seeded `service_activated` body with an empty credentials
 * map and asserts no "Your service credentials" text survives.
 *
 * Run: php .opencode/reports/2026-09-29-email-template-redesign/fix/service-activated-empty.php
 */

$root = dirname(__DIR__, 4);

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Database\Seeders\EmailTemplateSeeder;

$const = (new ReflectionClass(EmailTemplateSeeder::class))->getReflectionConstant('TEMPLATES');
$list = $const?->getValue();
$templates = [];

if (is_array($list)) {
    foreach ($list as $entry) {
        if (is_array($entry) && isset($entry['name'], $entry['body'])) {
            $templates[$entry['name']] = $entry;
        }
    }
}

if (! isset($templates['service_activated'])) {
    echo "FAIL: could not read seeder TEMPLATES (service_activated missing)\n";
    exit(2);
}

$body = preg_replace_callback(
    '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
    static fn (array $m): string => '',
    $templates['service_activated']['body'],
) ?? $templates['service_activated']['body'];

$heading = 'Your service credentials';

if (str_contains($body, $heading)) {
    echo "FAIL: rendered service_activated body still contains '{$heading}' with empty credentials.\n";
    exit(1);
}

echo "PASS: rendered service_activated body with empty credentials contains no '{$heading}' heading.\n";