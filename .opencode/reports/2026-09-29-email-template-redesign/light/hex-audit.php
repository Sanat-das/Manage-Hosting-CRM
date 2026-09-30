<?php

/**
 * Soft-light hex audit for the shipped e-mail template bodies.
 *
 * Verifies the dark-navy/saturated design is fully gone from the 16 HTML
 * bodies (10 canonical + 6 demo; the migration's chat_transcript body is the
 * byte-identical twin of the seeder's and is audited separately on the raw
 * text) and that every remaining band is one of the five soft-light tints.
 *
 * Checks per HTML body:
 *   1. No `background:#0f172a` anywhere.
 *   2. No `color:#ffffff` except inside `<a ...>` CTA anchors (buttons keep
 *      solid accent backgrounds with white text by design).
 *   3. Every hero band uses one of the five tints
 *      (#eff6ff #fef2f2 #f0fdf4 #fffbeb #f8fafc) and every header band is the
 *      white `#ffffff` header.
 *
 * Run: php .opencode/reports/2026-09-29-email-template-redesign/light/hex-audit.php
 * Output: light/hex-audit.txt (redirected by the caller)
 */

require __DIR__.'/../../../../vendor/autoload.php';

$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const HERO_TINTS = ['#eff6ff', '#fef2f2', '#f0fdf4', '#fffbeb', '#f8fafc'];

/**
 * @return list<string> bodies of the HTML templates from a seeder TEMPLATES constant
 */
function htmlBodies(string $class): array
{
    $ref = new ReflectionClass($class);
    $templates = $ref->getReflectionConstant('TEMPLATES')->getValue();

    $bodies = [];

    foreach ($templates as $template) {
        if (str_contains($template['body'], '<!doctype html')) {
            $bodies[] = $template['body'];
        }
    }

    return $bodies;
}

/**
 * @return string the migration's chat_transcript nowdoc contents
 */
function migrationBody(): string
{
    $path = __DIR__.'/../../../../database/migrations/2026_09_15_000005_backfill_chat_transcript_email_template.php';
    $source = file_get_contents($path);
    $open = strpos($source, "<<<'BODY'");
    $contentStart = strpos($source, "\n", $open) + 1;
    $close = strpos($source, "\nBODY;", $contentStart);

    return substr($source, $contentStart, $close - $contentStart);
}

/**
 * @return list<int> byte offsets of color:#ffffff NOT inside an anchor tag
 */
function whiteOutsideAnchors(string $body): array
{
    $hits = [];
    $offset = 0;

    while (($pos = strpos($body, 'color:#ffffff', $offset)) !== false) {
        $lineStart = strrpos(substr($body, 0, $pos), '<');
        $tagEnd = strpos($body, '>', $lineStart);

        if ($lineStart !== false && $tagEnd !== false) {
            $tag = substr($body, $lineStart, $tagEnd - $lineStart + 1);

            if (! preg_match('/^<a[\s>]/i', $tag)) {
                $hits[] = $pos;
            }
        } else {
            $hits[] = $pos;
        }

        $offset = $pos + 1;
    }

    return $hits;
}

$failures = 0;
$audited = 0;

$sets = [
    'canonical seeder' => htmlBodies(Database\Seeders\EmailTemplateSeeder::class),
    'demo seeder' => htmlBodies(Database\Seeders\Demo\NotificationEmailSeeder::class),
];

foreach ($sets as $label => $bodies) {
    echo "== {$label}: ".count($bodies)." HTML bodies ==\n";

    foreach ($bodies as $i => $body) {
        $audited++;

        $navy = [];

        foreach (['background:#0f172a', 'background: #0f172a'] as $needle) {
            if (($p = strpos($body, $needle)) !== false) {
                $navy[] = $p;
            }
        }

        $white = whiteOutsideAnchors($body);

        preg_match_all(
            '~<tr><td style="background:(#[0-9a-f]{6});padding:36px 40px;text-align:center;">~',
            $body,
            $heroes,
        );
        preg_match_all(
            '~<tr><td style="background:#ffffff;border-bottom:1px solid #eef2f7;padding:28px 40px;text-align:center;">~',
            $body,
            $headers,
        );

        $badTints = array_values(array_diff(array_map('strtolower', $heroes[1]), HERO_TINTS));

        $problems = [];
        $problems[] = count($navy) > 0 ? "navy band at bytes ".implode(',', $navy) : null;
        $problems[] = count($white) > 0 ? "white text outside anchors at bytes ".implode(',', $white) : null;
        $problems[] = count($badTints) > 0 ? 'non-tint hero: '.implode(',', $badTints) : null;
        $problems[] = count($heroes[0]) !== 1 ? 'hero bands: '.count($heroes[0]) : null;
        $problems[] = count($headers[0]) !== 1 ? 'header bands: '.count($headers[0]) : null;

        $problems = array_values(array_filter($problems));

        if ($problems === []) {
            echo sprintf("  body %d  OK  hero=%s header=white\n", $i + 1, strtolower($heroes[1][0]));
        } else {
            echo sprintf("  body %d  HIT: %s\n", $i + 1, implode('; ', $problems));
            $failures++;
        }
    }
}

// The migration body must satisfy the same checks (it is the seeder's twin).
$migration = migrationBody();
$audited++;
$mFails = 0;

if (strpos($migration, 'background:#0f172a') !== false) {
    echo "migration body  HIT: navy band\n";
    $mFails++;
}

$mWhite = whiteOutsideAnchors($migration);
if ($mWhite !== []) {
    echo 'migration body  HIT: white text outside anchors at bytes '.implode(',', $mWhite)."\n";
    $mFails++;
}

preg_match_all(
    '~<tr><td style="background:(#[0-9a-f]{6});padding:36px 40px;text-align:center;">~',
    $migration,
    $mHeroes,
);
if (count($mHeroes[0]) !== 1 || ! in_array(strtolower($mHeroes[1][0]), HERO_TINTS, true)) {
    echo 'migration body  HIT: hero band not a soft-light tint'."\n";
    $mFails++;
}

echo $mFails === 0
    ? 'migration body  OK  hero='.strtolower($mHeroes[1][0]).' header=white'."\n"
    : 'migration body  FAIL'."\n";
$failures += $mFails;

echo "\naudited: {$audited} HTML bodies (16 seeders + migration twin), {$failures} failure(s)\n";

if ($failures > 0) {
    echo "\nFAIL\n";
    exit(1);
}

echo "\nPASS: no dark-navy bands, no white text on bands, every hero band is a soft-light tint.\n";