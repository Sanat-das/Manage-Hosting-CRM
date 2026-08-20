<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\NoAvailableIpException;
use App\Http\Controllers\Controller;
use App\Models\AssetRelationship;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Datacenter;
use App\Models\HostingAccount;
use App\Models\InventoryAsset;
use App\Models\IpAddress;
use App\Models\IpSubnet;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rack;
use App\Models\ResourcePool;
use App\Models\Server;
use App\Models\SslCertificate;
use App\Models\Vlan;
use App\Services\HostingService;
use App\Services\IpAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Admin product/service account management (Session 3A.2).
 *
 * Ported from the reference Modules\Hosting\Presentation\HostingController
 * plus the local pilot (CustomerController) conventions:
 *   - create forces status 'pending' (reference CreateHostingCommand)
 *   - every write is audited (audit_log + customer activity_log)
 *   - status lifecycle actions (suspend/unsuspend/changePackage/destroy)
 *     delegate to HostingService so the guards stay in one place
 *
 * Permission gates: hosting.view (read), hosting.manage (write). The
 * reference granular hosting.create/edit/delete permissions do not exist in
 * the local seeder, so all write actions use hosting.manage.
 */
class HostingController extends Controller
{
    private const PER_PAGE = 20;

    /** Reference domain-name pattern (Modules\Hosting\Domain\HostingAccount). */
    private const DOMAIN_PATTERN = '/^[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?(\.[a-zA-Z]{2,})+$/';

    /** Cap on the "choose specific IP" list so the edit page stays fast on large pools. */
    private const AVAILABLE_IP_LIMIT = 100;

    /**
     * Asset kind => [model class, display column] used to resolve an asset
     * relationship's parent/child entity to its display name. Mirrors
     * ProductHostedOnController::PARENT_DISPLAY; kinds without a resolvable
     * name (license) fall back to "Kind #id".
     */
    private const ASSET_DISPLAY = [
        'product' => [Product::class, 'name'],
        'server' => [Server::class, 'name'],
        'hosting_account' => [HostingAccount::class, 'username'],
        'datacenter' => [Datacenter::class, 'name'],
        'rack' => [Rack::class, 'name'],
        'ip_subnet' => [IpSubnet::class, 'name'],
        'vlan' => [Vlan::class, 'name'],
        'resource_pool' => [ResourcePool::class, 'name'],
        'inventory_asset' => [InventoryAsset::class, 'asset_tag'],
    ];

    public function __construct(
        private readonly HostingService $hostingService,
        private readonly IpAssignmentService $ipAssignmentService,
    ) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');

        $accounts = HostingAccount::query()
            ->with(['customer.user:id,email,first_name,last_name', 'product:id,name,billing_cycle,price', 'product.pricing', 'server:id,name,ip_address'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('username', 'like', "%{$search}%")
                        ->orWhere('domain', 'like', "%{$search}%")
                        ->orWhereHas('customer.user', function ($u) use ($search) {
                            $u->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->when(in_array($status, HostingService::STATUSES, true), function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $amounts = $accounts->mapWithKeys(fn ($a) => [$a->id => (float) $this->recurringAmountFor($a)])->all();

        return view('admin.hosting.index', compact('accounts', 'search', 'status', 'amounts'));
    }

    public function create(): View
    {
        return view('admin.hosting.create', $this->formOptions());
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(
            $this->rules(customerId: (int) $request->input('customer_id'))
        );

        try {
            $account = DB::transaction(function () use ($validated, $request) {
                $account = HostingAccount::create([
                    'customer_id' => $validated['customer_id'],
                    'product_id' => $validated['product_id'],
                    'server_id' => $validated['server_id'] ?? null,
                    'username' => $validated['username'],
                    'domain' => $validated['domain'] ?? null,
                    'disk_quota' => $validated['disk_quota'] ?? 0,
                    'disk_used' => 0,
                    'bandwidth_quota' => $validated['bandwidth_quota'] ?? 0,
                    'bandwidth_used' => 0,
                    'panel_account_id' => $validated['panel_account_id'] ?? null,
                    'username_prefix' => $validated['username_prefix'] ?? null,
                    'password' => $validated['password'] ?? null,
                    'status' => HostingService::STATUS_PENDING,
                ]);

                $this->hostingService->audit(
                    $account,
                    'hosting.created',
                    "Product/Service {$account->username} created",
                    ['by' => $request->user()->email],
                );

                return $account;
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not create product/service: '.$e->getMessage()]);
        }

        return redirect()
            ->route('admin.hosting.show', $account)
            ->with('success', "Product/Service {$account->username} created (status: pending).");
    }

    public function show(HostingAccount $hostingAccount): View
    {
        $hostingAccount->load([
            'customer.user', 'product', 'server', 'order', 'order.domain', 'order.invoices', 'order.items',
        ]);

        $audit = AuditLog::query()
            ->where('entity_type', 'hosting_account')
            ->where('entity_id', $hostingAccount->id)
            ->with('user:id,first_name,last_name,email')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $packages = Product::query()
            ->where('status', 'active')
            ->whereHas('group', fn ($q) => $q->where('is_hosting', true))
            ->orderBy('name')
            ->get(['id', 'name', 'price', 'billing_cycle']);

        // IP lease state for the Info tab: the lease lives on the polymorphic
        // ip_addresses pair, not on hosting_accounts itself. An account can
        // hold several leases (e.g. public + private), so list them all
        // rather than a single first lease. Pool browsing / editing moved to
        // the edit page (see edit()).
        $assignedIps = IpAddress::query()
            ->where('assigned_to_type', HostingAccount::class)
            ->where('assigned_to_id', $hostingAccount->id)
            ->with('subnet:id,name')
            ->orderBy('id')
            ->get();

        // SSL monitoring (WHMCS-style): certificates for the linked order,
        // plus any for this account matching the account's primary domain.
        $sslCerts = collect();

        if ($hostingAccount->order_id !== null) {
            $sslCerts = $sslCerts->concat(
                SslCertificate::query()->where('order_id', $hostingAccount->order_id)->get()
            );
        }

        if ($hostingAccount->domain) {
            $sslCerts = $sslCerts->concat(
                SslCertificate::query()
                    ->where('customer_id', $hostingAccount->customer_id)
                    ->where('domain_name', $hostingAccount->domain)
                    ->get()
            );
        }

        $sslCerts = $sslCerts->unique('id')->values()->sortByDesc('expiry_date');

        // Asset relationships, display names and linkable inventory assets —
        // shared with the edit page (link / remove controls) via assetContext().
        $assetContext = $this->assetContext($hostingAccount);

        // Billing context for the Billing tab: recurring amount resolved from
        // the order's cycle when one exists (see recurringAmountFor), plus the
        // linked order and its invoices for the invoice list.
        $order = $hostingAccount->order;
        $recurringAmount = $this->recurringAmountFor($hostingAccount);

        // Package resources snapshot captured at order time: the config_options
        // JSON on the order item matching this account's product. Null when the
        // account has no order / no matching item / no snapshot — the view then
        // falls back to the live product option links.
        $packageSnapshot = $hostingAccount->order?->items
            ->firstWhere('product_id', $hostingAccount->product_id)?->config_options
            ?? null;

        return view('admin.hosting.show', [
            'hostingAccount' => $hostingAccount,
            'audit' => $audit,
            'packages' => $packages,
            'assignedIps' => $assignedIps,
            'sslCerts' => $sslCerts,
            'assetRelationships' => $assetContext['assetRelationships'],
            'assetNames' => $assetContext['assetNames'],
            'inventoryAssets' => $assetContext['inventoryAssets'],
            'recurringAmount' => $recurringAmount,
            'order' => $order,
            'domain' => $order?->domain,
            'invoices' => $order?->invoices ?? collect(),
            'packageSnapshot' => $packageSnapshot,
            'assetKinds' => AssetRelationship::ASSET_KINDS,
            'relationshipTypes' => AssetRelationship::RELATIONSHIP_TYPES,
        ]);
    }

    public function edit(HostingAccount $hostingAccount): View
    {
        $options = $this->formOptions();

        // Ensure the currently assigned server is always selectable, even if it
        // has since been marked inactive.
        if ($hostingAccount->server_id !== null && $options['servers']->doesntContain('id', $hostingAccount->server_id)) {
            $options['servers'] = $options['servers']
                ->push(Server::find($hostingAccount->server_id))
                ->filter()
                ->sortBy('name')
                ->values();
        }

        // IP lease state for the IP management card on the edit page: the
        // leases currently held by this account, plus the unassigned pool the
        // admin can pull from / pick from.
        $assignedIps = IpAddress::query()
            ->where('assigned_to_type', HostingAccount::class)
            ->where('assigned_to_id', $hostingAccount->id)
            ->with('subnet:id,name')
            ->orderBy('id')
            ->get();

        $availableIps = IpAddress::query()
            ->whereNull('assigned_to_type')
            ->with('subnet:id,name')
            ->orderBy('id')
            ->limit(self::AVAILABLE_IP_LIMIT)
            ->get(['id', 'subnet_id', 'ip_address', 'ip_version', 'type']);

        // Lifecycle + asset-linking cards use the same context as the show page.
        $assetContext = $this->assetContext($hostingAccount);

        // Billing context for the Billing tab: the linked order (cycle,
        // dates, payment method, subscription id) plus the recurring amount
        // resolution used on the show page — the form pre-fills from these.
        $order = $hostingAccount->order;
        $recurringAmount = $this->recurringAmountFor($hostingAccount);

        return view('admin.hosting.edit', $options + [
            'hostingAccount' => $hostingAccount,
            'assignedIps' => $assignedIps,
            'availableIps' => $availableIps,
            'packages' => $options['products'],
            'assetRelationships' => $assetContext['assetRelationships'],
            'assetNames' => $assetContext['assetNames'],
            'inventoryAssets' => $assetContext['inventoryAssets'],
            'assetKinds' => AssetRelationship::ASSET_KINDS,
            'relationshipTypes' => AssetRelationship::RELATIONSHIP_TYPES,
            'order' => $order,
            'recurringAmount' => $recurringAmount,
        ]);
    }

    public function update(Request $request, HostingAccount $hostingAccount): RedirectResponse
    {
        $validated = $request->validate(
            $this->rules($hostingAccount, (int) $request->input('customer_id', $hostingAccount->customer_id))
            + ['status' => ['sometimes', Rule::in(HostingService::STATUSES)]]
        );

        // Empty password field on the edit form means "leave unchanged".
        if (isset($validated['password']) && trim($validated['password']) === '') {
            unset($validated['password']);
        }

        try {
            DB::transaction(function () use ($hostingAccount, $validated, $request) {
                $hostingAccount->update($validated);

                $this->hostingService->audit(
                    $hostingAccount,
                    'hosting.updated',
                    "Product/Service {$hostingAccount->username} updated",
                    ['by' => $request->user()->email],
                );
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not update product/service: '.$e->getMessage()]);
        }

        return redirect()
            ->route('admin.hosting.show', $hostingAccount)
            ->with('success', "Product/Service {$hostingAccount->username} updated.");
    }

    /**
     * Delete = reference soft delete: terminate the account (status ->
     * terminated) rather than hard-removing the row, preserving the audit
     * trail and any linked billing history.
     */
    public function destroy(Request $request, HostingAccount $hostingAccount): RedirectResponse
    {
        try {
            $this->hostingService->terminate($hostingAccount, $request->input('reason'));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.hosting.index')
            ->with('success', "Product/Service {$hostingAccount->username} terminated.");
    }

    public function suspend(Request $request, HostingAccount $hostingAccount): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        try {
            $this->hostingService->suspend($hostingAccount, $validated['reason'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', "Product/Service {$hostingAccount->username} suspended.");
    }

    public function unsuspend(HostingAccount $hostingAccount): RedirectResponse
    {
        try {
            $this->hostingService->unsuspend($hostingAccount);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Product/Service {$hostingAccount->username} reactivated.");
    }

    /**
     * One-click control-panel password change (WHMCS "Change Password" module
     * command). Stores the new value on the account and audits it. The value
     * is persisted exactly like the edit form's password field (existing
     * storage convention — see HostingAccount::$fillable).
     */
    public function changePassword(Request $request, HostingAccount $hostingAccount): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            DB::transaction(function () use ($hostingAccount, $validated, $request) {
                $hostingAccount->update(['password' => $validated['password']]);

                $this->hostingService->audit(
                    $hostingAccount,
                    'hosting.password_changed',
                    "Product/Service {$hostingAccount->username} password changed",
                    ['by' => $request->user()->email],
                );
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not change password: '.$e->getMessage()]);
        }

        return back()->with('success', "Product/Service {$hostingAccount->username} password changed.");
    }

    public function changePackage(Request $request, HostingAccount $hostingAccount): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
        ]);

        try {
            $this->hostingService->changePackage($hostingAccount, (int) $validated['product_id']);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', "Product/Service {$hostingAccount->username} package changed.");
    }

    /**
     * Update billing info (Billing tab on the edit page).
     *
     * Persists the account's next due date plus the linked order's billing
     * cycle / next billing date / payment method / subscription id. Because
     * the recurring billing engine reads the order ITEM's schedule (the
     * order row is only a summary — see README "Billing & Recurring"), the
     * item matching this account's product is kept in sync so a cycle or
     * date change actually takes effect on the next renewal.
     */
    public function updateBilling(Request $request, HostingAccount $hostingAccount): RedirectResponse
    {
        $validated = $request->validate([
            'billing_cycle' => ['nullable', Rule::in(Order::BILLING_CYCLES)],
            'next_due_date' => ['nullable', 'date'],
            'next_billing_date' => ['nullable', 'date'],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'subscription_id' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            DB::transaction(function () use ($hostingAccount, $validated, $request) {
                $hostingAccount->update([
                    'next_due_date' => $validated['next_due_date'] ?? null,
                ]);

                $order = $hostingAccount->order;

                if ($order !== null) {
                    $order->update([
                        'billing_cycle' => $validated['billing_cycle'] ?? null,
                        'next_billing_date' => $validated['next_billing_date'] ?? null,
                        'payment_method' => $validated['payment_method'] ?? null,
                        'subscription_id' => $validated['subscription_id'] ?? null,
                    ]);

                    // Keep the authoritative per-item schedule in sync so the
                    // recurring billing engine (which reads order_items) picks
                    // up the change.
                    $item = $order->items()->where('product_id', $hostingAccount->product_id)->first();

                    if ($item !== null) {
                        $item->update([
                            'billing_cycle' => $validated['billing_cycle'] ?? null,
                            'next_billing_date' => $validated['next_billing_date'] ?? null,
                        ]);
                    }
                }

                $this->hostingService->audit(
                    $hostingAccount,
                    'hosting.billing.updated',
                    "Billing info for Product/Service {$hostingAccount->username} updated",
                    ['by' => $request->user()->email],
                );
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not update billing info: '.$e->getMessage()]);
        }

        return back()->with('success', "Billing info for Product/Service {$hostingAccount->username} updated.");
    }

    /**
     * Lease the next N available IPs from the pool to this account (default 1,
     * up to 10 per click).
     */
    public function pullIp(Request $request, HostingAccount $hostingAccount): RedirectResponse
    {
        $validated = $request->validate([
            'count' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        try {
            $ips = $this->ipAssignmentService->assignNextAvailableMany($hostingAccount, (int) ($validated['count'] ?? 1));
        } catch (NoAvailableIpException $e) {
            return back()->with('error', $e->getMessage());
        }

        $list = $ips->pluck('ip_address')->implode(', ');
        $n = $ips->count();

        return back()->with('success', $n === 1
            ? "IP {$list} assigned to {$hostingAccount->username}."
            : "{$n} IPs assigned to {$hostingAccount->username}: {$list}.");
    }

    /**
     * Lease several specific, currently unassigned IPs at once. Addresses that
     * turn out to be taken are skipped, the rest are assigned.
     */
    public function assignIps(Request $request, HostingAccount $hostingAccount): RedirectResponse
    {
        $validated = $request->validate([
            'ip_address_ids' => ['required', 'array', 'min:1', 'max:50'],
            'ip_address_ids.*' => ['integer', 'exists:ip_addresses,id'],
        ]);

        $result = $this->ipAssignmentService->assignMany($hostingAccount, $validated['ip_address_ids']);

        if ($result['assigned']->isEmpty()) {
            return back()->with('error', 'None of the selected IPs could be assigned (already in use).');
        }

        $message = 'Assigned '.$result['assigned']->count().' IP(s) to '.$hostingAccount->username.': '
            .$result['assigned']->pluck('ip_address')->implode(', ').'.';

        if ($result['failed'] !== []) {
            $message .= ' Skipped '.count($result['failed']).' already-assigned IP(s).';
        }

        return back()->with('success', $message);
    }

    /**
     * Release the selected IP lease(s) back to the pool (no-op when the
     * account holds no matching lease). Accepts either a single
     * ip_address_id or a bulk ip_address_ids[] selection.
     */
    public function releaseIp(Request $request, HostingAccount $hostingAccount): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
            'ip_address_id' => ['nullable', 'integer', 'exists:ip_addresses,id'],
            'ip_address_ids' => ['nullable', 'array', 'max:50'],
            'ip_address_ids.*' => ['integer', 'exists:ip_addresses,id'],
        ]);

        $ids = $validated['ip_address_ids'] ?? array_values(array_filter([$validated['ip_address_id'] ?? null]));

        if ($ids === []) {
            return back()->with('error', 'Select at least one IP lease to release.');
        }

        foreach ($ids as $id) {
            $this->ipAssignmentService->release($hostingAccount, $validated['reason'] ?? null, (int) $id);
        }

        return back()->with('success', count($ids) === 1
            ? "IP lease released from {$hostingAccount->username}."
            : count($ids)." IP leases released from {$hostingAccount->username}.");
    }

    /**
     * Recurring amount for an account: the price for the resolved billing
     * cycle. The cycle comes from the linked order when one exists, else the
     * product's default cycle; the price prefers the product_pricing row for
     * that cycle, falling back to the product's flat price. Null when no
     * cycle/price can be resolved (view falls back to 0).
     */
    private function recurringAmountFor(HostingAccount $account): ?float
    {
        $cycle = $account->order_id !== null && $account->order?->billing_cycle
            ? $account->order->billing_cycle
            : $account->product?->billing_cycle;

        if ($cycle === null) {
            return null;
        }

        $price = $account->product?->pricing->firstWhere('billing_cycle', $cycle)?->price
            ?? $account->product?->price;

        return $price === null ? null : (float) $price;
    }

    /**
     * Resolve display names for both the parent and child side of every asset
     * relationship, one query per kind (no N+1). Keyed by relationship id:
     * [id => ['parent' => ?string, 'child' => ?string]]; unresolvable kinds
     * (e.g. license) keep null so the view falls back to "Kind #id".
     *
     * @param  Collection<int, AssetRelationship>  $relationships
     * @return array<int, array{parent: ?string, child: ?string}>
     */
    private function resolveAssetNames(Collection $relationships): array
    {
        $names = [];

        foreach ($relationships as $relationship) {
            $names[$relationship->id] = ['parent' => null, 'child' => null];
        }

        foreach (['parent' => 'parent_kind', 'child' => 'child_kind'] as $side => $kindColumn) {
            $idColumn = $side.'_id';
            $idsByKind = [];

            foreach ($relationships as $relationship) {
                $kind = $relationship->{$kindColumn};

                if (array_key_exists($kind, self::ASSET_DISPLAY)) {
                    $idsByKind[$kind][$relationship->{$idColumn}] = $relationship->id;
                }
            }

            foreach ($idsByKind as $kind => $ids) {
                [$model, $column] = self::ASSET_DISPLAY[$kind];
                $rows = $model::query()->whereIn('id', array_keys($ids))->get(['id', $column]);

                foreach ($rows as $row) {
                    $names[$ids[$row->id]][$side] = $row->{$column};
                }
            }
        }

        return $names;
    }

    /**
     * Asset relationships touching this account (either side of the link),
     * restricted to inventory assets, plus the display-name lookup and the
     * assets available to link. Shared by the show page (read-only display)
     * and the edit page (link / remove controls).
     *
     * @return array{
     *     assetRelationships: \Illuminate\Support\Collection<int, AssetRelationship>,
     *     assetNames: array<int, array{parent: ?string, child: ?string}>,
     *     inventoryAssets: \Illuminate\Support\Collection<int, InventoryAsset>,
     * }
     */
    private function assetContext(HostingAccount $account): array
    {
        $assetRelationships = AssetRelationship::query()
            ->where(function ($query) use ($account) {
                $query->where(function ($q) use ($account) {
                    $q->where('parent_kind', 'hosting_account')
                        ->where('parent_id', $account->id)
                        ->where('child_kind', 'inventory_asset');
                })->orWhere(function ($q) use ($account) {
                    $q->where('child_kind', 'hosting_account')
                        ->where('child_id', $account->id)
                        ->where('parent_kind', 'inventory_asset');
                });
            })
            ->orderBy('id')
            ->get();

        return [
            'assetRelationships' => $assetRelationships,
            'assetNames' => $this->resolveAssetNames($assetRelationships),
            'inventoryAssets' => InventoryAsset::query()
                ->orderBy('asset_tag')
                ->limit(200)
                ->get(['id', 'asset_tag', 'model', 'asset_type']),
        ];
    }

    private function formOptions(): array
    {
        return [
            'customers' => Customer::query()
                ->with('user:id,first_name,last_name,email')
                ->orderBy('id')
                ->get(),
            'products' => Product::query()
                ->where('status', 'active')
                ->whereHas('group', fn ($q) => $q->where('is_hosting', true))
                ->orderBy('name')
                ->get(['id', 'name', 'price', 'billing_cycle']),
            'servers' => Server::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'ip_address', 'panel_type']),
        ];
    }

    /**
     * Validation rules. The domain must be unique per customer (reference
     * findByDomainAndCustomer business rule). $customerId is required for the
     * scoped uniqueness check.
     */
    private function rules(?HostingAccount $account = null, int $customerId = 0): array
    {
        $ignore = $account?->id;

        $domainRules = ['nullable', 'string', 'max:255', Rule::regex(self::DOMAIN_PATTERN)];

        if ($customerId > 0) {
            $domainRules[] = Rule::unique('hosting_accounts', 'domain')
                ->where(fn ($q) => $q->where('customer_id', $customerId))
                ->ignore($ignore);
        }

        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'server_id' => ['nullable', 'integer', 'exists:servers,id'],
            'username' => ['required', 'string', 'max:100', Rule::unique('hosting_accounts', 'username')->ignore($ignore)],
            'domain' => $domainRules,
            'disk_quota' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'bandwidth_quota' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'panel_account_id' => ['nullable', 'string', 'max:255'],
            'username_prefix' => ['nullable', 'string', 'max:50'],
            'password' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
