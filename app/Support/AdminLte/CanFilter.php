<?php

namespace App\Support\AdminLte;

use ColorlibHQ\AdminLte\Menu\Filters\FilterInterface;
use Illuminate\Support\Facades\Gate;

/**
 * Applies `can` gates to sidebar menu items at every level of the tree.
 *
 * The package's own GateFilter only ever sees each top-level item: the filter
 * pipeline hands a filter the whole item and never walks into its `submenu`.
 * A parent that passes its own gate therefore renders its entire submenu
 * unconditionally, and every child's `can` key was silently ignored — a
 * support agent with `tickets.view` but no `settings.view` was still shown
 * "Create Ticket" and "Departments". This filter closes that hole by
 * recursing into children and gating each one itself, keeping the package's
 * semantics: a `can` array passes when ANY listed ability is allowed, and
 * `can_params` is forwarded to the gate untouched.
 *
 * The `role` key is deliberately left to RoleFilter, which runs earlier in
 * the chain.
 */
class CanFilter implements FilterInterface
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

            // Drop a parent whose entire submenu is denied, rather than
            // rendering an empty treeview stub that expands to nothing.
            if ($children === []) {
                return null;
            }

            $item['submenu'] = $children;
        }

        if (! isset($item['can'])) {
            return $item;
        }

        // No authenticated gate available — leave item as-is.
        if (! class_exists(Gate::class)) {
            return $item;
        }

        $params = $item['can_params'] ?? [];

        foreach ((array) $item['can'] as $ability) {
            if (Gate::allows($ability, $params)) {
                return $item;
            }
        }

        return null;
    }
}
