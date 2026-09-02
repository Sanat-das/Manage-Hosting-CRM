<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Admin sidebar smoke test.
 *
 * Verifies gate #5 of Phase 5.2: after logging in as the seeded admin,
 * every sidebar module page resolves without a 404 or 403.
 *
 * NOTE: This intentionally does NOT use RefreshDatabase / DatabaseMigrations.
 * Dusk runs against the live site (APP_URL in .env.dusk.local) backed by the
 * real MySQL database, so this test is strictly read-only: it logs in with the
 * seeded admin credentials and asserts each module page renders its own
 * heading (a 404/403 page would show the error layout instead).
 *
 * The whole flow lives in a single test method: Dusk reuses one Chrome
 * profile across browse() calls in a single run, so once the first browse()
 * logs in, the authenticated session persists into later browse() blocks and
 * any subsequent "login" is redirected away from the login form.
 */
class AdminSidebarSmokeTest extends DuskTestCase
{
    /**
     * Modules from the AdminLTE sidebar, each with its URL and the unique
     * <h1> heading its index page renders when it resolves successfully.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    protected array $modules = [
        ['/admin/dns-zones', 'DNS Zones'],
        ['/admin/inventory-assets', 'Inventory Assets'],
        ['/admin/datacenters', 'Datacenters'],
        ['/admin/ip-subnets', 'IP Subnets'],
        ['/admin/licenses', 'Licenses'],
        ['/admin/cart', 'Shopping Cart'],
        ['/admin/search', 'Search Results'],
    ];

    /**
     * Log in as the seeded admin and confirm every sidebar module resolves
     * without redirecting (no auth bounce), rendering its own heading — i.e.
     * the route exists (not a 404) and passes the permission gate (not a 403).
     */
    public function test_admin_login_and_all_sidebar_modules_resolve(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->type('email', 'admin@localhost.com')
                ->type('password', 'Admin@123')
                ->press('button[type="submit"]')
                ->waitForLocation('/admin/dashboard', 15)
                // Landing on the dashboard proves the seeded admin login works.
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
