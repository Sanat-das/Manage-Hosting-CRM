<?php

namespace Tests\Dusk;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Browser-level coverage for sortable data-grid columns.
 *
 * Server rendering cannot prove the sort link works: the header is an <a>
 * built from the current query string, the click triggers a full navigation,
 * and the new order only appears after the paginated query re-runs server-side.
 * The direction toggle (asc → desc) and the row-order flip must be observed
 * from the browser.
 *
 * Runtime contract (same as GridColumnResizeTest): the app must be served with
 * the Dusk environment on the APP_URL in .env.dusk.local so the browser and
 * the test process share database/dusk.sqlite.
 */
class GridSortTest extends DuskTestCase
{
    private static bool $schemaMigrated = false;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$schemaMigrated) {
            $this->artisan('migrate:fresh');
            self::$schemaMigrated = true;
        }

        User::whereIn('email', [
            'grid-sort-admin@example.test',
            'grid-sort-alice@example.test',
            'grid-sort-bob@example.test',
            'grid-sort-charlie@example.test',
        ])->delete();

        $this->admin = User::create([
            'email' => 'grid-sort-admin@example.test',
            'password_hash' => bcrypt('password'),
            'first_name' => 'GridSort',
            'last_name' => 'Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        foreach (['customers.view', 'customers.edit', 'customers.delete'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->syncWithoutDetaching($permission->id);
        }
        $this->admin->assignRole('admin');

        // Three customers with lexically distinct names so ordering is unambiguous
        // regardless of insertion order. The grid sorts Name via users.first_name.
        foreach ([
            ['Alice', 'Anderson', 'grid-sort-alice@example.test', 'Alpha Co', 100],
            ['Bob', 'Baker', 'grid-sort-bob@example.test', 'Beta Co', 200],
            ['Charlie', 'Clark', 'grid-sort-charlie@example.test', 'Gamma Co', 300],
        ] as [$first, $last, $email, $company, $balance]) {
            $user = User::create([
                'email' => $email,
                'password_hash' => bcrypt('password'),
                'first_name' => $first,
                'last_name' => $last,
                'role' => 'client',
                'status' => 'active',
            ]);

            Customer::create([
                'user_id' => $user->id,
                'company' => $company,
                'balance' => $balance,
                'credit' => 0,
                'status' => 'active',
            ]);
        }
    }

    public function test_clicking_sortable_header_applies_is_active_and_flips_row_order(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->admin)
                ->visit('/admin/customers')
                ->waitFor('table[data-grid-resizable]')
                ->waitFor('table[data-grid-resizable] thead a.grid-sort');

            // No sort yet — no column should be marked active.
            $activeBefore = $browser->driver->executeScript(
                "return document.querySelector('table[data-grid-resizable] thead a.grid-sort.is-active') !== null;"
            );
            $this->assertFalse((bool) $activeBefore, 'no header should be active before any sort is requested');

            // Helper to read the visible Name column order (second td, trimmed).
            $namesInOrder = fn () => $browser->driver->executeScript(
                "return Array.from(document.querySelectorAll('table[data-grid-resizable] tbody tr td:nth-child(2)')).map(function (td) { return td.innerText.trim().replace(/\\s+/g, ' '); });"
            );

            $initialNames = $namesInOrder();
            // Sanity: our three seeded names are present (admin's own customer may also appear).
            $this->assertContains('Alice Anderson', $initialNames);
            $this->assertContains('Bob Baker', $initialNames);
            $this->assertContains('Charlie Clark', $initialNames);

            // First click on Name → sort=name&direction=asc, Name header becomes is-active with an ascending arrow.
            $browser->click('table[data-grid-resizable] thead th:nth-child(2) a.grid-sort');
            $browser->waitFor('table[data-grid-resizable] thead th:nth-child(2) a.grid-sort.is-active');
            $browser->pause(400);

            $urlAfterFirst = $browser->driver->getCurrentURL();
            $this->assertStringContainsString('sort=name', $urlAfterFirst);
            $this->assertStringContainsString('direction=asc', $urlAfterFirst);

            $iconClassAfterFirst = $browser->driver->executeScript(
                "var el = document.querySelector('table[data-grid-resizable] thead th:nth-child(2) a.grid-sort.is-active i'); return el ? el.className : null;"
            );
            $this->assertIsString($iconClassAfterFirst);
            $this->assertStringContainsString('bi-arrow-up', (string) $iconClassAfterFirst, 'ascending sort should show bi-arrow-up');

            $namesAsc = $namesInOrder();
            $posAliceAsc = array_search('Alice Anderson', $namesAsc, true);
            $posCharlieAsc = array_search('Charlie Clark', $namesAsc, true);
            $this->assertIsInt($posAliceAsc);
            $this->assertIsInt($posCharlieAsc);
            $this->assertLessThan($posCharlieAsc, $posAliceAsc, 'ascending Name sort must place Alice before Charlie');

            // Only the clicked column should be active.
            $activeCount = (int) $browser->driver->executeScript(
                "return document.querySelectorAll('table[data-grid-resizable] thead a.grid-sort.is-active').length;"
            );
            $this->assertSame(1, $activeCount, 'exactly one header should be marked is-active');

            // Second click on the same header → direction flips to desc and row order flips.
            $browser->click('table[data-grid-resizable] thead th:nth-child(2) a.grid-sort.is-active');
            $browser->pause(400);
            $browser->waitFor('table[data-grid-resizable] thead th:nth-child(2) a.grid-sort.is-active');

            $urlAfterSecond = $browser->driver->getCurrentURL();
            $this->assertStringContainsString('sort=name', $urlAfterSecond);
            $this->assertStringContainsString('direction=desc', $urlAfterSecond);

            $iconClassAfterSecond = $browser->driver->executeScript(
                "var el = document.querySelector('table[data-grid-resizable] thead th:nth-child(2) a.grid-sort.is-active i'); return el ? el.className : null;"
            );
            $this->assertIsString($iconClassAfterSecond);
            $this->assertStringContainsString('bi-arrow-down', (string) $iconClassAfterSecond, 'descending sort should show bi-arrow-down');

            $namesDesc = $namesInOrder();
            $posAliceDesc = array_search('Alice Anderson', $namesDesc, true);
            $posCharlieDesc = array_search('Charlie Clark', $namesDesc, true);
            $this->assertIsInt($posAliceDesc);
            $this->assertIsInt($posCharlieDesc);
            $this->assertGreaterThan($posCharlieDesc, $posAliceDesc, 'descending Name sort must place Charlie before Alice');

            // Final proof that the order truly flipped (not just moved together).
            $this->assertNotSame($namesAsc, $namesDesc, 'row order must flip between asc and desc');
        });
    }

    public function test_sort_preserves_search_query(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->admin)
                ->visit('/admin/customers?search=Alice')
                ->waitFor('table[data-grid-resizable]');

            $browser->click('table[data-grid-resizable] thead th:nth-child(2) a.grid-sort');
            $browser->pause(400);

            $url = $browser->driver->getCurrentURL();
            $this->assertStringContainsString('search=Alice', $url, 'sort link must preserve the existing search query');
            $this->assertStringContainsString('sort=name', $url);
        });
    }
}
