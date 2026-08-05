<?php

namespace App\Support\AdminLte;

use ColorlibHQ\AdminLte\Menu\Filters\FilterInterface;
use Illuminate\Support\Facades\Route;

/**
 * Drops sidebar menu items whose named route isn't defined yet.
 *
 * The full sidebar references routes that are built incrementally across plan
 * sessions (customers, products, orders, ...). Without this filter, rendering
 * the menu would throw RouteNotFoundException for every not-yet-built module.
 * Runs before HrefFilter in the menu filter pipeline.
 */
class RouteExistsFilter implements FilterInterface
{
    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    public function transform(array $item): ?array
    {
        if (isset($item['submenu'])) {
            $children = [];
            foreach ($item['submenu'] as $child) {
                $child = $this->transform($child);
                if ($child !== null) {
                    $children[] = $child;
                }
            }

            // Drop a parent whose entire submenu is not yet built.
            if ($children === []) {
                return null;
            }

            $item['submenu'] = $children;
        }

        if (isset($item['route'])) {
            $name = is_array($item['route']) ? $item['route'][0] : $item['route'];

            if (! Route::has($name)) {
                return null;
            }
        }

        return $item;
    }
}
