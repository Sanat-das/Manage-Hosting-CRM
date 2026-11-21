<?php

namespace Tests\Browser;

use App\Models\User;
use Database\Seeders\AdminLteRbacSeeder;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Admin sidebar smoke test.
 *
 * Verifies gate #5 of Phase 5.2: after logging in as an admin holding the full
 * RBAC permission set, every sidebar module page resolves without a 404/403.
 *
 * Database strategy (same as tests/Dusk): the schema is migrated FRESH once
 * per process and never rolled back between tests — Dusk serves the app from a
 * long-lived process that holds the sqlite connection open, so per-test
 * DatabaseMigrations would drop tables underneath it. setUp() seeds the RBAC
 * role/permission matrix and recreates only this test's own admin user (cleaned
 * by email), then authenticates with loginAs() instead of driving the UI login
 * form with hard-coded credentials.
 */
class AdminSidebarSmokeTest extends DuskTestCase
{
    private const ADMIN_EMAIL = 'admin-sidebar-smoke@example.test';

    private static bool $schemaMigrated = false;

    private User $admin;

    /**
     * Modules from the AdminLTE sidebar, each with its URL and the unique
     * <h1> heading its index page renders when it resolves successfully.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    protected array $modules = [
        ['/admin/dns-zones', 'DNS Zones'],
        ['/admin/devices', 'Devices'],
        ['/admin/datacenters', 'Datacenters'],
        ['/admin/ip-subnets', 'IP Subnets'],
        ['/admin/licenses', 'Licenses'],
        ['/admin/cart', 'Shopping Cart'],
        ['/admin/search', 'Search Results'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$schemaMigrated) {
            $this->artisan('migrate:fresh', ['--force' => true]);
            self::$schemaMigrated = true;
        }

        // Recreate the RBAC matrix so the admin role holds every permission
        // the module routes gate on, then (re)create only this test's admin.
        $this->seed(AdminLteRbacSeeder::class);
        User::where('email', self::ADMIN_EMAIL)->delete();

        $this->admin = User::create([
            'email' => self::ADMIN_EMAIL,
            'password_hash' => bcrypt('password'),
            'first_name' => 'Sidebar',
            'last_name' => 'Smoke',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->admin->assignRole('admin');
    }

    /**
     * Log in as the Dusk-created admin and confirm every sidebar module
     * resolves without redirecting (no auth bounce), rendering its own
     * heading — i.e. the route exists (not a 404) and passes the permission
     * gate (not a 403).
     */
    public function test_admin_login_and_all_sidebar_modules_resolve(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->admin)
                ->visit('/admin/dashboard')
                ->assertPathIs('/admin/dashboard')
                ->assertSee('Dashboard');

            foreach ($this->modules as [$url, $heading]) {
                $browser->visit($url)
                    // Not bounced back to login/elsewhere => still authenticated.
                    ->assertPathBeginsWith('/admin/')
                    // The error layout renders "Page not found"; its absence
                    // proves the module is a real admin page (no 404/403).
                    ->assertDontSee('Page not found')
                    ->assertSee($heading);
            }
        });
    }
}
