<?php

namespace Tests\Feature;

use App\Jobs\SendEmail;
use App\Models\EmailLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Hardened email-log pipeline: a per-dispatch log_key makes retries idempotent,
 * and a stored payload lets a logged email be resent.
 */
class EmailLogHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_retry_of_the_same_dispatch_reuses_the_row_and_counts_attempts(): void
    {
        Mail::fake();

        $job = new SendEmail('client@example.test', 'Subject', 'Body');

        // The queue re-runs handle() on the SAME serialized payload, i.e. the
        // same instance, so this is genuine retry semantics.
        $job->handle();
        $job->handle();

        $this->assertSame(1, EmailLog::count());

        $log = EmailLog::sole();
        $this->assertSame(2, (int) $log->attempts);
        $this->assertSame($job->logKey, $log->log_key);
        $this->assertSame('sent', $log->status);
    }

    public function test_two_distinct_dispatches_with_identical_content_create_two_rows(): void
    {
        Mail::fake();

        $first = new SendEmail('client@example.test', 'Subject', 'Body');
        $second = new SendEmail('client@example.test', 'Subject', 'Body');

        $this->assertNotSame($first->logKey, $second->logKey);

        $first->handle();
        $second->handle();

        $this->assertSame(2, EmailLog::count());
    }

    public function test_resend_queues_a_new_send_from_the_stored_payload(): void
    {
        Mail::fake();

        (new SendEmail(
            'client@example.test',
            'Subject',
            'Body',
            'from@example.test',
            [],
            [],
            [],
            null,
            [],
            null,
            'ticket_reply',
            null,
        ))->handle();

        $log = EmailLog::sole();
        $this->assertNotNull($log->payload);

        Queue::fake();

        $response = $this->actingAs($this->adminWithEmailPermissions())
            ->post(route('admin.email-logs.resend', $log));

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Resend queued.');

        Queue::assertPushed(SendEmail::class, fn (SendEmail $job) => $job->toEmail === 'client@example.test'
            && $job->subject === 'Subject'
            && $job->body === 'Body'
            && $job->fromEmail === 'from@example.test'
            && $job->templateName === 'ticket_reply');
    }

    public function test_resend_without_a_payload_reports_an_error(): void
    {
        $log = new EmailLog;
        $log->forceFill([
            'to_email' => 'client@example.test',
            'subject' => 'Old',
            'body' => 'Old body',
            'status' => 'sent',
        ])->save();

        $this->assertNull($log->fresh()->payload);

        Queue::fake();

        $response = $this->actingAs($this->adminWithEmailPermissions())
            ->from(route('admin.email-logs.index'))
            ->post(route('admin.email-logs.resend', $log));

        $response->assertRedirect(route('admin.email-logs.index'));
        $response->assertSessionHas('error', 'This row predates resend support — no payload stored.');
        Queue::assertNothingPushed();
    }

    public function test_a_failed_retry_appends_the_attempt_number_and_keeps_one_row(): void
    {
        Mail::shouldReceive('raw')->andThrow(new \RuntimeException('SMTP down'));

        $job = new SendEmail('client@example.test', 'Subject', 'Body');

        try {
            $job->handle();
        } catch (\RuntimeException) {
            // The job rethrows so the queue can retry.
        }

        // Simulate the queue worker's second attempt on the same job.
        $job->job = new class
        {
            public function attempts(): int
            {
                return 2;
            }
        };

        try {
            $job->handle();
        } catch (\RuntimeException) {
        }

        $this->assertSame(1, EmailLog::count());

        $log = EmailLog::sole();
        $this->assertSame(2, (int) $log->attempts);
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('SMTP down', (string) $log->error);
        $this->assertStringContainsString('[attempt 2]', (string) $log->error);
    }

    public function test_the_logged_body_and_payload_are_redacted(): void
    {
        Mail::fake();

        (new SendEmail('client@example.test', 'Subject', 'token ghp_ABCDEFGHIJKLMNOP inside'))->handle();

        $log = EmailLog::sole();

        $this->assertStringNotContainsString('ghp_', (string) $log->body);
        $this->assertStringContainsString('***', (string) $log->body);
        $this->assertStringNotContainsString('ghp_', (string) json_encode($log->payload));
    }

    private function adminWithEmailPermissions(): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        foreach (['email.view', 'email.manage'] as $permissionName) {
            $permission = Permission::firstOrCreate(
                ['name' => $permissionName],
                ['label' => ucwords(str_replace('.', ' ', $permissionName))]
            );
            $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->assignRole('admin');

        return $user;
    }
}
