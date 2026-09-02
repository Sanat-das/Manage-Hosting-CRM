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
 * Audit guard for the 18 legacy untyped settings keys.
 *
 * These keys are NOT in AppSettings::TYPED_KEYS and therefore hit the fallback
 * validation `nullable|string|max:1000` in SettingsController::update().
 * Typed keys delegate to Class::rules()[$key]; untyped keys persist to the
 * legacy `settings` table via updateOrInsert.
 */
class UntypedSettingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The 18 legacy untyped keys — must match the docblock above
     * SettingsController::update() and the blade baseline delta (84 total - 66 typed = 18).
     */
    public const UNTYPED_KEYS = [
        'registration_enabled',
        'default_currency',
        'default_tax_rate',
        'quote_prefix',
        'auto_generate_invoice',
        'due_days',
        'gst_enabled',
        'mail_from_address',
        'mail_from_name',
        'session_timeout',
        'max_login_attempts',
        'lockout_duration',
        'force_2fa',
        'password_min_length',
        'notify_overdue_invoices',
        'notify_domain_expiry',
        'notify_new_tickets',
        'domain_expiry_warning_days',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        app()->forgetScopedInstances();

        $ref = new \ReflectionClass(AppSettings::class);
        $prop = $ref->getProperty('cache');
        $prop->setValue(null, null);
    }

    public function testUntypedKeysPersist(): void
    {
        $this->assertCount(18, self::UNTYPED_KEYS, 'Untyped keys count must be 18.');

        // Each key should NOT be typed — otherwise fallback rule is bypassed.
        foreach (self::UNTYPED_KEYS as $key) {
            $this->assertArrayNotHasKey($key, AppSettings::TYPED_KEYS, "{$key} unexpectedly became typed.");
        }

        // POST each key individually and assert it lands in the legacy table.
        // Throttle 'admin' allows 30 POST/min, so 18 sequential posts are safe.
        foreach (self::UNTYPED_KEYS as $key) {
            $value = "test-{$key}-value";

            $this->actingAsSettingsAdmin()
                ->post(route('admin.settings.update'), [
                    'settings' => [$key => $value],
                ])
                ->assertRedirect(route('admin.settings.index'));

            $this->assertTrue(
                DB::table('settings')->where('setting_key', $key)->exists(),
                "Legacy key [{$key}] was not persisted to settings table."
            );

            $this->assertSame(
                $value,
                DB::table('settings')->where('setting_key', $key)->value('setting_value'),
                "Legacy key [{$key}] value mismatch."
            );
        }
    }

    private function actingAsSettingsAdmin(): self
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        foreach (['settings.view', 'settings.manage'] as $permName) {
            $perm = Permission::firstOrCreate(['name' => $permName], ['label' => ucfirst($permName)]);
            $adminRole->permissions()->syncWithoutDetaching($perm->id);
        }

        $user->assignRole('admin');

        return $this->actingAs($user);
    }
}
