<?php

use App\Support\Logging\RedactingProcessor;
use App\Support\Logging\RequestContextProcessor;
use Monolog\Handler\NullHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\SlackWebhookHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'app')),
            'ignore_exceptions' => false,
        ],

        /*
        |----------------------------------------------------------------------
        | Application domain channels (centralized logging)
        |----------------------------------------------------------------------
        |
        | One channel per business domain — see App\Support\Logging\LogChannel
        | for the routing and severity policy. The default `stack` routes to
        | `app` (LOG_STACK); call sites select a domain via AppLog instead of
        | naming a channel. Every channel rotates daily (Monolog
        | RotatingFileHandler), keeps LOG_RETENTION_DAYS days, and carries the
        | PsrLogMessageProcessor (placeholders), RequestContextProcessor
        | (request_id / actor / route / IP) and RedactingProcessor.
        |
        */

        'app' => [
            'driver' => 'monolog',
            'handler' => RotatingFileHandler::class,
            'handler_with' => [
                'filename' => storage_path('logs/app.log'),
                'maxFiles' => (int) env('LOG_RETENTION_DAYS', 30),
            ],
            'level' => env('LOG_LEVEL', 'debug'),
            'processors' => [
                PsrLogMessageProcessor::class,
                RequestContextProcessor::class,
                RedactingProcessor::class,
            ],
        ],

        'security' => [
            'driver' => 'monolog',
            'handler' => RotatingFileHandler::class,
            'handler_with' => [
                'filename' => storage_path('logs/security.log'),
                'maxFiles' => (int) env('LOG_RETENTION_DAYS', 30),
            ],
            'level' => env('LOG_LEVEL', 'debug'),
            'processors' => [
                PsrLogMessageProcessor::class,
                RequestContextProcessor::class,
                RedactingProcessor::class,
            ],
        ],

        'billing' => [
            'driver' => 'monolog',
            'handler' => RotatingFileHandler::class,
            'handler_with' => [
                'filename' => storage_path('logs/billing.log'),
                'maxFiles' => (int) env('LOG_RETENTION_DAYS', 30),
            ],
            'level' => env('LOG_LEVEL', 'debug'),
            'processors' => [
                PsrLogMessageProcessor::class,
                RequestContextProcessor::class,
                RedactingProcessor::class,
            ],
        ],

        'provisioning' => [
            'driver' => 'monolog',
            'handler' => RotatingFileHandler::class,
            'handler_with' => [
                'filename' => storage_path('logs/provisioning.log'),
                'maxFiles' => (int) env('LOG_RETENTION_DAYS', 30),
            ],
            'level' => env('LOG_LEVEL', 'debug'),
            'processors' => [
                PsrLogMessageProcessor::class,
                RequestContextProcessor::class,
                RedactingProcessor::class,
            ],
        ],

        'cron' => [
            'driver' => 'monolog',
            'handler' => RotatingFileHandler::class,
            'handler_with' => [
                'filename' => storage_path('logs/cron.log'),
                'maxFiles' => (int) env('LOG_RETENTION_DAYS', 30),
            ],
            'level' => env('LOG_LEVEL', 'debug'),
            'processors' => [
                PsrLogMessageProcessor::class,
                RequestContextProcessor::class,
                RedactingProcessor::class,
            ],
        ],

        'ops' => [
            'driver' => 'monolog',
            'handler' => RotatingFileHandler::class,
            'handler_with' => [
                'filename' => storage_path('logs/ops.log'),
                'maxFiles' => (int) env('LOG_RETENTION_DAYS', 30),
            ],
            'level' => env('LOG_LEVEL', 'debug'),
            'processors' => [
                PsrLogMessageProcessor::class,
                RequestContextProcessor::class,
                RedactingProcessor::class,
            ],
        ],

        /*
        | Alert seam: silent alerts.log by default; set LOG_ALERT_WEBHOOK and
        | the same records go to Slack instead. Deliberately dependency-free —
        | external error tracking plugs in here later without code churn.
        */
        'alert' => [
            'driver' => 'monolog',
            'handler' => env('LOG_ALERT_WEBHOOK') ? SlackWebhookHandler::class : RotatingFileHandler::class,
            'handler_with' => env('LOG_ALERT_WEBHOOK')
                ? [
                    'webhookUrl' => env('LOG_ALERT_WEBHOOK'),
                    'username' => env('LOG_SLACK_USERNAME', env('APP_NAME', 'Laravel')),
                    'iconEmoji' => env('LOG_ALERT_EMOJI', ':rotating_light:'),
                    'includeContextAndExtra' => true,
                ]
                : [
                    'filename' => storage_path('logs/alerts.log'),
                    'maxFiles' => (int) env('LOG_RETENTION_DAYS', 30),
                ],
            'level' => env('LOG_ALERT_LEVEL', 'error'),
            'processors' => [
                PsrLogMessageProcessor::class,
                RequestContextProcessor::class,
                RedactingProcessor::class,
            ],
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', env('APP_NAME', 'Laravel')),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
