<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Settings\BillingSettings;
use App\Settings\GeneralSettings;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Concurrency semantics guard for admin settings saves (task 14).
 *
 * Saves are LAST-WRITE-WINS (pinned — no optimistic/pessimistic locking):
 * - Two admins saving DIFFERENT tabs sequentially via per-tab payloads must
 *   both persist (per-tab scoping keeps their writes disjoint).
 * - Two admins saving the SAME tab still overwrite each other (last write
 *   wins) — the activity_log audit trail is the detection mechanism, so both
 *   writes must be recorded with their causer.
 * - The UI note near Save All warns admins to coordinate.
 */
class SettingsConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Spatie settings are container-scoped singletons; flush so each test
        // resolves a fresh instance for its own DB (same as audit test).
        app()->forgetScopedInstances();

        $ref = new \ReflectionClass(AppSettings::class);
        $prop = $ref->getProperty('cache');
        $prop->setValue(null, null);
    }

    public function test_two_admins_saving_different_tabs_sequentially_both_persist(): void
    {
        // Admin A saves the General tab only (per-tab payload).
        $adminA = $this->actingAsSettingsAdmin();
        $this->post(route('admin.settings.update'), [
            'active_tab' => 'general',
            'settings' => ['company_name' => 'Admin A General Co'],
        ])->assertRedirect(route('admin.settings.index', ['tab' => 'general']));

        // Admin B then saves the Billing tab only (per-tab payload).
        $adminB = $this->actingAsSettingsAdmin();
        $this->post(route('admin.settings.update'), [
            'active_tab' => 'billing',
            'settings' => ['invoice_prefix' => 'B-'],
        ])->assertRedirect(route('admin.settings.index', ['tab' => 'billing']));

        // Both writes persist — per-tab scoping kept them disjoint.
        $this->assertSame(
            'Admin A General Co',
            app(GeneralSettings::class)->company_name,
            'Admin B billing save must not clobber Admin A general save.'
        );
        $this->assertSame(
            'B-',
            app(BillingSettings::class)->invoice_prefix,
            'Admin B billing save must persist.'
        );

        // Audit trail records each admin as causer of their own section write.
        $sections = DB::table('activity_log')
            ->where('action', 'settings.updated')
            ->get(['user_id', 'properties']);
        $bySection = [];
        foreach ($sections as $row) {
            $props = json_decode((string) $row->properties, true);
            $bySection[$props['section']] = (int) $row->user_id;
        }
        $this->assertSame($adminA->id, $bySection['general'], 'Audit must attribute general write to Admin A.');
        $this->assertSame($adminB->id, $bySection['billing'], 'Audit must attribute billing write to Admin B.');
    }

    public function test_same_tab_edits_are_last_write_wins_and_audit_detects_both(): void
    {
        // Admin A edits General first.
        $adminA = $this->actingAsSettingsAdmin();
        $this->post(route('admin.settings.update'), [
            'active_tab' => 'general',
            'settings' => ['company_name' => 'First Writer'],
        ])->assertRedirect();

        // Admin B edits the SAME tab afterwards — last write wins, no locking.
        $adminB = $this->actingAsSettingsAdmin();
        $this->post(route('admin.settings.update'), [
            'active_tab' => 'general',
            'settings' => ['company_name' => 'Second Writer'],
        ])->assertRedirect();

        $this->assertSame(
            'Second Writer',
            app(GeneralSettings::class)->company_name,
            'Same-tab saves are last-write-wins by design.'
        );

        // Detection, not prevention: audit rows exist for BOTH writers.
        $causers = DB::table('activity_log')
            ->where('action', 'settings.updated')
            ->orderBy('created_at')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $this->assertContains($adminA->id, $causers, 'Audit must record Admin A write for lost-update tracing.');
        $this->assertContains($adminB->id, $causers, 'Audit must record Admin B write for lost-update tracing.');
    }

    public function test_save_all_concurrency_note_visible_on_get(): void
    {
        $this->actingAsSettingsAdmin();

        $response = $this->get(route('admin.settings.index'));
        $response->assertStatus(200);

        $html = $response->getContent();
        $this->assertStringContainsString(
            'save-all-concurrency-note',
            $html,
            'Concurrency note element missing near Save All.'
        );
        $this->assertStringContainsString(
            'Changes save immediately — coordinate with team when editing at the same time',
            $html,
            'UI coordination note text missing.'
        );
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
