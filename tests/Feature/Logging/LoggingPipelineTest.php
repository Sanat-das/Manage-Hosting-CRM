<?php

declare(strict_types=1);

namespace Tests\Feature\Logging;

use App\Support\Logging\AppLog;
use App\Support\Logging\LogChannel;
use App\Support\Logging\RedactingProcessor;
use App\Support\Logging\RequestContextProcessor;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\SlackWebhookHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * The centralized logging pipeline end to end: every domain channel resolves
 * to its own rotating file handler — never the silent emergency fallback to
 * laravel.log — records are redacted and placeholder-substituted before they
 * hit disk, and the correlation id round-trips through the middleware.
 */
class LoggingPipelineTest extends TestCase
{
    /**
     * @return array<string, array{LogChannel}>
     */
    public static function domainChannels(): array
    {
        $channels = [];

        foreach (LogChannel::cases() as $channel) {
            if ($channel === LogChannel::Alert) {
                continue; // own test: its driver depends on LOG_ALERT_WEBHOOK
            }

            $channels[$channel->value] = [$channel];
        }

        return $channels;
    }

    #[DataProvider('domainChannels')]
    public function test_every_domain_channel_resolves_to_its_own_rotating_file(LogChannel $channel): void
    {
        $logger = Log::channel($channel->value)->getLogger();

        $handlers = $logger->getHandlers();

        $this->assertCount(1, $handlers);
        $this->assertInstanceOf(RotatingFileHandler::class, $handlers[0]);
        $this->assertStringContainsString(
            "storage/logs/{$channel->value}",
            str_replace('\\', '/', $handlers[0]->getUrl()),
        );

        $processors = array_map(get_class(...), $logger->getProcessors());

        $this->assertContains(RequestContextProcessor::class, $processors);
        $this->assertContains(RedactingProcessor::class, $processors);
    }

    public function test_the_alert_channel_resolves_to_the_alerts_file_without_a_webhook(): void
    {
        $logger = Log::channel('alert')->getLogger();
        $handler = $logger->getHandlers()[0];

        $this->assertInstanceOf(RotatingFileHandler::class, $handler);
        $this->assertStringContainsString('storage/logs/alerts', str_replace('\\', '/', $handler->getUrl()));
    }

    public function test_the_loaded_alert_channel_config_keeps_the_central_processors(): void
    {
        $config = config('logging.channels.alert');

        $this->assertSame('monolog', $config['driver']);
        $this->assertContains(RequestContextProcessor::class, $config['processors']);
        $this->assertContains(RedactingProcessor::class, $config['processors']);
        $this->assertContains($config['handler'], [RotatingFileHandler::class, SlackWebhookHandler::class]);
    }

    public function test_the_slack_alert_branch_keeps_redaction_and_context_processors(): void
    {
        config([
            'logging.channels.alert.handler' => SlackWebhookHandler::class,
            'logging.channels.alert.handler_with' => [
                'webhookUrl' => 'https://hooks.example.test/services/T000/B000/XXXX',
            ],
        ]);
        Log::forgetChannel('alert');

        try {
            $logger = Log::channel('alert')->getLogger();

            $this->assertInstanceOf(SlackWebhookHandler::class, $logger->getHandlers()[0]);

            $processors = array_map(get_class(...), $logger->getProcessors());

            $this->assertContains(RequestContextProcessor::class, $processors);
            $this->assertContains(RedactingProcessor::class, $processors);
        } finally {
            Log::forgetChannel('alert');
        }
    }

    public function test_throwables_in_context_are_redacted_not_bypassed(): void
    {
        $record = new LogRecord(
            new DateTimeImmutable,
            'billing',
            Level::Error,
            'payment failed',
            ['exception' => new RuntimeException('gateway refused https://user:ghp_ABCDEFGHIJKLMNOP@github.com/o/r.git')],
        );

        $processed = (new RedactingProcessor)($record);

        $this->assertIsArray($processed->context['exception']);
        $this->assertSame(RuntimeException::class, $processed->context['exception']['class']);
        $this->assertStringNotContainsString('ghp_', $processed->context['exception']['message']);
        $this->assertStringContainsString('https://***@github.com', $processed->context['exception']['message']);
    }

    public function test_records_are_redacted_and_placeholders_replaced_before_hitting_disk(): void
    {
        // Extension-less base: Monolog inserts the date before an extension
        // when one exists, so no extension keeps the expected name exact.
        $base = sys_get_temp_dir().'/mh-log-'.uniqid();

        try {
            config(['logging.channels.security.handler_with.filename' => $base]);
            Log::forgetChannel('security');

            AppLog::security()->warning('token check {tag}', [
                'tag' => 'redact',
                'token' => 'ghp_ABCDEFGHIJKLMNOP',
                'nested' => ['url' => 'https://user:ghp_ZZZZZZZZZZZZZZZZ@github.com/o/r.git'],
            ]);

            $written = $base.'-'.date('Y-m-d');

            $this->assertFileExists($written);

            $contents = (string) file_get_contents($written);

            $this->assertStringContainsString('token check redact', $contents);
            $this->assertStringContainsString('"token":"***"', $contents);
            $this->assertStringContainsString('https://***@github.com/o/r.git', $contents);
            $this->assertStringNotContainsString('ghp_', $contents);

            @unlink($written);
        } finally {
            Log::forgetChannel('security');
        }
    }

    public function test_a_request_correlation_id_is_assigned_and_echoed_back(): void
    {
        $this->get('/up')->assertHeader('X-Request-Id');

        $this->get('/up', ['X-Request-Id' => 'trace-abc123'])
            ->assertHeader('X-Request-Id', 'trace-abc123');
    }
}
