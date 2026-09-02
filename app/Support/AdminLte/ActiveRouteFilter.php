<?php

namespace App\Support\AdminLte;

use ColorlibHQ\AdminLte\Menu\Filters\FilterInterface;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Route;

/**
 * Marks menu items as active based on the current route name.
 *
 * The package's stock ActiveFilter only knows how to light up items defined
 * with a literal `url` key. This app's whole sidebar (config/adminlte.php)
 * is built from named `route` keys instead, so the stock filter never matches
 * and no menu item ever receives the `active` class.
 *
 * This filter replaces it. It keeps the stock behavior for `url`-based and
 * explicit `active`-pattern items, and adds route-name matching:
 *
 *   - Exact route match lights the item.
 *   - A *listing* item (route action `index`/`show`) is also kept active for
 *     any other route in the same resource group (e.g. admin.orders.index
 *     stays highlighted on admin.orders.show / .status), so the current module
 *     and its parent treeview stay open as you move through a resource.
 *   - create/store siblings (e.g. admin.orders.create) are excluded from the
 *     group match: those pages are highlighted by their own menu item.
 *
 * Submenu parents become active when any of their children is active, which
 * is what opens the parent treeview group.
 */
class ActiveRouteFilter implements FilterInterface
{
    /**
     * Action suffixes that mark an item as the "resource listing", so it also
     * matches sibling detail routes inside the same resource group.
     *
     * @var array<int, string>
     */
    private const LISTING_ACTIONS = ['index', 'show'];

    /**
     * Actions owned by their own sibling menu items (usually "New X" / a POST
     * handler). Excluded from the resource-group match to avoid highlighting
     * the listing item on those pages.
     *
     * @var array<int, string>
     */
    private const SIBLING_ACTIONS = ['create', 'store'];

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    public function transform(array $item): ?array
    {
        if (isset($item['submenu'])) {
            $item['submenu'] = array_map(
                fn (array $child) => $this->transform($child) ?? $child,
                $item['submenu']
            );

            // Parent is active when any child is (opens the treeview group).
            foreach ($item['submenu'] as $child) {
                if (! empty($child['active'])) {
                    $item['active'] = true;
                    break;
                }
            }
        }

        // Explicit boolean already set — respect it.
        if (isset($item['active']) && is_bool($item['active'])) {
            return $item;
        }

        // Explicit path patterns (e.g. ['admin/tickets/open*']) — URL matching.
        if (! empty($item['active']) && is_array($item['active'])) {
            $item['active'] = $this->matchesAnyUrl((array) $item['active']);
        } elseif (isset($item['route'])) {
            $item['active'] = $this->matchesRoute($item['route']);
        } elseif (isset($item['url']) && $item['url'] !== '#' && $item['url'] !== '/') {
            $patterns = [trim($item['url'], '/'), trim($item['url'], '/').'/*'];
            $item['active'] = $this->matchesAnyUrl($patterns);
        } elseif (($item['url'] ?? null) === '/') {
            $item['active'] = Request::path() === '/';
        } else {
            $item['active'] = false;
        }

        return $item;
    }

    /**
     * Match a menu item's declared route (string or [name, params] array)
     * against the current route name.
     *
     * @param  string|array<int, mixed>  $route
     */
    private function matchesRoute(string|array $route): bool
    {
        $routeName = is_array($route) ? (string) $route[0] : $route;
        $current = Route::currentRouteName();

        if ($current === null || $routeName === '') {
            return false;
        }

        // Exact match wins.
        if ($current === $routeName) {
            return true;
        }

        // Only the listing item extends into the resource group.
        $lastDot = strrpos($routeName, '.');
        if ($lastDot === false) {
            return false;
        }

        $group = substr($routeName, 0, $lastDot);
        $action = substr($routeName, $lastDot + 1);

        if (! in_array($action, self::LISTING_ACTIONS, true)) {
            return false;
        }

        // Current route must live inside the same resource group ...
        if (! str_starts_with($current, $group.'.')) {
            return false;
        }

        // ... and must not be one of the create/store siblings that have their
        // own menu item.
        $currentTail = substr($current, strlen($group) + 1);
        $currentAction = (string) strtok($currentTail, '.');

        return ! in_array($currentAction, self::SIBLING_ACTIONS, true);
    }

    /**
     * Match the current request path against any of the given URL patterns
     * (same semantics as Laravel's Request::is wildcards).
     *
     * @param  array<int, mixed>  $patterns
     */
    private function matchesAnyUrl(array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === '/' && Request::path() === '/') {
                return true;
            }
            if ($pattern !== '/' && Request::is((string) $pattern)) {
                return true;
            }
        }

        return false;
    }
}