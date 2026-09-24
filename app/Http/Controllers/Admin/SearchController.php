<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchTypeaheadRequest;
use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ServiceInstance;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Search\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    public function search(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $results = [];
        if (strlen($q) >= 2) {
            $results['customers'] = Customer::whereHas('user', function ($query) use ($q) {
                $query->where('email', 'like', "%{$q}%")
                    ->orWhere('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%");
            })
                ->orWhere('company', 'like', "%{$q}%")
                ->with('user')
                ->limit(5)->get();
            $results['services'] = ServiceInstance::where('username', 'like', "%{$q}%")
                ->orWhere('domain', 'like', "%{$q}%")
                ->with('customer')
                ->limit(5)->get();
            $results['invoices'] = Invoice::where('invoice_no', 'like', "%{$q}%")
                ->limit(5)->get();
            $results['tickets'] = Ticket::where('subject', 'like', "%{$q}%")
                ->orWhere('ticket_no', 'like', "%{$q}%")
                ->limit(5)->get();
            $results['products'] = CatalogProduct::where('name', 'like', "%{$q}%")
                ->orWhere('sku', 'like', "%{$q}%")
                ->limit(5)->get();
        }

        return view('admin.search.index', compact('q', 'results'));
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
