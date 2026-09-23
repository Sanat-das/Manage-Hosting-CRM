<?php

declare(strict_types=1);

namespace App\Services\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * The shared machinery behind every provider.
 *
 * Subclasses declare what to match (`searchableColumns()` /
 * `searchableRelations()`) and where to read from (`baseQuery()`); this class
 * owns the parts that must be identical everywhere: the grouped WHERE, the
 * escaped LIKE, the closeness ranking applied before the LIMIT, and the
 * skip-if-not-installed guard.
 */
abstract class AbstractSearchProvider implements SearchProvider
{
    /**
     * Route names already reported missing, so a registry entry for a screen
     * that is not installed logs once instead of on every keystroke.
     *
     * @var array<string, true>
     */
    private static array $loggedMissingRoutes = [];

    /**
     * Plain columns on the base table that the term is matched against.
     *
     * @return list<string>
     */
    abstract protected function searchableColumns(): array;

    /**
     * Relation name => columns on that relation, matched through a grouped
     * whereHas. Kept apart from searchableColumns() so the term's ORs stay
     * inside the relation's own subquery.
     *
     * @return array<string, list<string>>
     */
    protected function searchableRelations(): array
    {
        return [];
    }

    /**
     * The query the term filter and the ranking are applied to.
     */
    abstract protected function baseQuery(): Builder;

    public function query(string $term, int $limit): Collection
    {
        $term = trim($term);

        if ($term === '' || ! $this->routeIsRegistered()) {
            return collect();
        }

        $pattern = LikePattern::contains($term);

        $query = $this->baseQuery();

        $this->applyTermFilter($query, $pattern);
        $this->applyRanking($query, $term, $pattern);

        return $query->limit($limit)->get();
    }

    /**
     * Column whose closeness decides the rank. Defaults to the first plain
     * column; a provider whose strongest identifier lives elsewhere overrides.
     */
    protected function rankColumn(): string
    {
        return $this->searchableColumns()[0] ?? 'id';
    }

    /**
     * The grouped WHERE template: every column OR'd inside ONE group, so a
     * constraint applied around it can never be widened by the term's ORs.
     */
    protected function applyTermFilter(Builder $query, string $pattern): void
    {
        $columns = $this->searchableColumns();
        $relations = $this->searchableRelations();

        if ($columns === [] && $relations === []) {
            // Nothing to match must match nothing — never everything.
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $group) use ($columns, $relations, $pattern): void {
            foreach ($columns as $column) {
                $group->orWhere(function (Builder $inner) use ($column, $pattern): void {
                    $this->whereLikeEscaped($inner, $column, $pattern);
                });
            }

            foreach ($relations as $relation => $relationColumns) {
                $group->orWhereHas($relation, function (Builder $relationQuery) use ($relationColumns, $pattern): void {
                    $relationQuery->where(function (Builder $relationGroup) use ($relationColumns, $pattern): void {
                        foreach ($relationColumns as $column) {
                            $relationGroup->orWhere(function (Builder $inner) use ($column, $pattern): void {
                                $this->whereLikeEscaped($inner, $column, $pattern);
                            });
                        }
                    });
                });
            }
        });
    }

    /**
     * The one place user text becomes a LIKE condition: the column is wrapped
     * by the grammar (never interpolated), the pattern is bound, and the
     * ESCAPE character is named explicitly because the default differs between
     * MySQL and SQLite.
     */
    protected function whereLikeEscaped(Builder $query, string $column, string $pattern): Builder
    {
        return $query->whereRaw(
            $query->getGrammar()->wrap($column).' LIKE ? ESCAPE \''.LikePattern::ESCAPE.'\'',
            [$pattern],
        );
    }

    /**
     * Closeness ranking, in SQL and BEFORE the LIMIT: an exact match can never
     * be cut off by a page of looser ones. The LIKE reuses the escaped pattern,
     * so it names the same ESCAPE character as the WHERE.
     */
    protected function applyRanking(Builder $query, string $term, string $pattern): void
    {
        $column = $query->getGrammar()->wrap($this->rankColumn());
        $escape = LikePattern::ESCAPE;

        $query->orderByRaw(
            "(CASE WHEN LOWER({$column}) = LOWER(?) THEN 0 WHEN LOWER({$column}) LIKE ? ESCAPE '{$escape}' THEN 1 ELSE 2 END), id DESC",
            [$term, $pattern],
        );
    }

    /**
     * The shared result shape; `url` always comes from the provider's own show
     * route, so a client can never supply a destination.
     *
     * @return array{id: int|string, label: string, subtitle: string|null, url: string}
     */
    protected function resultRow(Model $model, string $label, ?string $subtitle = null): array
    {
        return [
            'id' => $model->getKey(),
            'label' => $label !== '' ? $label : '#'.$model->getKey(),
            'subtitle' => $subtitle,
            'url' => route($this->showRoute(), $model),
        ];
    }

    /**
     * A provider whose destination route is not registered cannot produce a
     * usable result, so it is skipped — never thrown — and the skip is logged
     * once per route.
     */
    private function routeIsRegistered(): bool
    {
        $route = $this->showRoute();

        if (Route::has($route)) {
            return true;
        }

        if (! isset(self::$loggedMissingRoutes[$route])) {
            self::$loggedMissingRoutes[$route] = true;

            Log::warning('Global search provider skipped: its show route is not registered.', [
                'provider' => static::class,
                'route' => $route,
            ]);
        }

        return false;
    }
}
