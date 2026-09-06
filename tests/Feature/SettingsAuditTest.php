<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Audit trail guard for admin settings saves (task 13).
 *
 * - Every settings POST that changes values writes exactly one activity_log
 *   row (action=settings.updated) with causer user_id, JSON diff in
 *   properties/metadata and a key-only description. Reuses activity_log only —
 *   no settings_audits table.
 * - Encrypted secrets (IntegrationSettings::casts() + smtp_password) are
 *   masked as '***' in the audit diff; plaintext never reaches the log row.
 * - A POST with no effective changes writes no audit row.
 * - Next GET shows "Last updated" per section from the latest audit row.
 */
class SettingsAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Spatie settings are container-scoped singletons; flush so each test
        // resolves a fresh instance for its own DB (same as inventory test).
        app()->forgetScopedInstances();

        $ref = new \ReflectionClass(AppSettings::class);
        $prop = $ref->getProperty('cache');
        $prop->setValue(null, null);
    }

    public function test_changed_save_writes_audit_row_with_causer_and_diff(): void
    {
        $admin = $this->actingAsSettingsAdmin();

        $this->post(route('admin.settings.update'), [
            'active_tab' => 'general',
            'settings' => ['company_name' => 'Audit Co'],
        ])->assertRedirect(route('admin.settings.index', ['tab' => 'general']));

        $row = DB::table('activity_log')
            ->where('action', 'settings.updated')
            ->orderByDesc('created_at')
            ->first();

        $this->assertNotNull($row, 'No settings.updated audit row written.');
        $this->assertSame($admin->id, $row->user_id, 'Audit causer must be the authenticated admin.');

        $props = json_decode((string) $row->properties, true);
        $this->assertIsArray($props, 'properties must hold JSON diff.');
        $this->assertSame('general', $props['section']);
        $this->assertContains('company_name', $props['changed_keys']);
        $this->assertSame('Audit Co', $props['changes']['company_name']['new']);

        // Description carries keys only (no secret risk), section tagged for reader fallback.
        $this->assertStringContainsString('Settings updated (general)', (string) $row->description);
        $this->assertStringContainsString('company_name', (string) $row->description);
    }

    public function test_secret_change_is_masked_in_audit_row(): void
    {
        $this->actingAsSettingsAdmin();

        $this->post(route('admin.settings.update'), [
            'active_tab' => 'integration',
            'settings' => ['cpanel_api_token' => 'supersecret-token-123'],
        ])->assertRedirect();

        $row = DB::table('activity_log')
            ->where('action', 'settings.updated')
            ->orderByDesc('created_at')
            ->first();

        $this->assertNotNull($row, 'Secret change must still be audited.');

        $raw = json_encode([$row->properties, $row->metadata, $row->description]);
        $this->assertStringNotContainsString('supersecret-token-123', (string) $raw, 'Plaintext secret leaked into audit row.');

        $props = json_decode((string) $row->properties, true);
        $this->assertSame('***', $props['changes']['cpanel_api_token']['new'], 'New secret value must be masked.');
        $this->assertSame('integration', $props['section']);
    }

    public function test_smtp_password_change_is_masked_in_audit_row(): void
    {
        $this->actingAsSettingsAdmin();

        $this->post(route('admin.settings.update'), [
            'active_tab' => 'email',
            'settings' => ['smtp_password' => 'plain-smtp-pass-999'],
        ])->assertRedirect();

        $row = DB::table('activity_log')
            ->where('action', 'settings.updated')
            ->orderByDesc('created_at')
            ->first();

        $this->assertNotNull($row);

        $raw = json_encode([$row->properties, $row->metadata, $row->description]);
        $this->assertStringNotContainsString('plain-smtp-pass-999', (string) $raw, 'smtp_password plaintext leaked into audit row.');

        $props = json_decode((string) $row->properties, true);
        $this->assertSame('***', $props['changes']['smtp_password']['new']);
    }

    public function test_no_change_post_writes_no_audit_row(): void
    {
        $this->actingAsSettingsAdmin();

        // First save establishes the value (and one audit row).
        $this->post(route('admin.settings.update'), [
            'active_tab' => 'general',
            'settings' => ['company_name' => 'Same Value Co'],
        ])->assertRedirect();

        $countAfterFirst = DB::table('activity_log')->where('action', 'settings.updated')->count();
        $this->assertGreaterThanOrEqual(1, $countAfterFirst);

        // Identical re-save: no diff → no new audit row (documented choice).
        $this->post(route('admin.settings.update'), [
            'active_tab' => 'general',
            'settings' => ['company_name' => 'Same Value Co'],
        ])->assertRedirect();

        $countAfterSecond = DB::table('activity_log')->where('action', 'settings.updated')->count();
        $this->assertSame($countAfterFirst, $countAfterSecond, 'Unchanged save must not write an extra audit row.');
    }

    public function test_get_shows_last_updated_per_section_from_audit(): void
    {
        $this->actingAsSettingsAdmin();

        $this->post(route('admin.settings.update'), [
            'active_tab' => 'billing',
            'settings' => ['invoice_prefix' => 'AUD-'],
        ])->assertRedirect(route('admin.settings.index', ['tab' => 'billing']));

        $response = $this->get(route('admin.settings.index', ['tab' => 'billing']));
        $response->assertStatus(200);

        $html = $response->getContent();
        $this->assertStringContainsString('Last updated:', $html, 'GET must render Last updated per section.');
        $this->assertStringContainsString('data-section="billing"', $html);
        $this->assertStringContainsString('Settings updated (billing)', $html, 'Section tag from latest audit row must surface on GET.');
    }

    private function actingAsSettingsAdmin(): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        foreach (['settings.view', 'settings.manage'] as $permName) {
            $perm = Permission::firstOrCreate(['name' => $permName], ['label' => ucfirst($permName)]);
            $adminRole->permissions()->syncWithoutDetaching($perm->id);
        }

        $user->assignRole('admin');
        $this->actingAs($user);

        return $user;
    }
}
