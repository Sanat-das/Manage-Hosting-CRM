<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchTypeaheadRequest;
use App\Models\User;
use App\Services\Search\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    /**
     * The full grouped results page.
     *
     * The viewer's permission names are resolved ONCE (a single pluck) and the
     * service skips every provider the viewer may not read, so a group that
     * must not be visible is never even built. Each group is capped at 10 rows;
     * the service fetches `limit + 1` and reports `has_more`, so this page
     * never runs a COUNT(*). Queries shorter than two characters keep the
     * historical contract: the page renders the form only, with no results
     * section at all.
     */
    public function search(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $groups = [];

        if (mb_strlen($q) >= 2) {
            $service = app(GlobalSearchService::class);

            /** @var User $user */
            $user = $request->user();

            $permissionNames = $service->permissionNames($user);

            $groups = $service->groups($permissionNames, $q, 10);
        }

        return view('admin.search.index', compact('q', 'groups'));
    }

    /**
     * JSON typeahead for the Ctrl/Cmd+K command palette.
     *
     * Short queries answer with a 200 empty envelope — never 422, because the
     * palette fires on every keystroke. The viewer's permission set is resolved
     * ONCE and the service skips every provider the viewer may not read; the
     * payload is then mapped explicitly, so the service's internal `has_more`/
     * `list_url` full-page keys never leak into this contract. `url` is always
     * server-resolved by the provider from its own show route.
     */
    public function typeahead(SearchTypeaheadRequest $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json([
                'query' => '',
                'groups' => [],
                'total' => 0,
            ]);
        }

        $service = app(GlobalSearchService::class);

        /** @var User $user */
        $user = $request->user();
        $permissionNames = $service->permissionNames($user);

        $groups = array_map(
            static fn (array $group): array => [
                'key' => $group['key'],
                'label' => $group['label'],
                'icon' => $group['icon'],
                'results' => array_map(
                    static fn (array $result): array => [
                        'id' => $result['id'],
                        'label' => $result['label'],
                        'subtitle' => $result['subtitle'],
                        'url' => $result['url'],
                    ],
                    $group['results'],
                ),
            ],
            $service->groups($permissionNames, $q, 5),
        );

        // `total` is the number of rows the palette is about to render — the
        // sum of the per-group result counts, each already capped at 5 — not a
        // database count: the typeahead contract carries no counts and never
        // runs a COUNT(*) query.
        $total = array_sum(array_map(
            static fn (array $group): int => count($group['results']),
            $groups,
        ));

        return response()->json([
            'query' => $q,
            'groups' => $groups,
            'total' => $total,
        ]);
    }
}
