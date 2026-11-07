<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Notifications\EventCatalogue;
use App\Support\Notifications\NotificationMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Notification Settings matrix derives its audience columns from the live
 * adminlte_roles table: creating a role adds a column, deleting it removes
 * the column and every setting saved for it.
 */
class NotificationRoleSyncTest extends TestCase
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

    private function makeRole(string $name, string $label): Role
    {
        return Role::create(['name' => $name, 'label' => $label]);
    }

    private function enableCell(string $eventKey, string $audience, string $channel): void
    {
        DB::table('notification_event_settings')->insert([
            'event_key' => $eventKey,
            'audience' => $audience,
            'channel' => $channel,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_new_role_renders_as_a_matrix_column(): void
    {
        $this->makeRole('billing', 'Billing Team');

        $this->actingAs($this->admin())
            ->get(route('admin.notification-settings.index'))
            ->assertOk()
            ->assertSee('Billing Team')
            ->assertSee('settings[order.created][billing][database]');
    }

    public function test_a_new_role_cell_can_be_enabled_and_persists(): void
    {
        $this->makeRole('billing', 'Billing Team');

        EventCatalogue::flushAudiences();
        NotificationMatrix::flush();

        $this->assertFalse(
            NotificationMatrix::allows('order.created', 'billing', 'database'),
            'A new role must default to disabled on the staff database axis.',
        );

        $this->actingAs($this->admin())
            ->put(route('admin.notification-settings.update'), [
                'settings' => [
                    'order.created' => [
                        'billing' => ['database' => '1'],
                    ],
                ],
            ])
            ->assertRedirect(route('admin.notification-settings.index'));

        $row = DB::table('notification_event_settings')
            ->where('event_key', 'order.created')
            ->where('audience', 'billing')
            ->where('channel', 'database')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->enabled);
        $this->assertTrue(NotificationMatrix::allows('order.created', 'billing', 'database'));
    }

    public function test_a_new_role_is_not_applicable_where_no_staff_role_is(): void
    {
        $this->makeRole('billing', 'Billing Team');

        EventCatalogue::flushAudiences();
        NotificationMatrix::flush();

        $this->actingAs($this->admin())
            ->put(route('admin.notification-settings.update'), [
                'settings' => [
                    'invoice.created' => [
                        'billing' => ['mail' => '1'],
                    ],
                ],
            ])
            ->assertRedirect(route('admin.notification-settings.index'));

        $this->assertSame(0, DB::table('notification_event_settings')
            ->where('event_key', 'invoice.created')
            ->where('audience', 'billing')
            ->count());

        $this->assertFalse(NotificationMatrix::allows('invoice.created', 'billing', 'mail'));
    }

    public function test_deleting_a_role_removes_its_column_and_settings(): void
    {
        $role = $this->makeRole('billing', 'Billing Team');

        $this->enableCell('order.created', 'billing', 'database');

        EventCatalogue::flushAudiences();
        NotificationMatrix::flush();

        $this->assertContains('billing', EventCatalogue::audiences());

        $role->delete();

        $this->actingAs($this->admin())
            ->get(route('admin.notification-settings.index'))
            ->assertOk()
            ->assertDontSee('Billing Team')
            ->assertDontSee('settings[order.created][billing][database]');

        $this->assertSame(0, DB::table('notification_event_settings')->where('audience', 'billing')->count());
        $this->assertNotContains('billing', EventCatalogue::audiences());
    }

    public function test_admin_and_customer_are_always_audiences(): void
    {
        DB::table('adminlte_roles')->delete();

        EventCatalogue::flushAudiences();

        $this->assertSame(['admin', 'customer'], EventCatalogue::audiences());
    }

    public function test_staff_pool_includes_a_pivot_only_holder_of_a_new_role(): void
    {
        $role = $this->makeRole('billing', 'Billing Team');

        $user = User::factory()->create(['role' => 'client']);
        $user->assignRole('billing');

        $this->enableCell('order.created', 'billing', 'database');

        EventCatalogue::flushAudiences();
        NotificationMatrix::flush();

        $this->assertContains(
            $user->id,
            NotificationMatrix::staffPool('order.created', 'database')->pluck('id')->all(),
            'A pivot-only holder of an enabled new role must receive the staff broadcast.',
        );

        $role->delete();

        $this->assertNotContains(
            $user->id,
            NotificationMatrix::staffPool('order.created', 'database')->pluck('id')->all(),
            'Deleting the role must drop its pivot holders from the staff pool.',
        );
    }
}
