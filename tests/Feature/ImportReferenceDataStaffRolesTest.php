<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\ImportReferenceDataCommand;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

/**
 * Covers ImportReferenceDataCommand::assignStaffRoles(), changed in 3ec32086.
 *
 * The command as a whole cannot run here: handle() issues
 * `SET FOREIGN_KEY_CHECKS=0`, which is MySQL-only, while the suite runs on
 * sqlite. assignStaffRoles() touches nothing but the adminlte_* tables and the
 * id map, so it is exercised directly and that is exactly the code 3ec32086
 * altered.
 *
 * Before that commit the method mapped every imported `role='staff'` user onto
 * the **support** role, its docblock explaining that the RBAC set had no staff
 * role. One was added in the same commit, so the mapping is now name-for-name
 * and the pivot agrees with users.role. These tests pin both the new mapping
 * and the fallback that keeps older targets working.
 */
final class ImportReferenceDataStaffRolesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Call the private method with a crafted seed payload and id map.
     *
     * @param  list<array{int, string}>  $seedUsers  [seed id, role]
     * @param  array<int, int>  $idMap  seed id => target id
     */
    private function assignStaffRoles(array $seedUsers, array $idMap): string
    {
        $command = new ImportReferenceDataCommand();
        $command->setLaravel($this->app);

        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $command->setOutput(new \Illuminate\Console\OutputStyle(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            $output,
        ));

        $reflection = new ReflectionClass($command);

        $maps = $reflection->getProperty('idMaps');
        $maps->setValue($command, ['users' => $idMap]);

        $method = $reflection->getMethod('assignStaffRoles');
        $method->invoke($command, [
            'columns' => ['id', 'role'],
            'rows' => array_map(
                static fn (array $u): array => [(string) $u[0], "'{$u[1]}'"],
                $seedUsers,
            ),
        ]);

        return $output->fetch();
    }

    private function targetUser(string $email, string $role): User
    {
        return User::factory()->create(['email' => $email, 'role' => $role]);
    }

    private function pivotRoleNames(User $user): array
    {
        return DB::table('adminlte_role_user')
            ->join('adminlte_roles', 'adminlte_roles.id', '=', 'adminlte_role_user.role_id')
            ->where('adminlte_role_user.user_id', $user->id)
            ->pluck('adminlte_roles.name')
            ->all();
    }

    public function test_imported_staff_users_are_mapped_to_the_staff_role(): void
    {
        $user = $this->targetUser('imported-staff@example.com', 'staff');

        $this->assignStaffRoles([[7, 'staff']], [7 => $user->id]);

        $this->assertSame(['staff'], $this->pivotRoleNames($user));
    }

    /**
     * The regression this commit is really about.
     *
     * Mapping staff onto `support` gave every imported staff account support's
     * 16 permissions -- including hosting.view, which gates the RDP password
     * endpoint and the SSH console, i.e. stored server credentials.
     */
    public function test_imported_staff_do_not_inherit_support_permissions(): void
    {
        $user = $this->targetUser('imported-staff@example.com', 'staff');

        $this->assignStaffRoles([[7, 'staff']], [7 => $user->id]);

        $user = $user->fresh();

        $this->assertTrue($user->hasPermission('dashboard.view'), 'staff should keep a usable baseline');
        $this->assertFalse(
            $user->hasPermission('hosting.view'),
            'An imported staff user must not receive hosting.view: it discloses stored server '
                . 'credentials via the RDP password endpoint and the SSH console.'
        );
    }

    public function test_non_staff_seed_rows_are_left_alone(): void
    {
        $client = $this->targetUser('imported-client@example.com', 'client');
        $admin = $this->targetUser('imported-admin@example.com', 'admin');

        $this->assignStaffRoles(
            [[8, 'client'], [9, 'admin']],
            [8 => $client->id, 9 => $admin->id],
        );

        $this->assertSame([], $this->pivotRoleNames($client));
        $this->assertSame([], $this->pivotRoleNames($admin));
    }

    /**
     * Targets seeded before the staff role existed must still import.
     */
    public function test_it_falls_back_to_support_when_no_staff_role_exists(): void
    {
        Role::where('name', 'staff')->delete();

        $user = $this->targetUser('imported-staff@example.com', 'staff');

        $output = $this->assignStaffRoles([[7, 'staff']], [7 => $user->id]);

        $this->assertSame(['support'], $this->pivotRoleNames($user));
        $this->assertStringContainsString('support', $output);
    }

    public function test_it_skips_and_warns_when_neither_role_exists(): void
    {
        Role::whereIn('name', ['staff', 'support'])->delete();

        $user = $this->targetUser('imported-staff@example.com', 'staff');

        $output = $this->assignStaffRoles([[7, 'staff']], [7 => $user->id]);

        $this->assertSame([], $this->pivotRoleNames($user));
        $this->assertStringContainsString('staff RBAC mapping skipped', $output);
    }

    /**
     * `--force` re-runs the import over the same users; insertOrIgnore must not
     * accumulate duplicate pivot rows.
     */
    public function test_running_twice_does_not_duplicate_pivot_rows(): void
    {
        $user = $this->targetUser('imported-staff@example.com', 'staff');

        $this->assignStaffRoles([[7, 'staff']], [7 => $user->id]);
        $before = DB::table('adminlte_role_user')->count();

        $this->assignStaffRoles([[7, 'staff']], [7 => $user->id]);

        $this->assertSame($before, DB::table('adminlte_role_user')->count());
        $this->assertSame(['staff'], $this->pivotRoleNames($user));
    }

    /**
     * The seed id is not the target id -- the importer remaps colliding keys,
     * and the pivot must follow the remapped id, not the one in the seed file.
     */
    public function test_it_uses_the_remapped_target_id(): void
    {
        $user = $this->targetUser('imported-staff@example.com', 'staff');

        $this->assignStaffRoles([[7, 'staff']], [7 => $user->id]);

        $this->assertSame(['staff'], $this->pivotRoleNames($user));
        $this->assertSame(
            0,
            DB::table('adminlte_role_user')->where('user_id', 7)->count(),
            'The pivot row was written against the seed id instead of the remapped target id.'
        );
    }
}
