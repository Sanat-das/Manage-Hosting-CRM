<?php

/**
 * Proves the `chat_transcript` body in EmailTemplateSeeder and in
 * 2026_09_15_000005_backfill_chat_transcript_email_template are byte-identical.
 *
 * Both are PHP nowdocs; this extracts the text between the opening
 * `<<<'BODY'` line and its closing `BODY,` / `BODY;` line (the closing
 * identifier itself is not part of the string) and compares them byte for byte.
 *
 * Run: php .opencode/reports/2026-09-29-email-template-redesign/chat-body-identity.php
 */

$root = __DIR__.'/../../..';

$seederPath = $root.'/database/seeders/EmailTemplateSeeder.php';
$migrationPath = $root.'/database/migrations/2026_09_15_000005_backfill_chat_transcript_email_template.php';

/**
 * @return string the nowdoc contents (without the closing identifier line)
 */
function extractNowdoc(string $path, string $anchor, string $closingLine): string
{
    $source = file_get_contents($path);

    if ($source === false) {
        fwrite(STDERR, "Cannot read {$path}\n");
        exit(2);
    }

    $anchorPos = strpos($source, $anchor);

    if ($anchorPos === false) {
        fwrite(STDERR, "Anchor '{$anchor}' not found in {$path}\n");
        exit(2);
    }

    $open = strpos($source, "<<<'BODY'", $anchorPos);

    if ($open === false) {
        fwrite(STDERR, "No BODY nowdoc after anchor in {$path}\n");
        exit(2);
    }

    $contentStart = strpos($source, "\n", $open) + 1;
    $close = strpos($source, "\n".$closingLine, $contentStart);

    if ($close === false) {
        fwrite(STDERR, "No closing '{$closingLine}' line after the nowdoc in {$path}\n");
        exit(2);
    }

    return substr($source, $contentStart, $close - $contentStart);
}

$seederBody = extractNowdoc($seederPath, "'chat_transcript'", 'BODY,');
$migrationBody = extractNowdoc($migrationPath, "<<<'BODY'", 'BODY;');

echo 'seeder  chat_transcript body: '.strlen($seederBody).' bytes sha256='.hash('sha256', $seederBody)."\n";
echo 'migration chat_transcript body: '.strlen($migrationBody).' bytes sha256='.hash('sha256', $migrationBody)."\n";

if ($seederBody !== $migrationBody) {
    $limit = min(strlen($seederBody), strlen($migrationBody));
    $offset = $limit;

    for ($i = 0; $i < $limit; $i++) {
        if ($seederBody[$i] !== $migrationBody[$i]) {
            $offset = $i;
            break;
        }
    }

    echo "\nFAIL: bodies differ";
    echo strlen($seederBody) !== strlen($migrationBody)
        ? " (length ".strlen($seederBody).' vs '.strlen($migrationBody).')'
        : ' (same length)';
    echo ", first difference at byte {$offset}\n";
    echo '  seeder   : '.var_export(substr($seederBody, max(0, $offset - 30), 80), true)."\n";
    echo '  migration: '.var_export(substr($migrationBody, max(0, $offset - 30), 80), true)."\n";

    exit(1);
}

echo "\nPASS: chat_transcript bodies are byte-identical.\n";
