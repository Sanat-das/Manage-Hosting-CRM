<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * The search registry: term + permission set in, grouped results out.
 *
 * The permission set is resolved ONCE per request (`permissionNames()`).
 * `User::hasPermission()` costs one to two queries per call
 * (`HasRoles::hasPermission()`), and the palette asks every provider on every
 * keystroke — a per-provider permission check would add 17-34 queries each
 * time.
 */
class GlobalSearchService
{
    /**
     * Provider classes already reported missing, so a config entry for a class
     * that has not shipped yet logs once instead of on every keystroke.
     *
     * @var array<string, true>
     */
    private static array $loggedMissingProviders = [];

    /**
     * @param  list<class-string<SearchProvider>>|null  $providerClasses
     *         Explicit list for tests and narrow surfaces; null reads
     *         config/search.php.
     */
    public function __construct(private readonly ?array $providerClasses = null) {}

    /**
     * The registry, in configured order, with not-yet-installed entries
     * skipped.
     *
     * @return list<SearchProvider>
     */
    public function providers(): array
    {
        $providers = [];

        foreach ($this->providerClasses() as $class) {
            if (! class_exists($class) || ! is_subclass_of($class, SearchProvider::class)) {
                $this->logMissingProvider($class);

                continue;
            }

            $providers[] = app($class);
        }

        return $providers;
    }

    /**
     * Grouped results for the providers the viewer may search.
     *
     * One extra row per provider is fetched as the capped count: `has_more`
     * reports a truncated group without a COUNT(*) query.
     *
     * @param  list<string>  $permissionNames
     * @return list<array{
     *     key: string,
     *     label: string,
     *     icon: string,
     *     results: list<array{id: int|string, label: string, subtitle: string|null, url: string}>,
     *     has_more: bool,
     *     list_url: string|null
     * }>
     */
    public function groups(array $permissionNames, string $term, int $limitPerType): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        // SQLite reads a non-positive LIMIT as "no limit at all"; clamp it away.
        $limitPerType = max(1, $limitPerType);
        $groups = [];

        foreach ($this->providers() as $provider) {
            if (! in_array($provider->permission(), $permissionNames, true)) {
                continue;
            }

            $rows = $provider->queryFor($permissionNames, $term, $limitPerType + 1);
            $hasMore = $rows->count() > $limitPerType;

            if ($rows->isEmpty()) {
                continue;
            }

            $groups[] = [
                'key' => $provider->key(),
                'label' => $provider->label(),
                'icon' => $provider->icon(),
                'results' => $rows
                    ->take($limitPerType)
                    ->map(fn (Model $model) => $provider->toResult($model))
                    ->values()
                    ->all(),
                'has_more' => $hasMore,
                'list_url' => $this->listUrl($provider, $term),
            ];
        }

        return $groups;
    }

    /**
     * Every permission name the user holds, in ONE query, with each `x.manage`
     * expanded into its implied `x.view` twin.
     *
     * Mirrors `HasRoles::hasPermission()`: the permissions of the pivot-assigned
     * roles, plus those of the Role named by the legacy `users.role` column.
     *
     * @return list<string>
     */
    public function permissionNames(User $user): array
    {
        $names = Permission::query()
            ->where(function (Builder $query) use ($user): void {
                $query->whereHas('roles.users', fn (Builder $users) => $users->whereKey($user->getKey()));

                if (filled($user->role)) {
                    $query->orWhereHas('roles', fn (Builder $roles) => $roles->where('adminlte_roles.name', $user->role));
                }
            })
            ->pluck('name');

        return $this->withImpliedViewPermissions($names->unique()->values()->all());
    }

    /**
     * `x.manage` implies `x.view`, exactly as `PermissionMiddleware::handle()`
     * enforces for route gates - keep the two in step. A role the app admits to
     * a screen must also be able to search that screen's records; without this,
     * a custom role holding only `hosting.manage` (or `service-instances.manage`,
     * `domains.manage`, `catalog-products.manage`) opened the screens and got
     * zero search results for them.
     *
     * Pure string expansion on the already-plucked set: it must never add a
     * query, and it only ever widens `manage` -> `view`, never the reverse.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function withImpliedViewPermissions(array $names): array
    {
        $expanded = $names;

        foreach ($names as $name) {
            if (str_ends_with($name, '.manage')) {
                $expanded[] = substr($name, 0, -strlen('.manage')).'.view';
            }
        }

        return array_values(array_unique($expanded));
    }

    /**
     * @return list<class-string>
     */
    private function providerClasses(): array
    {
        $classes = $this->providerClasses ?? config('search.providers', []);

        return array_values(array_filter((array) $classes, 'is_string'));
    }

    /**
     * "View all" destination for a group, or null when the list screen is not
     * installed — never a broken link.
     */
    private function listUrl(SearchProvider $provider, string $term): ?string
    {
        $route = $provider->listRoute();

        if ($route === null || ! Route::has($route)) {
            return null;
        }

        return route($route, ['search' => $term]);
    }

    private function logMissingProvider(string $class): void
    {
        if (isset(self::$loggedMissingProviders[$class])) {
            return;
        }

        self::$loggedMissingProviders[$class] = true;

        Log::warning('Global search provider skipped: class is not available.', [
            'provider' => $class,
        ]);
    }
}
