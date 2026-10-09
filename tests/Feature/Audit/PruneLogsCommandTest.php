<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\Customer;
use App\Models\User;
use App\Settings\LogRetentionSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `logs:prune` enforces the per-stream retention windows from config/audit.php:
 * each stream is pruned at its own window, newer rows survive, and a stream
 * with a null window (marketing_consent_log) is never touched.
 */
class PruneLogsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_each_stream_at_its_configured_window(): void
    {
        $old = now()->subDays(2000);
        $new = now();
        $customerId = $this->makeCustomer()->id;

        DB::table('activity_log')->insert([
            ['action' => 'old', 'created_at' => $old],
            ['action' => 'new', 'created_at' => $new],
        ]);

        DB::table('audit_log')->insert([
            ['action' => 'old', 'entity_type' => 't', 'created_at' => $old],
            ['action' => 'new', 'entity_type' => 't', 'created_at' => $new],
        ]);

        DB::table('emails')->insert([
            ['to_email' => 'old@example.com', 'subject' => 'old', 'body' => 'x', 'status' => 'sent', 'created_at' => $old],
            ['to_email' => 'new@example.com', 'subject' => 'new', 'body' => 'x', 'status' => 'sent', 'created_at' => $new],
        ]);

        DB::table('module_log')->insert([
            ['event' => 'old', 'status' => 'info', 'created_at' => $old],
            ['event' => 'new', 'status' => 'info', 'created_at' => $new],
        ]);

        DB::table('domain_sync_log')->insert([
            ['provider' => 'old', 'operation' => 'sync', 'status' => 'success', 'created_at' => $old],
            ['provider' => 'new', 'operation' => 'sync', 'status' => 'success', 'created_at' => $new],
        ]);

        DB::table('domain_search_logs')->insert([
            ['domain_name' => 'old.example.com', 'created_at' => $old],
            ['domain_name' => 'new.example.com', 'created_at' => $new],
        ]);

        DB::table('invoice_pdf_log')->insert([
            ['file_name' => 'old.pdf', 'created_at' => $old, 'updated_at' => $old],
            ['file_name' => 'new.pdf', 'created_at' => $new, 'updated_at' => $new],
        ]);

        DB::table('marketing_consent_log')->insert([
            ['customer_id' => $customerId, 'source' => 'old', 'created_at' => $old, 'updated_at' => $old],
            ['customer_id' => $customerId, 'source' => 'new', 'created_at' => $new, 'updated_at' => $new],
        ]);

        $this->artisan('logs:prune')->assertExitCode(0);

        // Windows: activity_log 180 · audit_log 365 · emails 90 · module_log 90
        // · domain_sync_log 90 · domain_search_logs 30 · invoice_pdf_log 365.
        $this->assertDatabaseMissing('activity_log', ['action' => 'old']);
        $this->assertDatabaseHas('activity_log', ['action' => 'new']);

        $this->assertDatabaseMissing('audit_log', ['action' => 'old']);
        $this->assertDatabaseHas('audit_log', ['action' => 'new']);

        $this->assertDatabaseMissing('emails', ['to_email' => 'old@example.com']);
        $this->assertDatabaseHas('emails', ['to_email' => 'new@example.com']);

        $this->assertDatabaseMissing('module_log', ['event' => 'old']);
        $this->assertDatabaseHas('module_log', ['event' => 'new']);

        $this->assertDatabaseMissing('domain_sync_log', ['provider' => 'old']);
        $this->assertDatabaseHas('domain_sync_log', ['provider' => 'new']);

        $this->assertDatabaseMissing('domain_search_logs', ['domain_name' => 'old.example.com']);
        $this->assertDatabaseHas('domain_search_logs', ['domain_name' => 'new.example.com']);

        $this->assertDatabaseMissing('invoice_pdf_log', ['file_name' => 'old.pdf']);
        $this->assertDatabaseHas('invoice_pdf_log', ['file_name' => 'new.pdf']);
    }

    public function test_marketing_consent_log_is_never_pruned(): void
    {
        $old = now()->subDays(2000);
        $customerId = $this->makeCustomer()->id;

        DB::table('marketing_consent_log')->insert([
            'customer_id' => $customerId,
            'source' => 'old',
            'created_at' => $old,
            'updated_at' => $old,
        ]);

        $this->artisan('logs:prune')->assertExitCode(0);

        $this->assertDatabaseHas('marketing_consent_log', ['source' => 'old']);
    }

    public function test_activity_log_uses_the_settings_window_over_the_config_default(): void
    {
        // Save a 10-day window through the settings class (the admin path).
        $settings = app(LogRetentionSettings::class);
        $settings->activity_retention_days = 10;
        $settings->save();

        $fifteenDaysAgo = now()->subDays(15);
        $twoDaysAgo = now()->subDays(2);

        DB::table('activity_log')->insert([
            ['action' => 'would_survive_config_default', 'created_at' => $fifteenDaysAgo],
            ['action' => 'recent', 'created_at' => $twoDaysAgo],
        ]);

        $this->artisan('logs:prune')->assertExitCode(0);

        // The 15-day-old row is pruned at the saved 10-day window; the 180-day
        // config default would have kept it.
        $this->assertDatabaseMissing('activity_log', ['action' => 'would_survive_config_default']);
        $this->assertDatabaseHas('activity_log', ['action' => 'recent']);
    }

    public function test_cleanup_command_rejects_a_non_positive_days_override_without_deleting(): void
    {
        DB::table('activity_log')->insert([
            ['action' => 'keep_me', 'created_at' => now()->subDays(100)],
        ]);

        $this->artisan('app:cleanup', ['--days' => 'abc'])->assertFailed();
        $this->assertDatabaseHas('activity_log', ['action' => 'keep_me']);

        $this->artisan('app:cleanup', ['--days' => '0'])->assertFailed();
        $this->assertDatabaseHas('activity_log', ['action' => 'keep_me']);
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
