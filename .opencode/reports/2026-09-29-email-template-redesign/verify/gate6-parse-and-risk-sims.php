<?php
/**
 * Gate 6: DOMDocument parse of every HTML body in both seeders.
 * Risks A/B/E: render simulations.
 */
$root = __DIR__.'/../../../../';
require $root.'/vendor/autoload.php';

function extractTemplates(string $path, string $class): array {
    require_once $path;
    $rc = new ReflectionClass($class);
    return $rc->getReflectionConstant('TEMPLATES')->getValue();
}

$seeder = extractTemplates($root.'/database/seeders/EmailTemplateSeeder.php', Database\Seeders\EmailTemplateSeeder::class);
$demo   = extractTemplates($root.'/database/seeders/Demo/NotificationEmailSeeder.php', Database\Seeders\Demo\NotificationEmailSeeder::class);

libxml_use_internal_errors(true);
$parseIssues = 0;
foreach (['canonical'=>$seeder, 'demo'=>$demo] as $group => $templates) {
    foreach ($templates as $t) {
        $body = $t['body'];
        $isHtml = str_contains($body, '<table') || str_contains($body, '<html') || str_contains($body, '<div') || stripos($body, '<!doctype') !== false;
        if (! $isHtml) { echo sprintf("%-7s %-26s TEXT\n", $group, $t['name']); continue; }
        $dom = new DOMDocument();
        $ok = $dom->loadHTML($body);
        $errors = libxml_get_errors();
        if (! $ok || $errors !== []) {
            $parseIssues++;
            echo sprintf("%-7s %-26s PARSE ISSUES (%d):\n", $group, $t['name'], count($errors));
            foreach (array_slice($errors, 0, 5) as $e) {
                echo '    line '.$e->line.': '.trim($e->message)."\n";
            }
            // check whether errors are only from the trailing Available-variables footer
            if (count($errors) === 1 && str_contains($body, "---\nAvailable variables:")) {
                $onlyFooter = true;
            }
        } else {
            echo sprintf("%-7s %-26s HTML OK\n", $group, $t['name']);
        }
        libxml_clear_errors();
        // Risk E: img width + max-width
        if ($isHtml) {
            preg_match_all('/<img[^>]+>/', $body, $imgs);
            foreach ($imgs[0] as $i => $img) {
                $hasWidth = str_contains($img, 'width="140"') && str_contains($img, 'max-width:140px');
                if (! $hasWidth) { echo "    WARN img#$i lacks width/max-width: ".substr($img,0,120)."\n"; }
            }
        }
    }
}
echo "PARSE ISSUES total: $parseIssues\n";

// ── Risk A: service_activated with empty credentials block ──
echo "\n== Risk A: service_activated, credentialsBlock() == '' (compute VM path) ==\n";
foreach ($seeder as $t) {
    if ($t['name'] !== 'service_activated') continue;
    $heading = 'Your service credentials';
    $pos = strpos($t['body'], $heading);
    $segment = substr($t['body'], $pos - 45, 220);
    echo "HEADING present at byte $pos, followed by placeholder:\n$segment\n";
    $rendered = str_replace('{{service_credentials}}', '', $t['body']);
    $rendered = preg_replace('/\{\{[a-z_]+\}\}/', 'VALUE', $rendered);
    $after = substr($rendered, $pos, 260);
    echo "RENDERED (credentials blank, other vars= VALUE):\n$after\n";
}

// ── Risk B: order_confirmation with empty domain and invoice ──
echo "\n== Risk B: order_confirmation, domain='' invoice_no='' ==";
foreach ($seeder as $t) {
    if ($t['name'] !== 'order_confirmation') continue;
    $rendered = str_replace(
        ['{{domain_name}}', '{{invoice_no}}'],
        ['', ''],
        $t['body']
    );
    $rendered = preg_replace('/\{\{[a-z_]+\}\}/', 'VALUE', $rendered);
    // show the details table region
    $start = strpos($rendered, 'Order No.');
    echo "\n".substr($rendered, $start - 60, 1400)."\n";
}
