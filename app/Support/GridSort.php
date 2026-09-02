<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Server-side column sorting for <x-adminlte.partials.datatable>.
 *
 * A controller opts in with a single whitelisted mapping:
 *
 *     $query->gridSort([
 *         'name'   => 'name',                       // direct column
 *         'email'  => 'user.email',                 // relation via subquery
 *         'status' => ['status', '='],              // same as GridFilters style
 *         'custom' => fn (Builder $q, string $dir) => $q->orderBy(...),
 *     ]);
 *
 * Only keys present in the whitelist can be requested via ?sort=key&direction=asc|desc.
 * Invalid keys or directions are ignored and the caller falls back to its default ordering.
 */
class GridSort
{
    public static function register(): void
    {
        Builder::macro('gridSort', function (array $allowed, ?string $defaultColumn = null, string $defaultDirection = 'asc') {
            /** @var Builder $this */
            $sort = trim((string) request()->query('sort', ''));
            $direction = strtolower(trim((string) request()->query('direction', $defaultDirection))) === 'desc' ? 'desc' : 'asc';

            if ($sort === '' || ! isset($allowed[$sort])) {
                if ($defaultColumn !== null && isset($allowed[$defaultColumn])) {
                    \App\Support\GridSort::applySort($this, $allowed[$defaultColumn], $defaultDirection);
                }

                return $this;
            }

            $definition = $allowed[$sort];
            \App\Support\GridSort::applySort($this, $definition, $direction);

            return $this;
        });
    }

    /**
     * Apply a single sort definition to the query.
     */
    public static function applySort(Builder $query, mixed $definition, string $direction): void
    {
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        if ($definition instanceof \Closure) {
            $definition($query, $direction);

            return;
        }

        [$target, $operator] = GridFilters::resolve($definition);

        if (str_contains($target, '.')) {
            $relation = Str::beforeLast($target, '.');
            $column = Str::afterLast($target, '.');
            $table = $query->getModel()->getTable();

            // Generic guard: if the relation looks like a single word, infer the FK as {relation}_id.
            // For deeper or mismatched FKs the caller should use a Closure instead.
            $foreignKey = Str::snake($relation) . '_id';

            // Validate the foreign key column actually exists on the parent table before using the subquery path.
            // If we cannot infer the FK safely, fall back to a plain orderBy on the relation-less column.
            try {
                $hasFk = \Illuminate\Support\Facades\Schema::hasColumn($table, $foreignKey);
            } catch (\Throwable) {
                $hasFk = false;
            }

            if ($hasFk) {
                $relatedModel = null;
                // Try to resolve the related model via the relation method if it exists, to get its table name.
                $model = $query->getModel();
                if (method_exists($model, $relation)) {
                    try {
                        $related = $model->{$relation}();
                        $relatedModel = $related->getRelated();
                    } catch (\Throwable) {
                        $relatedModel = null;
                    }
                }

                if ($relatedModel) {
                    $relatedTable = $relatedModel->getTable();
                    $relatedKey = $relatedModel->getKeyName();

                    $query->orderBy(
                        $relatedModel::select($column)
                            ->whereColumn("{$relatedTable}.{$relatedKey}", "{$table}.{$foreignKey}")
                            ->limit(1),
                        $direction
                    );

                    return;
                }
            }

            // The leaf column belongs to the related table, not this one, so ordering
            // by it here would be a SQL error rather than a degraded sort. Leave the
            // caller's default ordering in place; a relation whose FK cannot be
            // inferred should be declared as a Closure instead.
            return;
        }

        $query->orderBy($target, $direction);
    }
}
