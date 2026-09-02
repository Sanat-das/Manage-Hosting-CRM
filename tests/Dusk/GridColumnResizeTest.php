<?php

namespace Tests\Dusk;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Browser-level coverage for the fitted, resizable data grid.
 *
 * None of this can be asserted from a server-rendered response: the <colgroup>
 * is built client-side from measured widths, the drag is a stream of pointer
 * events, and the result is persisted to localStorage. This is the only layer
 * that can prove the grid both resizes and stays inside its container.
 *
 * Runtime contract (same as ConfigurableOptionPreviewTest): the app must be
 * served with the Dusk environment on the APP_URL in .env.dusk.local so the
 * browser and the test process share database/dusk.sqlite.
 */
class GridColumnResizeTest extends DuskTestCase
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

        User::where('email', 'grid-resize-admin@example.test')->delete();

        $this->admin = User::create([
            'email' => 'grid-resize-admin@example.test',
            'password_hash' => bcrypt('password'),
            'first_name' => 'Grid',
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

        Customer::firstOrCreate(
            ['user_id' => $this->admin->id],
            ['company' => 'Grid Resize Co', 'status' => 'active']
        );
    }

    public function test_grid_initialises_a_percentage_colgroup_from_measured_widths(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->admin)
                ->visit('/admin/customers')
                ->waitFor('table[data-grid-resizable]')
                ->waitFor('table.grid-resizable colgroup col');

            $this->assertSame(
                'fixed',
                $browser->driver->executeScript(
                    "return getComputedStyle(document.querySelector('table[data-grid-resizable]')).tableLayout;"
                ),
                'the grid should switch to table-layout:fixed once widths are frozen'
            );

            $units = $browser->driver->executeScript(
                "return Array.from(document.querySelectorAll('table[data-grid-resizable] > colgroup > col'))
                    .map(function (col) { return col.style.width.replace(/[0-9.]/g, ''); });"
            );
            $this->assertSame(
                ['%'],
                array_values(array_unique($units)),
                'widths must be percentages — pixel widths are what made the grid overflow'
            );

            $cols = (int) $browser->driver->executeScript(
                "return document.querySelectorAll('table[data-grid-resizable] > colgroup > col').length;"
            );
            $headers = (int) $browser->driver->executeScript(
                "return document.querySelectorAll('table[data-grid-resizable] thead tr:first-child > th').length;"
            );

            $this->assertGreaterThan(0, $cols);
            $this->assertSame($headers, $cols, 'one <col> per header cell');

            $handles = (int) $browser->driver->executeScript(
                "return document.querySelectorAll('table[data-grid-resizable] thead tr:first-child .grid-col-resizer').length;"
            );
            $this->assertSame(
                $headers - 1,
                $handles,
                'every column but the last gets a handle — the last has no neighbour to trade with'
            );

            $this->assertEqualsWithDelta(100.0, $this->totalPercent($browser), 0.5, 'columns should fill exactly 100%');
            $this->assertGridFits($browser);
        });
    }

    public function test_dragging_a_handle_trades_width_with_the_neighbour_and_persists(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->admin)
                ->visit('/admin/customers')
                ->waitFor('table.grid-resizable colgroup col');

            $percentOf = fn (int $i) => (float) $browser->driver->executeScript(
                "return parseFloat(document.querySelectorAll('table[data-grid-resizable] > colgroup > col')[{$i}].style.width);"
            );

            $tableWidth = (float) $browser->driver->executeScript(
                "return document.querySelector('table[data-grid-resizable]').getBoundingClientRect().width;"
            );

            $before = $percentOf(1);
            $neighbourBefore = $percentOf(2);

            $handle = $browser->driver->findElement(
                \Facebook\WebDriver\WebDriverBy::cssSelector(
                    'table[data-grid-resizable] thead tr:first-child th:nth-child(2) .grid-col-resizer'
                )
            );

            $browser->driver->action()
                ->moveToElement($handle)
                ->clickAndHold($handle)
                ->moveByOffset(120, 0)
                ->release()
                ->perform();

            $browser->pause(300);

            $after = $percentOf(1);
            $neighbourAfter = $percentOf(2);

            // A +120px drag, unless the neighbour hits the 56px floor first.
            $minPercent = min(25.0, 56 / $tableWidth * 100);
            $expected = min($before + (120 / $tableWidth * 100), $before + ($neighbourBefore - $minPercent));

            $this->assertEqualsWithDelta(
                $expected,
                $after,
                0.75,
                "dragging +120px should widen column 2 (was {$before}%, now {$after}%)"
            );
            $this->assertLessThan(
                $neighbourBefore,
                $neighbourAfter,
                'the neighbouring column pays for the width the dragged column gains'
            );
            $this->assertEqualsWithDelta(
                $before + $neighbourBefore,
                $after + $neighbourAfter,
                0.1,
                'the traded pair keeps its combined width, so the table cannot grow'
            );
            $this->assertEqualsWithDelta(100.0, $this->totalPercent($browser), 0.5, 'columns still fill exactly 100%');
            $this->assertGridFits($browser);

            $stored = $browser->driver->executeScript(
                "return localStorage.getItem('adminlte.gridColumnWidths.v2');"
            );
            $this->assertNotNull($stored, 'widths should be written to localStorage');
            $this->assertStringContainsString('admin.customers.index', $stored, 'stored under the route-name key');

            $browser->refresh()->waitFor('table.grid-resizable colgroup col')->pause(300);

            $this->assertEqualsWithDelta($after, $percentOf(1), 0.5, 'the resized width should survive a reload');
            $this->assertGridFits($browser);
        });
    }

    public function test_stored_widths_can_never_overflow_the_container(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->admin)
                ->visit('/admin/customers')
                ->waitFor('table.grid-resizable colgroup col');

            // Pixel widths left over from the v1 store, and a percentage set
            // that sums to far more than 100 — neither may widen the grid.
            $browser->driver->executeScript(
                "var n = document.querySelectorAll('table[data-grid-resizable] > colgroup > col').length;
                 localStorage.setItem('adminlte.gridColumnWidths.v1', JSON.stringify({'admin.customers.index': Array(n).fill(400)}));
                 localStorage.setItem('adminlte.gridColumnWidths.v2', JSON.stringify({'admin.customers.index': Array(n).fill(400)}));"
            );

            $browser->refresh()->waitFor('table.grid-resizable colgroup col')->pause(300);

            $this->assertEqualsWithDelta(100.0, $this->totalPercent($browser), 0.5, 'oversized stored widths are normalised back to 100%');
            $this->assertGridFits($browser);

            $this->assertNull(
                $browser->driver->executeScript("return localStorage.getItem('adminlte.gridColumnWidths.v1');"),
                'the legacy pixel store is dropped on read'
            );
        });
    }

    public function test_reset_control_clears_stored_widths(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->admin)
                ->visit('/admin/customers')
                ->waitFor('table.grid-resizable colgroup col');

            $browser->driver->executeScript(
                "var n = document.querySelectorAll('table[data-grid-resizable] > colgroup > col').length;
                 localStorage.setItem('adminlte.gridColumnWidths.v2', JSON.stringify({'admin.customers.index': Array(n).fill(100 / n)}));"
            );

            $browser->refresh()->waitFor('table.grid-resizable colgroup col')->pause(300);

            $browser->click('[data-grid-reset]')->pause(300);

            $stored = $browser->driver->executeScript(
                "var s = localStorage.getItem('adminlte.gridColumnWidths.v2'); return s ? (JSON.parse(s)['admin.customers.index'] || null) : null;"
            );
            $this->assertNull($stored, 'reset should drop the stored widths for this grid');
            $this->assertGridFits($browser);
        });
    }

    private function totalPercent(Browser $browser): float
    {
        return (float) $browser->driver->executeScript(
            "return Array.from(document.querySelectorAll('table[data-grid-resizable] > colgroup > col'))
                .reduce(function (sum, col) { return sum + parseFloat(col.style.width || 0); }, 0);"
        );
    }

    /**
     * The whole point of the feature: neither the scroll container around the
     * grid nor the page itself may end up with anything to scroll sideways.
     */
    private function assertGridFits(Browser $browser): void
    {
        $overflow = $browser->driver->executeScript(
            "var table = document.querySelector('table[data-grid-resizable]');
             var wrap = table.closest('.table-responsive') || table.parentElement;
             return {
                 table: table.getBoundingClientRect().width - wrap.clientWidth,
                 wrap: wrap.scrollWidth - wrap.clientWidth,
                 page: document.documentElement.scrollWidth - document.documentElement.clientWidth
             };"
        );

        $this->assertLessThanOrEqual(1, $overflow['table'], 'the table is wider than its container');
        $this->assertLessThanOrEqual(1, $overflow['wrap'], 'the grid container has content to scroll sideways');
        $this->assertLessThanOrEqual(1, $overflow['page'], 'the page has a horizontal scrollbar');
    }
}
