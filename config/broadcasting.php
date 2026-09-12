<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | Reverb is the transport for the Slack-like chat. It is first-party and
    | self-hosted, which matters here: this application ships as an installable
    | panel, and a SaaS websocket account is not something every install can be
    | expected to have.
    |
    | `log` remains available for local work with no Reverb process running —
    | events are written to the log instead of the wire. Do NOT leave it as the
    | default: chat delivery silently stops working and nothing errors.
    |
    | Supported: "reverb", "log", "null"
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Only the connections this application actually supports are defined. The
    | pusher/ably/redis samples from the framework's stub are deliberately
    | absent — an unused connection is a configuration surface that looks
    | supported and is not.
    |
    */

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                // The host the *application* uses to reach the Reverb server
                // when publishing. Behind the IIS reverse proxy this stays
                // 127.0.0.1 while the browser dials the public hostname.
                'host' => env('REVERB_HOST', '127.0.0.1'),
                'port' => env('REVERB_PORT', 8081),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            'client_options' => [
                // Guzzle options. Keep the publish timeout short: chat events
                // broadcast synchronously inside the request (see the Chat
                // events' ShouldBroadcastNow), so a hung Reverb must not hold
                // a web request open.
                'timeout' => 5,
                'connect_timeout' => 2,
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
