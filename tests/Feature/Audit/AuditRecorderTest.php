<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\DomainSyncLog;
use App\Models\ModuleLog;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Logging\RequestContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * The recorder contract: guaranteed timestamps, request correlation,
 * redaction, and failure escalation — the properties every stream relies on.
 */
class AuditRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_records_always_carry_created_at_and_the_event_key(): void
    {
        $customer = $this->makeCustomer();

        app(AuditRecorder::class)->activity(AuditEvent::OrderCreated, $customer, ['order_id' => 7], 'Order created');

        $row = ActivityLog::query()->latest('id')->first();

        $this->assertNotNull($row);
        $this->assertSame('order_created', $row->action);
        $this->assertSame('order_created', $row->event);
        $this->assertSame($customer->id, $row->customer_id);
        $this->assertNotNull($row->created_at);
    }

    public function test_request_context_is_stamped_into_rows(): void
    {
        app(RequestContext::class)->capture(Request::create('/x', 'GET', [], [], [], [
            'HTTP_X_REQUEST_ID' => 'trace-audit-1',
        ]));

        app(AuditRecorder::class)->entityRef('chat.channel_created', 'conversation', 42);

        $row = AuditLog::query()->latest('id')->first();

        $this->assertNotNull($row);
        $this->assertSame('trace-audit-1', $row->request_id);
        $this->assertNotNull($row->created_at);
    }

    public function test_secrets_in_metadata_are_redacted(): void
    {
        app(AuditRecorder::class)->activity(AuditEvent::SettingsUpdated, null, [
            'note' => 'see https://user:ghp_ABCDEFGHIJKLMNOP@github.com/o/r.git',
            'nested' => ['token' => 'token ghp_ABCDEFGHIJKLMNOP end'],
        ]);

        $row = ActivityLog::query()->latest('id')->first();

        $this->assertNotNull($row);
        $this->assertStringNotContainsString('ghp_', (string) json_encode($row->metadata));
    }

    public function test_a_failed_write_is_escalated_to_the_security_log(): void
    {
        Log::spy();
        Log::shouldReceive('channel')
            ->with('security')
            ->andReturn($logger = Mockery::mock(LoggerInterface::class));
        $logger->shouldReceive('withContext')->andReturnSelf();
        $logger->shouldReceive('error')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'Audit record failed'));

        ActivityLog::saving(static function (): void {
            throw new RuntimeException('simulated insert failure');
        });

        try {
            app(AuditRecorder::class)->activity(AuditEvent::SettingsUpdated);
        } finally {
            ActivityLog::flushEventListeners();
        }
    }

    public function test_module_and_domain_sync_streams_stamp_created_at_and_redact_errors(): void
    {
        $moduleId = DB::table('modules')->insertGetId([
            'slug' => 'audit-smoke',
            'name' => 'Audit Smoke',
            'version' => '1.0.0',
            'status' => 'installed',
            'provider' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(AuditRecorder::class)->module($moduleId, 'smoke.event', null, 'info');
        app(AuditRecorder::class)->domainSync('registrar', 'sync', 'error', null, 'boom https://u:ghp_ABCDEFGHIJKLMNOP@h/r');

        $this->assertNotNull(ModuleLog::query()->latest('id')->first()?->created_at);

        $sync = DomainSyncLog::query()->latest('id')->first();

        $this->assertNotNull($sync);
        $this->assertNotNull($sync->created_at);
        $this->assertStringNotContainsString('ghp_', (string) $sync->error);
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Test Corp',
            'status' => 'active',
        ]);
    }
}
