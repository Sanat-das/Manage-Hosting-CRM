<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Attaches RequestContext (request_id, actor, route, IP) to every record.
 * Registered on the domain channels in config/logging.php.
 */
final class RequestContextProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        try {
            if (! app()->bound(RequestContext::class)) {
                return $record;
            }

            $extra = $record->extra;

            foreach (app(RequestContext::class)->toLogContext() as $key => $value) {
                $extra[$key] ??= $value;
            }

            return $record->with(extra: $extra);
        } catch (\Throwable) {
            // Logging must keep working while the application is half-booted
            // (e.g. an update replacing code underneath a live process).
        }

        return $record;
    }
}
