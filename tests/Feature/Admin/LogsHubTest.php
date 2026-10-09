<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The admin Logs hub: each of the four log pages is gated by its own
 * permission, renders a seeded row for an authorised actor, and the
 * pre-existing Activity Log route still resolves for an activity.view holder.
 *
 * RBAC is seeded by TestCase::setUp, so an `admin`-role user holds every
 * permission and a `support`-role user holds a real but narrower set — support
 * deliberately lacks audit.view / module_logs.view / domain_logs.view /
 * consent.view, which makes it the natural "without permission" actor.
 */
final class LogsHubTest extends TestCase
{
    use RefreshDatabase;

    // ── Audit Trail ──────────────────────────────────────────────────

    public function test_audit_log_requires_the_audit_permission(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'support']))
            ->get(route('admin.audit-log.index'))
            ->assertForbidden();
    }

    public function test_audit_log_lists_a_seeded_row_for_an_authorised_actor(): void
    {
        DB::table('audit_log')->insert([
            'action' => 'audit.seed.action',
            'entity_type' => 'seed_entity',
            'entity_id' => 7,
            'ip_address' => '10.0.0.1',
            'created_at' => now(),
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.audit-log.index'))
            ->assertOk()
            ->assertSee('audit.seed.action')
            ->assertSee('seed_entity');
    }

    // ── Module Logs ──────────────────────────────────────────────────

    public function test_module_logs_require_the_module_logs_permission(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'support']))
            ->get(route('admin.module-logs.index'))
            ->assertForbidden();
    }

    public function test_module_logs_list_a_seeded_row_for_an_authorised_actor(): void
    {
        DB::table('module_log')->insert([
            'module_id' => null,
            'event' => 'seed.module.event',
            'service_instance_id' => 42,
            'status' => 'info',
            'error' => 'seed module failure',
            'created_at' => now(),
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.module-logs.index'))
            ->assertOk()
            ->assertSee('seed.module.event')
            ->assertSee('seed module failure');
    }

    // ── Domain Logs ──────────────────────────────────────────────────

    public function test_domain_logs_require_the_domain_logs_permission(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'support']))
            ->get(route('admin.domain-logs.index'))
            ->assertForbidden();
    }

    public function test_domain_logs_list_seeded_sync_and_search_rows(): void
    {
        DB::table('domain_sync_log')->insert([
            'provider' => 'seedregistrar',
            'operation' => 'sync',
            'status' => 'success',
            'payload' => json_encode([]),
            'created_at' => now(),
        ]);

        DB::table('domain_search_logs')->insert([
            'customer_id' => null,
            'domain_name' => 'seed-domain.example',
            'results' => json_encode([]),
            'created_at' => now(),
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.domain-logs.index'))
            ->assertOk()
            ->assertSee('seedregistrar')
            ->assertSee('seed-domain.example');
    }

    // ── Consent Log ──────────────────────────────────────────────────

    public function test_consent_log_requires_the_consent_permission(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'support']))
            ->get(route('admin.consent-log.index'))
            ->assertForbidden();
    }

    public function test_consent_log_lists_a_seeded_row_for_an_authorised_actor(): void
    {
        $owner = User::factory()->create(['first_name' => 'Seed', 'last_name' => 'Consenter']);
        $customer = Customer::create([
            'user_id' => $owner->id,
            'company' => 'Seed Co',
            'status' => 'active',
        ]);

        DB::table('marketing_consent_log')->insert([
            'customer_id' => $customer->id,
            'contact_type' => 'email',
            'consent_status' => 'opt_in',
            'source' => 'seed.consent.source',
            'ip_address' => '10.0.0.9',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.consent-log.index'))
            ->assertOk()
            ->assertSee('seed.consent.source')
            ->assertSee('Seed Consenter');
    }

    // ── Regression: the pre-existing Activity Log route still resolves ──

    public function test_activity_log_still_resolves_for_an_activity_view_holder(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.activity-log.index'))
            ->assertOk();
    }
}
