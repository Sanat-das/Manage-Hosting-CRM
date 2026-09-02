<?php
// TEMPORARY diagnostic — delete after use.
if (($_GET['t'] ?? '') !== 'z9x1q7') {
    http_response_code(404);
    exit;
}

header('Content-Type: application/x-ndjson');
header('Cache-Control: no-store');
header('X-Accel-Buffering: no');

for ($i = 0; $i < 5; $i++) {
    echo json_encode(['i' => $i, 'at' => round(microtime(true), 3)])."\n";
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    @flush();
    sleep(1);
}
