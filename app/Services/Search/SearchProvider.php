<?php

declare(strict_types=1);

namespace App\Services\Search;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * One searchable entity in the global search registry.
 *
 * A provider owns four things: how it is presented (key/label/icon), what
 * permission guards its rows, where a result lives (showRoute/listRoute), and
 * how its rows are matched and rendered (query/toResult). It never decides
 * *whether* the viewer may search — that is the caller's permission set.
 */
interface SearchProvider
{
    /** Stable group identifier, e.g. `customers`. */
    public function key(): string;

    /** Human label for the group header, e.g. `Customers`. */
    public function label(): string;

    /** Bootstrap icon class rendered next to the group header. */
    public function icon(): string;

    /** Permission the viewer must hold for this provider to run at all. */
    public function permission(): string;

    /** Named route a single result links to. */
    public function showRoute(): string;

    /** Named route of the entity's list screen, or null when it has none. */
    public function listRoute(): ?string;

    /**
     * Matching rows, ranked by closeness, capped at $limit.
     *
     * @return Collection<int, Model>
     */
    public function query(string $term, int $limit): Collection;

    /**
     * One result row. `url` is resolved server-side from showRoute(), never
     * accepted from the client.
     *
     * @return array{id: int|string, label: string, subtitle: string|null, url: string}
     */
    public function toResult(Model $model): array;
}
