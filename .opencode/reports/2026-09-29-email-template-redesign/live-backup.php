<?php

declare(strict_types=1);

use App\Models\EmailTemplate;

$backup = 'C:/Users/Administrator/Local Sites/managehosting/app/.opencode/reports/2026-09-29-email-template-redesign/live-db-backup.json';

$rows = EmailTemplate::orderBy('id')->get();

file_put_contents(
    $backup,
    $rows->map(fn ($t) => [
        'id' => $t->id,
        'name' => $t->name,
        'subject' => $t->subject,
        'status' => $t->status,
        'body' => $t->body,
    ])->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
);

echo 'Backup written: '.$backup.' ('.count($rows)." rows)\n\n";

foreach ($rows as $t) {
    $head = mb_substr(str_replace(["\n", "\r"], ' ', (string) $t->body), 0, 90);
    echo sprintf("%d | %s | %s | %s\n", $t->id, $t->name, $t->status, $head);
}