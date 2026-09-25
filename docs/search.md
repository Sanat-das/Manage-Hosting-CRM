# Global Search: Architecture, Routes, and Extension

This doc is the single reference for the global search system that ships inside the panel. It covers the provider registry, the permission-per-provider model, the `!` ESCAPE rule, the permission-aware `queryFor()` hook, the two HTTP surfaces (the Ctrl/Cmd+K command palette and the full results page), the throttle budget, and the steps to add a new provider in one file.

> Scope reminder: admin/staff only. There is no client-portal global search. Chat message search is a separate endpoint (`routes/admin/config.php`) and is not part of this system. Matching is database-native `LIKE`: no external search engine (Meilisearch, Typesense, Scout) is involved. The original plan lives at `.omo/plans/global-search.md`.

---

## 1. Overview

One search box, two surfaces, one registry:

| Surface | Where | What it renders |
|---|---|---|
| Command palette | Every admin page, opened with Ctrl/Cmd+K | The client-side **Navigation** group (sidebar links) plus a server-fetched **Records** group from the JSON typeahead endpoint, and a "View all results" row. Labels and subtitles render with `textContent` only; URLs are resolved server-side. |
| Full results page | `/admin/search?q=` | Every permitted provider's group, capped at 10 rows per type, with per-type count labels and a "View all" link into the matching list screen. |

Both surfaces call the same service, `App\Services\Search\GlobalSearchService`, which owns a registry of per-entity providers listed in `config/search.php`. Each provider declares the permission that guards its rows. The service resolves the viewer's permission set once per request and skips any provider the viewer may not read, so a group the viewer is not allowed to see is never built.

The curated set is 17 providers:

| # | Provider | `key()` | Matched fields | Guard permission | Show route | "View all" route |
|---|---|---|---|---|---|---|
| 1 | `CustomerSearchProvider` | `customers` | `customers.company`, `user.email`, `user.first_name`, `user.last_name` | `customers.view` | `admin.customers.show` | `admin.customers.index` |
| 2 | `ContactSearchProvider` | `contacts` | `first_name`, `last_name`, `email` | `customers.view` | `admin.customers.show` (the parent customer) | `admin.customers.index` |
| 3 | `StaffUserSearchProvider` | `staff` | `email`, `first_name`, `last_name` (`role = client` excluded) | `users.view` | `admin.users.show` | `admin.users.index` |
| 4 | `OrderSearchProvider` | `orders` | `order_number`, `domain_name` | `orders.view` | `admin.orders.show` | `admin.orders.index` |
| 5 | `InvoiceSearchProvider` | `invoices` | `invoice_no` | `invoices.view` | `admin.invoices.show` | `admin.invoices.index` |
| 6 | `PaymentSearchProvider` | `payments` | `transaction_id` | `payments.view` | `admin.payments.show` | `admin.payments.index` |
| 7 | `TransactionSearchProvider` | `transactions` | `transaction_id` | `invoices.view` | `admin.transactions.show` | `admin.transactions.index` |
| 8 | `QuoteSearchProvider` | `quotes` | `quote_no`, `subject` | `invoices.view` | `admin.quotes.show` | `admin.quotes.index` |
| 9 | `ServiceInstanceSearchProvider` | `service-instances` | `service_tag`, `username`, `domain` | `service-instances.view` | `admin.service-instances.show` | `admin.service-instances.index` |
| 10 | `HostingAccountSearchProvider` | `hosting` | `username`, `domain`, `host_name` | `hosting.view` | `admin.hosting.show` | `admin.hosting.index` |
| 11 | `ServerSearchProvider` | `servers` | `name`, `ip_address`, `server_type` | `hosting.view` | `admin.servers.show` | `admin.servers.index` |
| 12 | `DomainSearchProvider` | `domains` | `name` | `domains.view` | `admin.domains.show` | `admin.domains.index` |
| 13 | `SslCertificateSearchProvider` | `ssl` | `domain_name`, `provider` | `hosting.view` (no `ssl.*` permission exists) | `admin.ssl.show` | `admin.ssl.index` |
| 14 | `TicketSearchProvider` | `tickets` | `ticket_no`, `subject` | `tickets.view` | `admin.tickets.show` | `admin.tickets.index` |
| 15 | `KnowledgeBaseSearchProvider` | `kb` | `title`, `content` (the subtitle is the category only) | `kb.view` | `admin.kb.show` | `admin.kb.index` |
| 16 | `CatalogProductSearchProvider` | `catalog-products` | `name`, `sku` | `catalog-products.view` | `admin.catalog-products.show` | `admin.catalog-products.index` |
| 17 | `ProductSearchProvider` | `products` | `name` | `products.view` | `admin.products.show` | `admin.products.index` |

Providers outside this set (infrastructure, IPAM/DNS, audit and email logs, licenses, settings, modules, cron tasks) are deliberately out of scope today. Each is one provider to add later, following section 9.

---

## 2. The provider registry

### 2.1 The contract: `App\Services\Search\SearchProvider`

One provider owns four things: how it is presented, what permission guards its rows, where a result lives, and how rows are matched and rendered. The interface methods are:

| Method | Purpose |
|---|---|
| `key(): string` | Stable group identifier, e.g. `customers`. |
| `label(): string` | Human group header, e.g. `Customers`. |
| `icon(): string` | Bootstrap icon class for the group header. |
| `permission(): string` | The permission the viewer must hold for this provider to run at all. |
| `showRoute(): string` | Named route a single result links to. |
| `listRoute(): ?string` | Named route of the entity's list screen, or `null` when it has none. |
| `query(string $term, int $limit): Collection` | Matching rows, ranked by closeness, capped at `$limit`. |
| `queryFor(array $permissionNames, string $term, int $limit): Collection` | The same pipeline with the viewer's permission set available (section 5). |
| `toResult(Model $model): array` | One result row: `['id', 'label', 'subtitle', 'url']`, with `url` resolved server-side from `showRoute()`. |

### 2.2 The shared machinery: `AbstractSearchProvider`

Every provider extends `App\Services\Search\AbstractSearchProvider`, which owns the parts that must be identical everywhere. A subclass declares:

| Hook | Default | Purpose |
|---|---|---|
| `searchableColumns(): array` | abstract | Plain columns on the base table that the term is matched against. |
| `searchableRelations(): array` | `[]` | Relation name to columns, matched through a grouped `whereHas`. |
| `baseQuery(): Builder` | abstract | The query the term filter and ranking are applied to. |
| `baseQueryFor(array $permissionNames): Builder` | `baseQuery()` | Permission-aware base query hook (section 5). |
| `rankColumn(): string` | first searchable column | The column whose closeness decides the rank. |

The base class then owns the fixed pipeline: trim the term, skip when the term is empty or the show route is not registered, build the escaped pattern, apply the grouped `WHERE`, apply the SQL-side ranking, and apply the `LIMIT`. It also provides `resultRow()` so every provider builds the same result shape.

The route guard is the skip-if-not-installed rule: a provider whose `showRoute()` is not present in `Route::has()` returns no rows and logs one warning per route, never throws. The same applies to a `config/search.php` entry whose class has not shipped yet.

### 2.3 The service: `GlobalSearchService`

- `providers(): array` reads `config/search.php`, skips class entries that do not exist or do not implement the interface (logged once per class), and resolves the rest through the container.
- `groups(array $permissionNames, string $term, int $limitPerType): array` is the only entry point the HTTP layer uses. It skips providers whose permission is absent, fetches `limit + 1` rows per provider as a capped count, and returns groups shaped as `key`, `label`, `icon`, `results`, `has_more`, `list_url`.
- `permissionNames(User $user): array` resolves every permission name the user holds in a single pluck (section 3).

The `limit + 1` fetch is the count strategy: `has_more` is true when the extra row exists, so no provider ever runs a separate `COUNT(*)` query.

---

## 3. Permissions

### 3.1 Permission per provider, resolved once per request

Each provider names exactly one permission in `permission()`. `GlobalSearchService::groups()` skips a provider when its permission is not in the caller's pre-resolved permission set:

```php
if (! in_array($provider->permission(), $permissionNames, true)) {
    continue;
}
```

The set comes from `GlobalSearchService::permissionNames(User $user)`, which plucks all permission names in **one query** (pivot-assigned roles plus the role named by the legacy `users.role` column). It is called once per request by the controller, never per provider and never per row. `User::hasPermission()` costs one to two queries per call, so checking it inside the provider loop would add 17 to 34 queries per keystroke. Providers therefore never call `hasPermission()`; a provider that needs to vary its query by permission reads the array it is handed (section 5).

`x.manage` implies `x.view`: `PermissionMiddleware` admits a `.view`-gated route when the user holds the `.manage` twin (section 3.2). The search set mirrors that rule — `permissionNames()` expands every held `x.manage` name into its `x.view` twin (`withImpliedViewPermissions()`) — so a custom role holding only `service-instances.manage` (or `hosting.manage`, `domains.manage`, `catalog-products.manage`) gets the groups whose screens the app already admits it to, instead of opening the screen and finding nothing. The expansion is pure string work on the plucked set: the resolution stays **one query**, it never widens `.view` → `.manage`, and exact membership still decides everything else. No shipped role holds a `.manage` permission by default (`admin` excepted), so this affects custom roles created in the Roles UI.

### 3.2 The route gate

Both search routes carry `permission:search`. `App\Http\Middleware\PermissionMiddleware` aborts with a 403 when the authenticated user lacks it. The routes also sit inside the standard admin group (`web`, `auth`, `admin`), so guests are redirected to the login page and client-role accounts are refused by `AdminMiddleware` before search runs.

### 3.3 Seeder and backfill migration

`search` ("Use Global Search") is declared in `database/seeders/AdminLteRbacSeeder.php` and granted to `admin` (via the full permission set) plus `support`, `sales`, `marketing`, `staff`, `editor`, and `viewer`. Those are exactly the panel roles that could reach the old page, so no role gains or loses access.

Because the in-app updater runs `php artisan migrate --force` and never `db:seed`, `database/migrations/2026_09_23_000001_backfill_search_permission.php` attaches `search` to every role that already holds `dashboard.view` on an existing install. It is idempotent (`insertOrIgnore`), guarded on the RBAC tables existing, and its `down()` is a no-op.

### 3.4 Worked example: `staff` sees no server rows

The `staff` role deliberately lacks `hosting.view` (the permission discloses stored server credentials). `ServerSearchProvider`, `HostingAccountSearchProvider`, and `SslCertificateSearchProvider` all declare `hosting.view`, so a `staff` user's results simply never include those groups, on either surface, with no per-row filtering involved.

---

## 4. The escaping rule: `LIKE ? ESCAPE '!'`

`App\Services\Search\LikePattern` is the one place a search term becomes a `LIKE` pattern.

- `LikePattern::ESCAPE` is the literal `!`.
- `LikePattern::contains($term)` escapes the escape character first, then the two `LIKE` metacharacters, and wraps the result in leading and trailing `%`:

```php
'%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%'
```

So `100%_!` becomes `%100!%!_!!%`.

Every provider column is matched through `AbstractSearchProvider::whereLikeEscaped()`, which emits:

```php
$query->whereRaw($query->getGrammar()->wrap($column)." LIKE ? ESCAPE '!'", [$pattern])
```

Three details matter:

1. **`ESCAPE '!'` is named explicitly.** MySQL's default `LIKE` escape is a backslash while SQLite has none, so the default differs by dialect. Naming `!` makes the clause mean the same thing on both.
2. **The column identifier is wrapped by the grammar** (`$query->getGrammar()->wrap($column)`), never interpolated into the SQL string.
3. **The pattern is a bound parameter**, so user text never becomes SQL.

`Str::escapeLike()` is not used because it does not exist in `Illuminate\Support\Str`. `whereLike()` is not used for user input because it takes no escape parameter. The shared `!` helper is the only path from user text to a `LIKE` condition.

The closeness ranking reuses the same escaped pattern and the same `ESCAPE '!'`, in SQL and before the `LIMIT`:

```sql
ORDER BY (CASE
    WHEN LOWER(col) = LOWER(?) THEN 0
    WHEN LOWER(col) LIKE ? ESCAPE '!' THEN 1
    ELSE 2
END), id DESC
```

An exact match therefore cannot be cut off by a page of looser matches.

---

## 5. Permission-aware scoping: `queryFor()` and `baseQueryFor()`

`queryFor(array $permissionNames, string $term, int $limit)` is the permission-aware scoping hook in the interface. `query()` is a thin delegate:

```php
public function query(string $term, int $limit): Collection
{
    return $this->queryFor([], $term, $limit);
}
```

`queryFor()` owns the whole pipeline (trim, route guard, escaped `LIKE`, grouped `WHERE`, ranking, `LIMIT`) and builds its query through:

```php
protected function baseQueryFor(array $permissionNames): Builder
{
    return $this->baseQuery();
}
```

A provider overrides `baseQueryFor()` when what the viewer may see depends on their permissions. `GlobalSearchService::groups()` always calls `queryFor($permissionNames, ...)`, so the set is available to every provider without any provider calling `hasPermission()`.

### Worked example: KB draft visibility

`KnowledgeBaseSearchProvider` matches `title` and `content`, but `kb.view` admits viewers who may read published articles only. The default base query is published-only, and a viewer who also holds `kb.edit` gets the status constraint dropped:

```php
protected function baseQueryFor(array $permissionNames): Builder
{
    if (in_array('kb.edit', $permissionNames, true)) {
        return KnowledgeBase::query();
    }

    return $this->baseQuery(); // published only
}
```

The `content` body is matched but never rendered: the result subtitle is the category label only, so article text cannot leak into the typeahead payload.

---

## 6. HTTP surface

### 6.1 Routes

Both routes are registered in `routes/admin/search.php` inside the admin group.

| Method | URI | Name | Middleware |
|---|---|---|---|
| GET | `admin/search` | `admin.search.index` | `web`, `auth`, `admin`, `permission:search`, `throttle:search` |
| GET | `admin/search/typeahead` | `admin.search.typeahead` | `web`, `auth`, `admin`, `permission:search`, `throttle:search` |

Both opt out of the group's `throttle:admin` with `withoutMiddleware('throttle:admin')` (section 7). Reproduce the table with:

```bash
php artisan route:list --name=admin.search -v
```

### 6.2 JSON typeahead contract

`GET /admin/search/typeahead?q=` answers with:

```json
{
  "query": "acme",
  "groups": [
    {
      "key": "customers",
      "label": "Customers",
      "icon": "bi bi-people",
      "results": [
        {"id": 12, "label": "Acme Ltd", "subtitle": "acme@example.com", "url": "https://host/admin/customers/12"}
      ]
    }
  ],
  "total": 1
}
```

- Each group is capped at 5 rows; `total` is the number of rows the palette is about to render, never a database count.
- `url` is resolved server-side from the provider's `showRoute()`; the client cannot supply a destination.
- A query shorter than 2 characters (`mb_strlen`) returns HTTP 200 with `{"query":"","groups":[],"total":0}`, because the palette fires on every keystroke.
- `q` longer than 100 characters returns 422 (`SearchTypeaheadRequest`).
- A guest request redirects to the login page (302). An authenticated panel user without `search` gets 403.
- A missing provider route never becomes a 500: the provider is skipped and logged once.

### 6.3 Full results page

`GET /admin/search?q=` renders the same groups with `limit + 1` fetched and 10 rows shown per type. Each group header carries a count label (`10` or `10+` when `has_more` is true) and, when the list route is registered, a "View all" link to that screen with `?search=` forwarded. Queries shorter than 2 characters keep the historical contract: the page renders the search form only, with no results section. Zero results render the shared empty state; navigation shows the shared loading skeleton.

---

## 7. The `throttle:search` limiter

`AppServiceProvider::boot()` registers:

```php
RateLimiter::for('search', function (Request $request) {
    return Limit::perMinute(300)->by($request->user()?->getAuthIdentifier() ?: $request->ip());
});
```

Both routes opt out of the outer `throttle:admin` group:

```php
->middleware(['permission:search', 'throttle:search'])
->withoutMiddleware('throttle:admin')
```

The opt-out is load bearing. Without it, one palette keystroke would be charged to two buckets, and the `admin` limiter's GET ceiling (300/min) would apply in addition to the search budget. The palette debounces at 250 ms, so a sustained typist peaks at roughly 240 requests per minute, under the 300/min ceiling, while a scripted flood still hits 429. The limiter is keyed per user, so one noisy staff member cannot throttle anybody else.

---

## 8. Ctrl/Cmd+K precedence (palette vs chat switcher)

Two surfaces listen for Ctrl/Cmd+K: the global command palette (every admin page) and the chat conversation switcher (`resources/js/chat.js`, on `/admin/chat*`). One keystroke must open exactly one of them. The contract is symmetric and order-independent:

- `resources/js/chat.js` sets `window.__mhChatShortcuts = true` at module top level, and its Ctrl/Cmd+K branch skips when the palette is open (`!document.getElementById('adminlteCommandPalette').hidden`).
- The palette's Ctrl/Cmd+K branch bails when `window.__mhChatShortcuts` is set or `event.defaultPrevented` is true.

Acceptance: on `/admin/chat*`, one Ctrl/Cmd+K opens only the conversation switcher; on every other admin page, only the palette. Verify the flag with:

```bash
grep -q '__mhChatShortcuts' resources/js/chat.js
```

---

## 9. How to add a new provider in one file

Say you want licenses in the global search. The model, the routes (`admin.licenses.show`, `admin.licenses.index`), and the permission (`licenses.view`) already exist, so the work is one class, one config line, and one test. `LicenseSearchProvider` below is the worked example; it is not shipped as part of the 17 providers above.

**Step 1: create `app/Services/Search/Providers/LicenseSearchProvider.php`.**

```php
<?php

declare(strict_types=1);

namespace App\Services\Search\Providers;

use App\Models\License;
use App\Services\Search\AbstractSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LicenseSearchProvider extends AbstractSearchProvider
{
    public function key(): string
    {
        return 'licenses';
    }

    public function label(): string
    {
        return 'Licenses';
    }

    public function icon(): string
    {
        return 'bi bi-key';
    }

    public function permission(): string
    {
        return 'licenses.view';
    }

    public function showRoute(): string
    {
        return 'admin.licenses.show';
    }

    public function listRoute(): ?string
    {
        return 'admin.licenses.index';
    }

    protected function baseQuery(): Builder
    {
        return License::query();
    }

    protected function searchableColumns(): array
    {
        return ['license_key', 'vendor'];
    }

    public function toResult(Model $model): array
    {
        /** @var License $model */
        return $this->resultRow($model, (string) $model->license_key, (string) $model->vendor);
    }
}
```

That is the whole class. Everything shared (escaped `LIKE`, grouped `WHERE`, ranking before the `LIMIT`, the route guard, the result shape) comes from `AbstractSearchProvider`.

Optional overrides, only when the default is wrong for your entity:

- `rankColumn()` when the strongest identifier is not the first searchable column;
- `searchableRelations()` for columns on a related model (a grouped `whereHas` per relation);
- `baseQueryFor(array $permissionNames)` when the viewer's permissions change what they may see (section 5).

**Step 2: add the class to the `providers` array in `config/search.php`.**

```php
\App\Services\Search\Providers\LicenseSearchProvider::class,
```

Order in the config is the group order on both surfaces. The list may safely name a class that has not shipped yet: it is skipped and logged once.

**Step 3: add a test.** Assert the group appears for a user holding the provider's permission and is absent for a user without it. `tests/Feature/GlobalSearchServiceTest.php` shows the registry-stub pattern, and `tests/Feature/IdentitySearchProvidersTest.php` and `tests/Feature/HostingSearchProvidersTest.php` show the permission-absence assertion pattern.

No controller, route, view, or palette change is needed. Both surfaces pick the new provider up from the config list.

---

## 10. Verification

| Check | Command |
|---|---|
| Routes and middleware | `php artisan route:list --name=admin.search -v` shows both routes with `permission:search` and `throttle:search`, and without `throttle:admin`. |
| Service behavior and escaping | `php artisan test --filter=GlobalSearchService` |
| Typeahead contract and permission leaks | `php artisan test --filter=GlobalSearchTypeaheadTest` |
| Provider permission leaks | `php artisan test --filter=IdentitySearchProviders` and `php artisan test --filter=HostingSearchProviders` |
| Throttle ceiling | `php artisan test --filter=SearchThrottleTest` |
| Permission backfill | `php artisan test --filter=SearchPermissionBackfill` |
| Existing page contracts | `php artisan test --filter=AdminSearch` |
| Escape rule unit | A test asserts `LikePattern::contains('100%_!') === '%100!%!_!!%'` in `tests/Feature/GlobalSearchServiceTest.php`. |
| Views compile | `php artisan view:cache` |
| Assets build | `npm run build` |

Evidence files live under `.omo/evidence/global-search/`.
