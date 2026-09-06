<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Every sortable column of every admin grid must produce a runnable query.
 *
 * The sort whitelists live in the controllers while the links are rendered by
 * the grid component, so a column mapped to a relation the sort helper cannot
 * resolve produces a broken query only when someone clicks that one header.
 * This test discovers the links from the rendered page — exactly what a user
 * can click — and follows every one of them in both directions.
 */
class GridSortRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_rendered_sort_link_returns_ok(): void
    {
        // Following several hundred links in one test trips the route throttle,
        // which is not what this test is about.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->actingAs($this->adminWithEveryPermission());

        $gridsWithSortLinks = 0;
        $linksChecked = 0;
        $failures = [];

        foreach ($this->adminIndexUris() as $name => $uri) {
            $response = $this->get($uri);

            if ($response->status() !== 200) {
                continue;
            }

            $links = $this->sortLinks($response->getContent());

            if ($links === []) {
                continue;
            }

            $gridsWithSortLinks++;

            foreach ($links as $link) {
                foreach ([$link, str_replace('direction=asc', 'direction=desc', $link)] as $target) {
                    $status = $this->get($target)->status();
                    $linksChecked++;

                    if ($status !== 200) {
                        $failures[] = "{$name}: {$target} returned {$status}";
                    }
                }
            }
        }

        $this->assertSame([], $failures, "sortable columns produced a broken query:\n".implode("\n", $failures));

        // Guards against the assertion above passing because nothing was found.
        $this->assertGreaterThanOrEqual(24, $gridsWithSortLinks, 'expected sortable headers on all 24 admin grids');
        $this->assertGreaterThanOrEqual(250, $linksChecked, 'expected to follow every sortable column in both directions');
    }

    public function test_sort_links_carry_the_column_and_direction(): void
    {
        $this->actingAs($this->adminWithEveryPermission());

        $content = $this->get(route('admin.customers.index'))->assertOk()->getContent();
        $links = $this->sortLinks($content);

        $this->assertNotEmpty($links, 'the customers grid should render sortable headers');

        foreach ($links as $link) {
            $this->assertMatchesRegularExpression('/[?&]sort=[^&]+/', $link);
            $this->assertMatchesRegularExpression('/[?&]direction=(asc|desc)/', $link);
        }
    }

    public function test_an_unknown_sort_key_is_ignored_rather_than_applied(): void
    {
        $this->actingAs($this->adminWithEveryPermission());

        $this->get(route('admin.customers.index').'?sort='.urlencode('id); drop table users--').'&direction=asc')
            ->assertOk();

        $this->get(route('admin.customers.index').'?sort=not_a_column&direction=sideways')
            ->assertOk();
    }

    /**
     * @return array<string, string>
     */
    private function adminIndexUris(): array
    {
        $uris = [];

        foreach (RouteFacade::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, 'admin.') || ! str_ends_with($name, '.index')) {
                continue;
            }

            if (! in_array('GET', $route->methods(), true) || str_contains($route->uri(), '{')) {
                continue;
            }

            $uris[$name] = '/'.ltrim($route->uri(), '/');
        }

        return $uris;
    }

    /**
     * @return list<string>
     */
    private function sortLinks(string $html): array
    {
        preg_match_all('/<a\s[^>]*href="([^"]*sort=[^"]*)"[^>]*class="[^"]*grid-sort/i', $html, $matches);

        return array_values(array_unique(array_map(
            fn (string $href) => html_entity_decode($href, ENT_QUOTES),
            $matches[1] ?? []
        )));
    }

    private function adminWithEveryPermission(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        foreach ($this->permissionNamesFromRoutes() as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->syncWithoutDetaching($permission->id);
        }

        $user->assignRole('admin');

        return $user->fresh();
    }

    /**
     * @return list<string>
     */
    private function permissionNamesFromRoutes(): array
    {
        $names = [];

        foreach (RouteFacade::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                    foreach (explode(',', substr($middleware, strlen('permission:'))) as $name) {
                        $names[] = trim($name);
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($names)));
    }
}
