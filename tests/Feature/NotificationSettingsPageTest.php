<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Admin "Notification Settings" matrix page (admin.notification-settings.*):
 * renders the catalogue grouped by category and persists per-combo toggles
 * to notification_event_settings.
 */
class NotificationSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);

        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        foreach (['settings.view', 'settings.manage'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->syncWithoutDetaching($permission->id);
        }

        $user->assignRole('admin');

        return $user;
    }

    public function test_index_renders_the_matrix_with_catalogue_events(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.notification-settings.index'))
            ->assertOk()
            ->assertSee('Notification Settings')
            ->assertSee('Order created');
    }

    public function test_index_renders_the_category_icon_classes(): void
    {
        $response = $this->actingAs($this->admin())
            ->get(route('admin.notification-settings.index'))
            ->assertOk();

        foreach (['bi-receipt', 'bi-ticket-detailed', 'bi-chat-dots', 'bi-arrow-repeat', 'bi-gear'] as $icon) {
            $response->assertSee($icon);
        }
    }

    public function test_update_persists_disabled_and_enabled_toggles(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)
            ->put(route('admin.notification-settings.update'), [
                'settings' => [
                    'order.created' => [
                        // database.admin intentionally absent -> unchecked -> disabled
                        'customer' => ['database' => '1'],
                    ],
                ],
            ]);

        $response->assertRedirect(route('admin.notification-settings.index'));

        $adminRow = DB::table('notification_event_settings')
            ->where('event_key', 'order.created')
            ->where('audience', 'admin')
            ->where('channel', 'database')
            ->first();
        $this->assertNotNull($adminRow);
        $this->assertSame(0, (int) $adminRow->enabled);

        $customerRow = DB::table('notification_event_settings')
            ->where('event_key', 'order.created')
            ->where('audience', 'customer')
            ->where('channel', 'database')
            ->first();
        $this->assertNotNull($customerRow);
        $this->assertSame(1, (int) $customerRow->enabled);
    }

    public function test_update_ignores_non_catalogue_payload_keys(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('admin.notification-settings.update'), [
                'settings' => [
                    'not.a.real.event' => ['admin' => ['database' => '1']],
                    'order.created' => ['nonsense-audience' => ['database' => '1']],
                    'order.paid' => ['admin' => ['telepathy' => '1']],
                ],
            ])
            ->assertRedirect(route('admin.notification-settings.index'));

        $this->assertSame(0, DB::table('notification_event_settings')->where('event_key', 'not.a.real.event')->count());
        $this->assertSame(0, DB::table('notification_event_settings')->where('audience', 'nonsense-audience')->count());
        $this->assertSame(0, DB::table('notification_event_settings')->where('channel', 'telepathy')->count());
    }

    public function test_guest_cannot_update(): void
    {
        $this->put(route('admin.notification-settings.update'), [
            'settings' => ['order.created' => ['admin' => ['database' => '1']]],
        ])->assertRedirect(route('admin.login'));
    }

    public function test_client_non_admin_cannot_update(): void
    {
        $client = User::factory()->create(['role' => 'client']);

        $this->actingAs($client)
            ->put(route('admin.notification-settings.update'), [
                'settings' => ['order.created' => ['admin' => ['database' => '1']]],
            ])
            ->assertForbidden();
    }
}
