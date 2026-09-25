<?php

declare(strict_types=1);

// rdp-console module — browser console gateway (guacamole-lite sidecar).
//
// Canonical home for the gateway settings: GuacamoleLiteDriver reads
// config('rdp-console.secret'), config('rdp-console.ws_url') and
// config('rdp-console.recording_path') at mint time, and the module's
// DEPLOYMENT.md documents the GUACAMOLE_* variables below. Keep the env
// names and defaults in sync with the sidecar's own configuration.
return [
    // Websocket endpoint of the Node sidecar running guacamole-lite.
    'ws_url' => env('GUACAMOLE_WS_URL', 'ws://127.0.0.1:8080/'),

    // Shared secret used to encrypt connection tokens (min 16 chars).
    // Must be byte-identical to the sidecar's GUACAMOLE_SECRET.
    // Generate with: openssl rand -base64 32
    'secret' => env('GUACAMOLE_SECRET'),

    // Directory where guacd writes session recordings; blank disables recording.
    'recording_path' => env('GUACAMOLE_RECORDING_PATH'),
];
