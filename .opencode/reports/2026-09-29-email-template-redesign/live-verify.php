<?php

declare(strict_types=1);

use App\Models\EmailTemplate;

$rows = EmailTemplate::orderBy('id')->get();

foreach ($rows as $t) {
    $body = (string) $t->body;
    $head = mb_substr(str_replace(["\n", "\r"], ' ', $body), 0, 90);
    $isLight = str_contains($body, '#f1f5f9') && (str_contains($body, '#eff6ff') || str_contains($body, '#fef2f2') || str_contains($body, '#f0fdf4') || str_contains($body, '#fffbeb') || str_contains($body, '#f8fafc'));
    $hasNavy = str_contains($body, 'background:#0f172a');
    echo sprintf(
        "%d | %s | %s | light=%s navy=%s | %s\n",
        $t->id,
        $t->name,
        $t->status,
        $isLight ? 'yes' : 'no',
        $hasNavy ? 'YES' : 'no',
        $head,
    );
}