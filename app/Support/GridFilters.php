<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Server-side per-column filtering for <x-adminlte.partials.datatable>.
 *
 * The grid renders one input per filterable column named `f[key]`; a controller
 * opts in with a single call naming the columns it will accept:
 *
 *     $query->gridFilters([
 *         'name'    => 'name',              // column on this table
 *         'email'   => 'user.email',        // dot path = whereHas on a relation
 *         'status'  => ['status', '='],     // exact match instead of LIKE
 *         'contact' => fn ($q, $v) => $q->whereHas(...),   // anything else
 *     ]);
 *
 * Filtering is deliberately server-side: these grids are paginated at 20-50
 * rows, so a client-side filter would only search the page you happen to be on
 * and report "no matches" while matching rows sat on page 7.
 *
 * Keys absent from the whitelist are ignored, so a crafted `f[...]` parameter
 * can never reach the query builder.
 */
class GridFilters
{
    public static function register(): void
    {
        Builder::macro('gridFilters', function (array $allowed) {
            /** @var Builder $this */
            $requested = array_filter(
                (array) request()->query('f', []),
                fn ($value) => is_scalar($value) && trim((string) $value) !== ''
            );

            foreach ($requested as $key => $value) {
                if (! isset($allowed[$key])) {
                    continue;
                }

                $definition = $allowed[$key];
                $value = trim((string) $value);

                if ($definition instanceof \Closure) {
                    $definition($this, $value);

                    continue;
                }

                [$target, $operator] = GridFilters::resolve($definition);

                if (str_contains($target, '.')) {
                    $relation = Str::beforeLast($target, '.');
                    $column = Str::afterLast($target, '.');

                    $this->whereHas($relation, function (Builder $query) use ($column, $operator, $value) {
                        GridFilters::constrain($query, $column, $operator, $value);
                    });

                    continue;
                }

                GridFilters::constrain($this, $target, $operator, $value);
            }

            return $this;
        });
    }

    /**
     * @param  string|array{0: string, 1?: string}  $definition
     * @return array{0: string, 1: string}
     */
    public static function resolve(string|array $definition): array
    {
        if (is_string($definition)) {
            return [$definition, 'like'];
        }

        return [$definition[0], $definition[1] ?? 'like'];
    }

    public static function constrain(Builder $query, string $column, string $operator, string $value): void
    {
        if ($operator === 'like') {
            $query->where($column, 'like', '%'.$value.'%');

            return;
        }

        $query->where($column, $operator, $value);
    }
}
