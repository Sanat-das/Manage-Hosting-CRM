<?php

/**
 * verify2 style audit — gate 5 + falsify C/E.
 */

$root = __DIR__.'/../../../..';

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$allowedBand = ['#ffffff', '#f1f5f9', '#f8fafc', '#eff6ff', '#fef2f2', '#f0fdf4', '#fffbeb'];

$failures = 0;

function auditBody(string $label, string $body): void
{
    global $allowedBand, $failures;

    if (preg_match('/background:#0f172a/i', $body)) {
        echo "  {$label}: FAIL background:#0f172a present\n";
        $failures++;
    }

    preg_match_all('/background:#([0-9a-fA-F]{3,8})/i', $body, $m, PREG_OFFSET_CAPTURE);
    $bad = [];
    foreach ($m[1] as $i => $hexMatch) {
        $offset = $hexMatch[1] - 12;
        if ($offset < 0) { $offset = 0; }
        $before = substr($body, 0, $offset + 2);
        $lastA = strrpos($before, '<a');
        $lastAEnd = strrpos($before, '</a>');
        $inAnchor = $lastA !== false && ($lastAEnd === false || $lastAEnd < $lastA);
        $hex = strtolower($hexMatch[0]);
        if (! $inAnchor && ! in_array('#'.$hex, $allowedBand, true)) {
            $bad[] = '#'.$hex;
        }
    }
    if ($bad !== []) {
        echo '  '.$label.': FAIL non-palette band background(s): '.implode(', ', array_unique($bad))."\n";
        $failures++;
    }

    if (! preg_match('/<td\s[^>]*style="[^"]*background:#ffffff[^"]*"[^>]*>\s*<img src="\{\{app_logo_url\}\}"[^>]*>(.*?)<\/td>/si', $body, $h)) {
        if (! preg_match('/<td\s[^>]*>(\s*<img src="\{\{app_logo_url\}\}"[^>]*>.*?)<\/td>/si', $body, $h)) {
            echo "  {$label}: FAIL no header band with {{app_logo_url}} found\n";
            $failures++;

            return;
        }
        echo "  {$label}: FAIL header band td is not background:#ffffff\n";
        $failures++;

        return;
    }
    if (preg_match('/color:#(fff|ffffff)/i', $h[1])) {
        echo "  {$label}: FAIL white text inside white header band\n";
        $failures++;
    }
    if (! preg_match('/width="\d+"[^>]*max-width:\d+px/i', $h[0]) && ! preg_match('/max-width:\d+px[^>]*width="\d+"/i', $h[0])) {
        echo "  {$label}: FAIL logo img lacks width/max-width constraint\n";
        $failures++;
    }
}

$bodies = [];

$ref = new ReflectionClass(Database\Seeders\EmailTemplateSeeder::class);
foreach ($ref->getReflectionConstant('TEMPLATES')->getValue() as $t) {
    $bodies['canonical/'.$t['name']] = (string) $t['body'];
}

$ref = new ReflectionClass(Database\Seeders\Demo\NotificationEmailSeeder::class);
foreach ($ref->getReflectionConstant('TEMPLATES')->getValue() as $t) {
    $bodies['demo/'.$t['name']] = (string) $t['body'];
}

// Migration twin
function extractNowdoc(string $path, string $anchor, string $closingLine): string
{
    $source = file_get_contents($path);
    $anchorPos = strpos($source, $anchor);
    $open = strpos($source, "<<<'BODY'", $anchorPos);
    $contentStart = strpos($source, "\n", $open) + 1;
    $close = strpos($source, "\n".$closingLine, $contentStart);

    return substr($source, $contentStart, $close - $contentStart);
}
$bodies['migration/chat_transcript'] = extractNowdoc(
    __DIR__.'/../../../../database/migrations/2026_09_15_000005_backfill_chat_transcript_email_template.php',
    "<<<'BODY'",
    'BODY;',
);

echo 'bodies audited: '.count($bodies)."\n";

foreach ($bodies as $label => $html) {
    if (! str_contains($html, '<table') && ! str_contains($html, '<!doctype')) {
        echo "  {$label}: SKIP (plain text ticket body)\n";
        continue;
    }
    auditBody($label, $html);
}

echo "\n";
if ($failures > 0) {
    echo "FAIL: {$failures} style-audit violation(s).\n";
    exit(1);
}
echo "PASS: all HTML bodies clean — no dark band backgrounds, headers white,\n";
echo "      logo constrained, no white-on-white in header.\n";
