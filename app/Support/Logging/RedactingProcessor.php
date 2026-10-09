<?php

declare(strict_types=1);

namespace App\Support\Logging;

use App\Support\SecretRedactor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * Runs message, context and extra through SecretRedactor so a credential can
 * never reach a log file regardless of which call site wrote it.
 *
 * Throwables in context are rewritten to a redacted [class, message, file]
 * array — the same data the formatter would otherwise stringify itself, but
 * with the message redacted. Other objects pass through untouched and rely on
 * the formatter's own rendering; do not pass objects that carry credentials.
 * Coverage is exactly SecretRedactor's pattern list (see its docblock for the
 * not-covered secret formats).
 */
final class RedactingProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: SecretRedactor::redact($record->message),
            context: $this->redactValue($record->context),
            extra: $this->redactValue($record->extra),
        );
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function redactValue(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_string($item)) {
                $value[$key] = SecretRedactor::redact($item);
            } elseif (is_array($item)) {
                $value[$key] = $this->redactValue($item);
            } elseif ($item instanceof Throwable) {
                // An exception object would bypass this processor and be
                // stringified later by the formatter with its message intact.
                // Replace it with the data the formatter would print, redacted.
                $value[$key] = [
                    'class' => $item::class,
                    'message' => SecretRedactor::redact($item->getMessage()),
                    'file' => $item->getFile().':'.$item->getLine(),
                ];
            }
        }

        return $value;
    }
}
