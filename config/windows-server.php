<?php

declare(strict_types=1);

// Windows Server (RDP) module — browser console gateway (guacamole-lite sidecar).
return [
    // Websocket endpoint of the Node sidecar running guacamole-lite.
    'ws_url' => env('GUACAMOLE_WS_URL', 'ws://127.0.0.1:8080/'),

    // Shared secret used to encrypt connection tokens (min 16 chars).
    // Generate with: openssl rand -base64 32
    'secret' => env('GUACAMOLE_SECRET'),

    // Directory where guacd writes session recordings; blank disables recording.
    'recording_path' => env('GUACAMOLE_RECORDING_PATH'),
];
