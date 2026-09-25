<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Ctrl/Cmd+K palette is rendered on every admin page through the navbar
 * partial. This is the bounded server-rendered proof for the palette todo: the
 * palette root carries the typeahead URL its IIFE needs to fetch grouped record
 * results, and the page still renders. The interactive record-render proof is
 * browser QA (todo 13), which this test deliberately does not duplicate.
 */
class CommandPaletteRecordsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mirrors AdminSearchTest::actingAsAdmin(): a real authenticated panel user
     * that holds the page gate (`search`) plus the sidebar baseline the navbar
     * renders against.
     */
    private function actingAsAdmin()
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        foreach (['search', 'dashboard.view'] as $permName) {
            $perm = Permission::firstOrCreate(['name' => $permName], ['label' => ucfirst($permName)]);
            $adminRole->permissions()->syncWithoutDetaching($perm->id);
        }

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    public function test_the_rendered_palette_wires_the_typeahead_url_and_keeps_the_listbox_intact(): void
    {
        $html = $this->actingAsAdmin()
            ->get('/admin/search?q=acme')
            ->assertOk()
            ->getContent();

        // The palette root exists and hands the IIFE the endpoint it fetches.
        $this->assertStringContainsString('id="adminlteCommandPalette"', $html);
        $this->assertStringContainsString(
            'data-typeahead-url="'.route('admin.search.typeahead').'"',
            $html
        );

        // The full-results page URL is wired too, because the "View all results"
        // row is built client-side from it.
        $this->assertStringContainsString(
            'data-search-url="'.route('admin.search.index').'"',
            $html
        );

        // The combobox and its listbox are untouched: the record rows append
        // into the same keyboard-navigable list.
        $this->assertStringContainsString('id="adminlteCommandPaletteInput"', $html);
        $this->assertStringContainsString('id="adminlteCommandPaletteResults"', $html);

        // Bounded static proof of two behaviors the browser pass (todo 13)
        // exercises interactively: the fetch is abortable, and a query shorter
        // than two characters never leaves the client.
        $this->assertStringContainsString('AbortController', $html);
        $this->assertStringContainsString('term.length < 2', $html);
    }

    /**
     * The "View all results" row links to admin.search.index, whose page reads
     * ONLY the `q` query parameter. The row is built client-side, so the served
     * page proves the construction it will use:
     * href = searchUrl + '?q=' + encodeURIComponent(raw).
     *
     * Where each surface reads it: the results page uses the validated `q`
     * (SearchController::search() -> `$validated['q']`), while the palette
     * endpoint reads `$request->query('q', '')`
     * (SearchController::typeahead()).
     *
     * Regression guard: the earlier '?search=' construction silently dropped
     * the term on the results page.
     */
    public function test_the_view_all_results_row_targets_the_q_parameter(): void
    {
        $html = $this->actingAsAdmin()
            ->get('/admin/search?q=acme')
            ->assertOk()
            ->getContent();

        // Exact construction, including encodeURIComponent, against the
        // server-derived URL the palette root carries.
        $this->assertStringContainsString(
            "searchUrl + '?q=' + encodeURIComponent(raw)",
            $html
        );

        // The wrong construction must not exist anywhere in the page: the
        // destination does not read `search`, so this exact expression is a bug.
        $this->assertStringNotContainsString(
            "searchUrl + '?search='",
            $html
        );
    }
}
