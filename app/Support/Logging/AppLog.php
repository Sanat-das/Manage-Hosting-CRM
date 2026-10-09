<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * The single entry point for application file logging.
 *
 * Call sites name a business domain, never a channel string or a file:
 *
 *     AppLog::provisioning(['order_id' => $order->id])
 *         ->error('Provisioning module threw', ['error' => $e->getMessage()]);
 *
 * Channel selection and record enrichment live in config/logging.php: every
 * domain channel carries RequestContextProcessor (request_id, actor, route,
 * IP) and RedactingProcessor (SecretRedactor over message/context/extra), so
 * no call site manages correlation ids or redaction itself.
 */
final class AppLog
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function channel(LogChannel $channel, array $context = []): LoggerInterface
    {
        return Log::channel($channel->value)->withContext($context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function app(array $context = []): LoggerInterface
    {
        return self::channel(LogChannel::App, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function security(array $context = []): LoggerInterface
    {
        return self::channel(LogChannel::Security, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function billing(array $context = []): LoggerInterface
    {
        return self::channel(LogChannel::Billing, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function provisioning(array $context = []): LoggerInterface
    {
        return self::channel(LogChannel::Provisioning, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function cron(array $context = []): LoggerInterface
    {
        return self::channel(LogChannel::Cron, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function ops(array $context = []): LoggerInterface
    {
        return self::channel(LogChannel::Ops, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function alert(array $context = []): LoggerInterface
    {
        return self::channel(LogChannel::Alert, $context);
    }
}
