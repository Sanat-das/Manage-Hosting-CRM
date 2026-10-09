<?php

declare(strict_types=1);

namespace Tests\Feature\Logging;

use App\Support\Logging\RequestContext;
use App\Support\Logging\RequestContextProcessor;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

/**
 * Correlation data capture and its Monolog processor: well-formed inbound
 * ids are forwarded, malformed ones replaced, and caller-provided extra
 * fields always win over the processor's defaults.
 */
class RequestContextTest extends TestCase
{
    private function record(array $extra = []): LogRecord
    {
        return new LogRecord(
            new DateTimeImmutable,
            'app',
            Level::Warning,
            'msg',
            extra: $extra,
        );
    }

    public function test_capture_reads_a_well_formed_inbound_request_id(): void
    {
        $context = new RequestContext;
        $context->capture(Request::create('/admin/orders', 'GET', [], [], [], [
            'HTTP_X_REQUEST_ID' => 'trace-777',
        ]));

        $this->assertSame('trace-777', $context->requestId());
    }

    public function test_capture_replaces_a_malformed_inbound_request_id(): void
    {
        $context = new RequestContext;
        $context->capture(Request::create('/x', 'GET', [], [], [], [
            'HTTP_X_REQUEST_ID' => "bad\nvalue",
        ]));

        $this->assertNotNull($context->requestId());
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', (string) $context->requestId());
    }

    public function test_processor_attaches_request_context_to_every_record(): void
    {
        $context = app(RequestContext::class);
        $context->capture(Request::create('/admin/orders', 'GET', [], [], [], [
            'HTTP_X_REQUEST_ID' => 'trace-777',
        ]));

        $record = (new RequestContextProcessor)($this->record());

        $this->assertSame('trace-777', $record->extra['request_id']);
        $this->assertSame('admin/orders', $record->extra['route']);
        $this->assertSame('127.0.0.1', $record->extra['ip']);
        $this->assertArrayHasKey('cli', $record->extra);
    }

    public function test_processor_never_overwrites_caller_provided_extra(): void
    {
        $context = app(RequestContext::class);
        $context->capture(Request::create('/x', 'GET', [], [], [], [
            'HTTP_X_REQUEST_ID' => 'trace-777',
        ]));

        $record = (new RequestContextProcessor)($this->record(['request_id' => 'caller-wins']));

        $this->assertSame('caller-wins', $record->extra['request_id']);
    }
}
