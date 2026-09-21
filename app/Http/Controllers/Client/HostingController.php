<?php

namespace App\Http\Controllers\Client;

use App\Contracts\Integrations\Capabilities\HostingAccountInfoProvider;
use App\Http\Controllers\Controller;
use App\Models\HostingAccount;
use App\Services\HostingService;
use App\Services\Modules\ModuleManager;
use App\Services\OrderConfigSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Client portal — hosting account listing and detail.
 */
class HostingController extends Controller
{
    private const PER_PAGE = 15;

    public function __construct(
        private readonly OrderConfigSnapshot $snapshot,
    ) {}

    public function index(Request $request): View
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $search = trim((string) $request->query('search'));
        $status = $request->query('status');

        $accounts = $customer->hostingAccounts()
            ->with([
                'product:id,name',
                'server:id,name',
                'ipAddresses:id,assigned_to_type,assigned_to_id,ip_address,type',
                'order:id,order_number,billing_cycle,next_billing_date',
                'order.items:id,order_id,product_id,billing_cycle,unit_price,total,next_billing_date',
            ])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('domain', 'like', "%{$search}%")
                        ->orWhere('host_name', 'like', "%{$search}%")
                        ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('server', fn ($s) => $s->where('name', 'like', "%{$search}%"));
                });
            })
            ->when(in_array($status, HostingService::STATUSES, true), function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->gridSort([
                'host_name' => 'host_name',
                'product' => 'product.name',
                'domain' => 'domain',
                'next_due' => 'next_due_date',
                'status' => 'status',
            ])
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $counts = $customer->hostingAccounts()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $billing = $accounts->mapWithKeys(fn ($account) => [$account->id => $this->billingFor($account)])->all();

        return view('client.hosting.index', compact('accounts', 'search', 'status', 'counts', 'billing'));
    }

    public function show(Request $request, int $id): View
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $account = $customer->hostingAccounts()
            ->with(['product', 'server', 'order.items', 'ipAddresses'])
            ->findOrFail($id);

        // Module capability panels (cache-only, client-safe): same collection
        // as the admin page. Modules return only cached snapshot data for
        // this path — no refresh controls or credentials are involved.
        $modulePanels = [];
        $manager = app(ModuleManager::class);

        foreach ($manager->active() as $module) {
            $instance = $manager->resolve($module);

            if (! $instance instanceof HostingAccountInfoProvider) {
                continue;
            }

            $link = $account->product?->moduleLinks->firstWhere('module_slug', $module->slug);

            if ($link === null || ! $link->enabled) {
                continue;
            }

            $config = $manager->decryptConfig($module, $link->config ?? []);
            $panel = $instance->hostingAccountInfo($account, $config);

            if ($panel !== null) {
                $modulePanels[] = $panel;
            }
        }

        return view('client.hosting.show', array_merge([
            'account' => $account,
            'billing' => $this->billingFor($account),
            'modulePanels' => $modulePanels,
        ], $this->configurationFor($account)));
    }

    /**
     * The features this service actually has — the order-time snapshot when
     * there is one, otherwise resolved from the product's option links so a
     * service predating the snapshot still shows its fixed features.
     *
     * Only options with a value are returned: the page used to fall back to
     * listing every value a group offers ("RAM: 8 GB, 16 GB"), which reads as
     * though the customer had been given all of them.
     *
     * @return array{configOptions: array<int, array<string, mixed>>, configCycle: string}
     */
    private function configurationFor(HostingAccount $account): array
    {
        $cycle = $account->order?->billing_cycle
            ?? $account->product?->billing_cycle
            ?? 'monthly';

        $linkedItem = $account->order?->items
            ?->first(fn ($item) => (int) $item->product_id === (int) $account->product_id && ! empty($item->config_options))
            ?? $account->order?->items?->first(fn ($item) => ! empty($item->config_options));

        $options = $linkedItem?->config_options['options']
            ?? ($account->product !== null
                ? $this->snapshot->capture($account->product, null, [], $cycle)['options']
                : []);

        $configured = array_values(array_filter($options, function (array $entry): bool {
            $selected = $entry['selected'] ?? null;

            return $selected !== null && $selected !== '' && $selected !== [];
        }));

        return ['configOptions' => $configured, 'configCycle' => (string) $cycle];
    }

    /**
     * Client-facing billing snapshot for an account: prefers the linked order
     * item's snapshot (per-service price/cycle/dates, authoritative for
     * renewals), falling back to the order header. Null when nothing can be
     * resolved (view falls back to em-dashes).
     *
     * @return array{cycle: ?string, amount: ?string, next_billing_date: ?Carbon}|null
     */
    private function billingFor(HostingAccount $account): ?array
    {
        $item = $account->order?->items
            ?->first(fn ($i) => (int) $i->product_id === (int) $account->product_id)
            ?? $account->order?->items?->first();

        if ($item !== null) {
            return [
                'cycle' => $item->billing_cycle ?? $account->order?->billing_cycle,
                'amount' => $item->total ?? $item->unit_price,
                'next_billing_date' => $item->next_billing_date,
            ];
        }

        if ($account->order !== null) {
            return [
                'cycle' => $account->order->billing_cycle,
                'amount' => $account->order->total,
                'next_billing_date' => $account->order->next_billing_date,
            ];
        }

        return null;
    }
}
