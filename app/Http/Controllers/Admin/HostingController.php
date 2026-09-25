<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Integrations\Capabilities\HostingAccountInfoProvider;
use App\Contracts\Integrations\Capabilities\HostingAccountToolsProvider;
use App\Exceptions\NoAvailableIpException;
use App\Http\Controllers\Controller;
use App\Models\AssetRelationship;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Datacenter;
use App\Models\HostingAccount;
use App\Models\HostingNote;
use App\Models\InventoryAsset;
use App\Models\IpAddress;
use App\Models\IpSubnet;
use App\Models\Order;
use App\Models\PanelAccount;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\ProvisioningEvent;
use App\Models\Rack;
use App\Models\ResourcePool;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\SslCertificate;
use App\Models\Vlan;
use App\Services\HostingService;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\IpAssignmentService;
use App\Services\Modules\ModuleManager;
use App\Services\Provisioning\HypervDriver;
use App\Services\Provisioning\HypervVmBuildDispatcher;
use App\Services\Provisioning\ManualProvisioner;
use App\Services\Provisioning\ProvisioningEventRecorder;
use App\Services\Provisioning\VmStatusPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        // hosting_account username is legacy/module-managed — host_name is the product identifier
        'hosting_account' => [HostingAccount::class, 'host_name'],
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
        private readonly ManualProvisioner $manualProvisioner,
        private readonly ProvisioningEventRecorder $provisioningEvents,
        private readonly VmStatusPresenter $vmStatusPresenter,
        private readonly HypervVmBuildDispatcher $vmBuildDispatcher,
    ) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');

        $accounts = HostingAccount::query()
            ->with(['customer.user:id,email,first_name,last_name', 'product:id,name,billing_cycle,price', 'product.group:id,name', 'product.pricing', 'product.moduleLinks', 'server:id,name,ip_address', 'ipAddresses:id,assigned_to_type,assigned_to_id,ip_address,type', 'order:id,next_billing_date,billing_cycle'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('domain', 'like', "%{$search}%")
                        ->orWhere('host_name', 'like', "%{$search}%")
                        ->orWhere('id', 'like', "%{$search}%")
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
            ->when($status === 'awaiting_manual', function ($query) {
                $query->where('status', HostingService::STATUS_PENDING)
                    ->whereHas('product.moduleLinks', function ($q) {
                        $q->where('module_slug', 'hyperv')
                            ->where('enabled', true)
                            ->where('provisioning_mode', 'manual');
                    });
            })
            ->gridSort([
                'id' => 'id',
                'host_name' => 'host_name',
                'customer' => fn (Builder $q, string $dir) => $q->orderBy(Customer::select('company')->whereColumn('customers.id', 'hosting_accounts.customer_id'), $dir),
                'package' => 'product.name',
                'domain' => 'domain',
                'billing_cycle' => fn (Builder $q, string $dir) => $q->orderBy(Product::select('billing_cycle')->whereColumn('products.id', 'hosting_accounts.product_id'), $dir),
                'amount' => fn (Builder $q, string $dir) => $q->orderBy(Product::select('price')->whereColumn('products.id', 'hosting_accounts.product_id'), $dir),
                'due_date' => fn (Builder $q, string $dir) => $q->orderByRaw("COALESCE(next_due_date, (SELECT next_billing_date FROM orders WHERE orders.id = hosting_accounts.order_id)) {$dir}"),
                'created_at' => 'created_at',
                'status' => 'status',
            ])
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $amounts = $accounts->mapWithKeys(fn ($a) => [$a->id => (float) $this->recurringAmountFor($a)])->all();

        $awaitingManualMap = $accounts->getCollection()->mapWithKeys(function ($account) {
            $isAwaiting = $account->status === HostingService::STATUS_PENDING
                && $account->product?->moduleLinks?->contains(fn ($link) => $link->module_slug === 'hyperv' && (bool) $link->enabled && $link->provisioning_mode === 'manual') === true;

            return [$account->id => $isAwaiting];
        })->all();

        return view('admin.hosting.index', compact('accounts', 'search', 'status', 'amounts', 'awaitingManualMap'));
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
                // username/prefix are legacy/module-managed — auto-fill if missing for backward compat
                $username = $validated['username'] ?? null;
                if ($username === null || trim($username) === '') {
                    $username = null;
                }
                $account = HostingAccount::create([
                    'customer_id' => $validated['customer_id'],
                    'product_id' => $validated['product_id'],
                    'server_id' => $validated['server_id'] ?? null,
                    'username' => $username,
                    'domain' => $validated['domain'] ?? null,
                    'host_name' => $validated['host_name'] ?? null,
                    'username_prefix' => $validated['username_prefix'] ?? null,
                    'status' => HostingService::STATUS_PENDING,
                ]);

                $this->hostingService->audit(
                    $account,
                    'hosting.created',
                    "Product/Service #{$account->id} created",
                    ['by' => $request->user()->email],
                );

                return $account;
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not create product/service: '.$e->getMessage()]);
        }

        return redirect()
            ->route('admin.hosting.show', $account)
            ->with('success', "Product/Service #{$account->id} created (status: pending).");
    }

    public function show(HostingAccount $hostingAccount): View
    {
        $hostingAccount->load([
            'customer.user', 'product', 'product.moduleLinks', 'server', 'order', 'order.domain', 'order.invoices', 'order.items',
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
            ->with(['subnet.vlan', 'subnet.datacenter'])
            ->orderBy('id')
            ->get();

        // Verify display: eager-load status is derived from assigned_to_type vs type, so
        // assigned rows will show "Assigned" regardless of the underlying `type` column
        // (legacy rows may have type=Available while still leased).

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

        // Module data for the Info tab: every active module plus this account's
        // product pivot rows (enabled flag + per-product config). The view
        // renders it read-only — enabling/configuring happens on the product
        // show/edit pages.
        $modules = app(ModuleManager::class)->active();

        // Module capability contributions for this account's product: every
        // active module implementing HostingAccountInfoProvider contributes a
        // read-only panel and every one implementing HostingAccountToolsProvider
        // contributes its own tools section (buttons + modals + JS). The pivot
        // config is decrypted before it reaches the module; the module decides
        // what (if anything) to render for this account.
        $modulePanels = [];
        $moduleTools = [];
        $manager = app(ModuleManager::class);
        $registry = app(IntegrationRegistry::class);

        foreach ($modules as $module) {
            $instance = $manager->resolve($module);

            if (! $instance instanceof HostingAccountInfoProvider
                && ! $instance instanceof HostingAccountToolsProvider) {
                continue;
            }

            $link = $hostingAccount->product?->moduleLinks->firstWhere('module_slug', $module->slug);

            if ($link === null || ! $link->enabled) {
                continue;
            }

            $config = $manager->decryptConfig($module, $link->config ?? []);

            if ($instance instanceof HostingAccountInfoProvider) {
                $panel = $instance->hostingAccountInfo($hostingAccount, $config);

                if ($panel !== null) {
                    $modulePanels[] = $panel;
                }
            }

            if ($instance instanceof HostingAccountToolsProvider) {
                $tools = $instance->hostingAccountTools($hostingAccount, $config);

                if ($tools !== null) {
                    $moduleTools[] = $tools;
                }
            }
        }

        // Notes for the Notes tab: super-user (hosting.manage) can add/edit/delete.
        $notes = HostingNote::query()
            ->where('hosting_account_id', $hostingAccount->id)
            ->with('user:id,first_name,last_name,email')
            ->orderByDesc('is_important')
            ->orderByDesc('created_at')
            ->get();

        // Direct module actions for this account's product: every enabled
        // module link that implements provisioning contributes buttons. For
        // builtins the driver is resolved via IntegrationRegistry, for plugins
        // via ModuleManager fallback.
        $provisioningModules = [];
        $productLinks = $hostingAccount->product?->moduleLinks()->where('enabled', true)->get() ?? collect();
        foreach ($productLinks as $link) {
            $slug = trim((string) ($link->module_slug ?? ''));
            if ($slug === '') {
                continue;
            }

            $driver = null;
            $name = $registry->nameFor($slug);

            if ($registry->has($slug)) {
                $candidate = $registry->instanceFor($slug);
                if ($candidate !== null && method_exists($candidate, 'provision')) {
                    $driver = $candidate;
                }
            } else {
                $mod = $manager->find($slug);
                if ($mod !== null && $mod->status === \App\Models\Module::STATUS_ACTIVE) {
                    $driver = $manager->capabilityInstance($mod, 'provisioning');
                    $name = $mod->name ?? $name;
                }
            }

            if ($driver === null) {
                continue;
            }

            // Provide a pseudo-module object so the view's $mod->slug / $mod->name keep working
            $pseudoModule = (object) ['slug' => $slug, 'name' => $name, 'id' => null];

            $provisioningModules[] = [
                'module' => $pseudoModule,
                'slug' => $slug,
                'name' => $name,
                'mode' => $link->provisioning_mode ?? 'auto',
            ];
        }

        // Hyper-V effective templates for the admin Create modal (per-product restriction)
        $hypervEffectiveOptions = [];
        $hypervEffectiveDefault = null;
        $hypervCuratedCount = 0;
        if ($hostingAccount->server && method_exists($hostingAccount->server, 'hypervTemplateVms')) {
            $hypervCuratedCount = count($hostingAccount->server->hypervTemplateVms());
            $productAllowed = [];
            try {
                $hypervLinkForEffective = $hostingAccount->product?->moduleLinks?->firstWhere('module_slug', 'hyperv')
                    ?? ($hostingAccount->product ? $hostingAccount->product->moduleLinks()->where('module_slug', 'hyperv')->first() : null);
                if ($hypervLinkForEffective) {
                    $rawCfg = is_array($hypervLinkForEffective->config) ? $hypervLinkForEffective->config : [];
                    $dec = app(\App\Services\Integrations\IntegrationRegistry::class)->decryptConfigFor('hyperv', $rawCfg);
                    $rawAllowed = $dec['allowed_templates'] ?? [];
                    $productAllowed = \App\Services\Provisioning\HypervTemplateCatalog::sanitizeAllowed(is_array($rawAllowed) ? $rawAllowed : []);
                }
            } catch (\Throwable) {
                $productAllowed = [];
            }
            $hypervEffectiveOptions = \App\Services\Provisioning\HypervTemplateCatalog::effectiveOptions($hostingAccount->server, $productAllowed);
            $def = $hostingAccount->server->hypervDefaultTemplate();
            if ($def !== null && $def !== '' && in_array($def, array_column($hypervEffectiveOptions, 'name'), true)) {
                $hypervEffectiveDefault = $def;
            } elseif ($def !== null && $def !== '' && $productAllowed !== [] && ! in_array($def, array_column($hypervEffectiveOptions, 'name'), true)) {
                $hypervEffectiveDefault = null;
            } else {
                $hypervEffectiveDefault = $def;
                if ($hypervEffectiveDefault !== null && ! in_array($hypervEffectiveDefault, array_column($hypervEffectiveOptions, 'name'), true)) {
                    $hypervEffectiveDefault = $hypervEffectiveOptions[0]['name'] ?? null;
                }
            }
            // If effective empty but curated non-empty, keep default null so view shows empty-state
            if ($hypervEffectiveOptions === [] && $hypervCuratedCount > 0) {
                $hypervEffectiveDefault = null;
            }
        }

        $vmStatus = $this->vmStatusPresenter->build($hostingAccount);
        // Credential hint for the UI (never the password itself): the status
        // presenter already resolved the stored guest credentials.
        $vmGuestUsername = $vmStatus['credentials']['username'] ?? 'Administrator';
        $vmHasStoredPassword = (bool) ($vmStatus['credentials']['stored'] ?? false);

        return view('admin.hosting.show', [
            'hostingAccount' => $hostingAccount,
            'modules' => $modules,
            'modulePanels' => $modulePanels,
            'moduleTools' => $moduleTools,
            'provisioningModules' => $provisioningModules,
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
            'notes' => $notes,
            'hypervEffectiveOptions' => $hypervEffectiveOptions,
            'hypervEffectiveDefault' => $hypervEffectiveDefault,
            'hypervCuratedCount' => $hypervCuratedCount,
            'latestProvisioningEvent' => ProvisioningEvent::where('hosting_account_id', $hostingAccount->id)->orderByDesc('id')->first(),
            'vmStatus' => $vmStatus,
            'vmGuestUsername' => $vmGuestUsername,
            'vmHasStoredPassword' => $vmHasStoredPassword,
        ]);
    }

    /**
     * Notes tab: super-users (hosting.manage) can add internal notes about the product/service.
     */
    public function storeNote(Request $request, HostingAccount $hostingAccount): RedirectResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'max:5000'],
            'is_important' => ['sometimes', 'boolean'],
        ]);

        $note = HostingNote::create([
            'hosting_account_id' => $hostingAccount->id,
            'user_id' => $request->user()->id,
            'note' => $validated['note'],
            'is_important' => $request->boolean('is_important'),
        ]);

        $this->hostingService->audit($hostingAccount, 'hosting.note_added', "Note #{$note->id} added to #{$hostingAccount->id}", ['by' => $request->user()->email, 'note_id' => $note->id]);

        return redirect()->route('admin.hosting.show', ['hostingAccount' => $hostingAccount, 'tab' => 'notes'])->with('success', 'Note added.');
    }

    public function updateNote(Request $request, HostingAccount $hostingAccount, HostingNote $note): RedirectResponse
    {
        abort_unless($note->hosting_account_id === $hostingAccount->id, 404);

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:5000'],
            'is_important' => ['sometimes', 'boolean'],
        ]);

        $note->update([
            'note' => $validated['note'],
            'is_important' => $request->boolean('is_important'),
        ]);

        $this->hostingService->audit($hostingAccount, 'hosting.note_updated', "Note #{$note->id} updated on #{$hostingAccount->id}", ['by' => $request->user()->email, 'note_id' => $note->id]);

        return redirect()->route('admin.hosting.show', ['hostingAccount' => $hostingAccount, 'tab' => 'notes'])->with('success', 'Note updated.');
    }

    public function destroyNote(Request $request, HostingAccount $hostingAccount, HostingNote $note): RedirectResponse
    {
        abort_unless($note->hosting_account_id === $hostingAccount->id, 404);

        $note->delete();

        $this->hostingService->audit($hostingAccount, 'hosting.note_deleted', "Note #{$note->id} deleted from #{$hostingAccount->id}", ['by' => $request->user()->email, 'note_id' => $note->id]);

        return redirect()->route('admin.hosting.show', ['hostingAccount' => $hostingAccount, 'tab' => 'notes'])->with('success', 'Note deleted.');
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
            ->with(['subnet.vlan', 'subnet.datacenter'])
            ->orderBy('id')
            ->get();

        $availableIps = IpAddress::query()
            ->whereNull('assigned_to_type')
            ->with(['subnet.vlan'])
            ->orderBy('id')
            ->limit(self::AVAILABLE_IP_LIMIT)
            ->get(['id', 'subnet_id', 'ip_address', 'ip_version', 'type']);

        $ipSubnets = IpSubnet::orderBy('subnet_cidr')->get(['id', 'name', 'subnet_cidr']);

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
            'ipSubnets' => $ipSubnets,
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

        $originalHostName = $hostingAccount->getRawOriginal('host_name') ?? $hostingAccount->getOriginal('host_name');

        try {
            DB::transaction(function () use ($hostingAccount, $validated, $request) {
                $hostingAccount->update($validated);

                $this->hostingService->audit(
                    $hostingAccount,
                    'hosting.updated',
                    "Product/Service #{$hostingAccount->id} updated",
                    ['by' => $request->user()->email],
                );
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not update product/service: '.$e->getMessage()]);
        }

        $redirect = redirect()->route('admin.hosting.show', $hostingAccount);

        $renameOutcome = $this->syncHypervRenameOnHostNameChange($hostingAccount, $originalHostName);

        if ($renameOutcome === null) {
            return $redirect->with('success', "Product/Service #{$hostingAccount->id} updated.");
        }

        if ($renameOutcome['success']) {
            return $redirect->with(
                'success',
                "Product/Service #{$hostingAccount->id} updated. Hyper-V VM renamed from '{$renameOutcome['old']}' to '{$renameOutcome['new']}'."
            );
        }

        // The account keeps the new host_name (the VM GUID linkage makes that
        // safe) — the failed rename is surfaced loudly next to the success.
        return $redirect
            ->with('success', "Product/Service #{$hostingAccount->id} updated.")
            ->with('error', 'VM rename failed: '.$renameOutcome['message']);
    }

    /**
     * Rename the Hyper-V VM on the host after the admin changed the hosting
     * account's host_name.
     *
     * Returns null when there is nothing to do (name unchanged, no enabled
     * hyperv product link, or no mirrored ServiceInstance/PanelAccount — a
     * host_name change on a non-provisioned service still saves cleanly).
     * Otherwise returns ['success' => bool, 'old'/'new'/'message'] and records
     * the attempt as an `update` provisioning event (action=rename).
     *
     * @return array{success:bool,old:string,new:string,message:string}|null
     */
    private function syncHypervRenameOnHostNameChange(HostingAccount $hostingAccount, mixed $originalHostName): ?array
    {
        $old = trim((string) ($originalHostName ?? ''));
        $newRaw = $hostingAccount->getAttributes()['host_name'] ?? null;
        $new = trim((string) ($newRaw ?? ''));

        if ($old === '' || $new === '' || $old === $new) {
            return null;
        }

        $link = ProductModule::where('product_id', $hostingAccount->product_id)
            ->where('module_slug', 'hyperv')
            ->where('enabled', true)
            ->first();

        if ($link === null) {
            return null;
        }

        // Mirrored service only — never create one here.
        $service = null;
        if ($hostingAccount->order_id !== null) {
            $service = ServiceInstance::where('order_id', $hostingAccount->order_id)->first();
        }
        if ($service === null) {
            $service = ServiceInstance::where('service_tag', 'HOST-'.$hostingAccount->id)->first();
        }

        if ($service === null) {
            return null;
        }

        $panelAccount = PanelAccount::where('service_instance_id', $service->id)
            ->where('panel', 'hyperv')
            ->first();

        if ($panelAccount === null) {
            return null;
        }

        $driver = $this->hypervRenameDriver();

        if ($driver === null) {
            return null;
        }

        $payload = [
            'module' => 'hyperv',
            'action' => 'rename',
            'hosting_account_id' => $hostingAccount->id,
            'order_id' => $hostingAccount->order_id,
            'order_number' => $hostingAccount->order?->order_number,
            'old_name' => $old,
            'new_name' => $new,
        ];

        try {
            $event = $this->provisioningEvents->begin('update', $payload, $service->id, $hostingAccount->id);
        } catch (\Throwable $e) {
            Log::error('Hyper-V rename event could not be opened', [
                'hosting_account_id' => $hostingAccount->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'old' => $old, 'new' => $new, 'message' => $e->getMessage()];
        }

        try {
            $result = $driver->rename($service, $new);
        } catch (\Throwable $e) {
            Log::error('Hyper-V rename threw', [
                'hosting_account_id' => $hostingAccount->id,
                'old_name' => $old,
                'new_name' => $new,
                'error' => $e->getMessage(),
            ]);
            $this->provisioningEvents->fail($event, $e->getMessage());

            return ['success' => false, 'old' => $old, 'new' => $new, 'message' => $e->getMessage()];
        }

        if (! $result->success) {
            $message = $result->message ?? 'VM rename failed.';
            $this->provisioningEvents->fail($event, $message);

            return ['success' => false, 'old' => $old, 'new' => $new, 'message' => $message];
        }

        $this->provisioningEvents->complete(
            $event,
            $result->message ?? "Hyper-V VM renamed to '{$new}'",
            is_array($result->data ?? null) ? $result->data : []
        );

        return ['success' => true, 'old' => $old, 'new' => $new, 'message' => $result->message ?? 'renamed'];
    }

    /**
     * Resolve the Hyper-V driver when it exposes rename(), else null (skip
     * silently). Mirrors the moduleAction() resolution order (registry first,
     * ModuleManager fallback).
     */
    private function hypervRenameDriver(): ?object
    {
        try {
            $registry = app(IntegrationRegistry::class);

            if ($registry->has('hyperv')) {
                $candidate = $registry->instanceFor('hyperv');

                return $candidate !== null && method_exists($candidate, 'rename') ? $candidate : null;
            }

            $module = app(ModuleManager::class)->find('hyperv');

            if ($module === null || $module->status !== \App\Models\Module::STATUS_ACTIVE) {
                return null;
            }

            $driver = app(ModuleManager::class)->capabilityInstance($module, 'provisioning');

            return $driver !== null && method_exists($driver, 'rename') ? $driver : null;
        } catch (\Throwable $e) {
            Log::error('Hyper-V rename driver resolution failed', ['error' => $e->getMessage()]);

            return null;
        }
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

        if ($moduleError = $this->syncModuleOnHostingChange($hostingAccount, 'terminate')) {
            return redirect()
                ->route('admin.hosting.index')
                ->with('success', "Product/Service #{$hostingAccount->id} terminated locally.")
                ->with('error', $moduleError);
        }

        return redirect()
            ->route('admin.hosting.index')
            ->with('success', "Product/Service #{$hostingAccount->id} terminated (module synced).");
    }

    /**
     * Best-effort remote sync for the local hosting lifecycle: calls every
     * enabled provisioning module on the account's mirrored service instance.
     * Returns null when everything synced (or no module applies), otherwise a
     * human-readable error for the flash message. Never throws.
     */
    private function syncModuleOnHostingChange(HostingAccount $hostingAccount, string $verb): ?string
    {
        try {
            $registry = app(IntegrationRegistry::class);
            $manager = app(ModuleManager::class);
            $links = $hostingAccount->product?->moduleLinks()->where('enabled', true)->get() ?? collect();
            $errors = [];

            foreach ($links as $link) {
                $slug = trim((string) ($link->module_slug ?? ''));
                if ($slug === '') {
                    continue;
                }

                $driver = null;
                $name = $registry->nameFor($slug);

                if ($registry->has($slug)) {
                    $candidate = $registry->instanceFor($slug);
                    if ($candidate !== null && method_exists($candidate, $verb)) {
                        $driver = $candidate;
                    }
                } else {
                    $module = $manager->find($slug);
                    if ($module === null || $module->status !== \App\Models\Module::STATUS_ACTIVE) {
                        continue;
                    }
                    $driver = $manager->capabilityInstance($module, 'provisioning');
                    $name = $module->name ?? $name;
                }

                if ($driver === null) {
                    continue;
                }

                $config = $registry->decryptConfigFor($slug, is_array($link->config ?? null) ? $link->config : []);
                $service = $this->serviceForHosting($hostingAccount, $slug);

                try {
                    $result = $driver->{$verb}($service, $config);

                    if ($result->success) {
                        // ok
                    } else {
                        $errors[] = "{$name}: ".($result->message ?? "{$verb} failed");
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('Hosting lifecycle module sync threw', [
                        'hosting_account_id' => $hostingAccount->id,
                        'module' => $slug,
                        'action' => $verb,
                        'error' => $e->getMessage(),
                    ]);
                    $errors[] = "{$name}: {$e->getMessage()}";
                }
            }

            if ($errors !== []) {
                return 'Remote sync incomplete — '.implode('; ', $errors);
            }

            return null;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Hosting lifecycle module sync failed', [
                'hosting_account_id' => $hostingAccount->id,
                'action' => $verb,
                'error' => $e->getMessage(),
            ]);

            return "Remote sync could not run: {$e->getMessage()}";
        }
    }

    public function suspend(Request $request, HostingAccount $hostingAccount): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        try {
            $this->hostingService->suspend($hostingAccount, $validated['reason'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        // Best-effort remote sync: the local suspend is committed; a module
        // refusal is surfaced, not rolled back (same contract as
        // OrderService::applyLifecycleEffects).
        if ($moduleError = $this->syncModuleOnHostingChange($hostingAccount, 'suspend')) {
            return back()
                ->with('success', "Product/Service #{$hostingAccount->id} suspended locally.")
                ->with('error', $moduleError);
        }

        return back()->with('success', "Product/Service #{$hostingAccount->id} suspended (module synced).");
    }

    public function unsuspend(HostingAccount $hostingAccount): RedirectResponse
    {
        try {
            $this->hostingService->unsuspend($hostingAccount);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($moduleError = $this->syncModuleOnHostingChange($hostingAccount, 'unsuspend')) {
            return back()
                ->with('success', "Product/Service #{$hostingAccount->id} reactivated locally.")
                ->with('error', $moduleError);
        }

        return back()->with('success', "Product/Service #{$hostingAccount->id} reactivated (module synced).");
    }

    /**
     * Direct module action on a hosting account (Hyper-V: Create / Start /
     * Stop / Restart / Delete; other modules: legacy Create / Suspend /
     * Unsuspend / Terminate / Delete).
     *
     * Direct means the enabled module instance is called straight from the
     * hosting account — NOT via the order ProvisioningDispatcher. The target
     * ServiceInstance is the hosting account's order service when one exists,
     * otherwise a minimal instance mirrored from the hosting row (same
     * server/domain/identity) so compute modules have a server to talk to.
     * Local hosting status follows only when the module reports success, and
     * every outcome is audited.
     *
     * Production guards: Restart and Delete require typing the account's
     * host_name (typed confirmation, not a single click); Delete refuses a
     * Running VM (stop first — never stop-and-delete in one click); the
     * power actions are state-checked on the host (no blind -Force).
     */
    public function moduleAction(Request $request, HostingAccount $hostingAccount): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'module_slug' => ['required', 'string', 'max:100'],
            'action' => ['required', 'string', 'in:create,start,stop,restart,delete,suspend,unsuspend,terminate'],
            'confirm' => ['nullable', 'string', 'max:255'],
            'delete_vhd' => ['nullable', 'boolean'],
            'template_vm' => ['nullable', 'string', 'max:64'],
            'start_after_create' => ['nullable', 'boolean'],
            'guest_username' => ['nullable', 'string', 'max:64'],
            // The opt-in rotation must authenticate inside the guest with the
            // template's CURRENT password before it can set a new one.
            'guest_password' => ['nullable', 'string', 'max:128', Rule::requiredIf(fn () => $request->boolean('apply_password'))],
            'apply_password' => ['nullable', 'boolean'],
        ], [
            'guest_password.required' => "Enter the template's current Administrator password so the panel can set a new one.",
        ]);

        $slug = trim((string) $validated['module_slug']);
        $registry = app(IntegrationRegistry::class);
        $manager = app(ModuleManager::class);

        // Hyper-V create → async queued job (host-verified)
        if ($validated['action'] === 'create' && $slug === 'hyperv') {
            $templateVm = isset($validated['template_vm']) ? trim((string) $validated['template_vm']) : null;
            if ($templateVm === '') {
                $templateVm = null;
            }
            $guestUsername = isset($validated['guest_username']) ? trim((string) $validated['guest_username']) : null;
            if ($guestUsername === '') {
                $guestUsername = null;
            }
            $guestPassword = isset($validated['guest_password']) ? trim((string) $validated['guest_password']) : null;
            if ($guestPassword === '') {
                $guestPassword = null;
            }
            $startAfterCreate = (bool) $request->boolean('start_after_create');

            // Guard: a build already queued/running for this account (shared
            // with the client portal through the dispatcher service).
            if ($this->vmBuildDispatcher->isBuildRunning($hostingAccount)) {
                $msg = 'A VM build is already running for this service.';
                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json(['ok' => false, 'message' => $msg], 409);
                }
                return back()->with('error', $msg);
            }

            try {
                $event = $this->vmBuildDispatcher->dispatch(
                    $hostingAccount,
                    $templateVm,
                    $startAfterCreate,
                    $guestUsername,
                    $guestPassword,
                    (bool) $request->boolean('apply_password'),
                );

                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json(['ok' => true, 'started' => true, 'event_id' => $event->id, 'action' => 'create', 'message' => 'VM build started.'], 202);
                }

                return back()->with('info', 'VM build started.');
            } catch (\Throwable $e) {
                Log::error('Hyper-V queued create failed', [
                    'hosting_account_id' => $hostingAccount->id,
                    'error' => $e->getMessage(),
                ]);
                $msg = $e->getMessage();
                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json(['ok' => false, 'message' => $msg], 422);
                }
                return back()->with('error', $msg);
            }
        }

        $driver = null;
        $displayName = $registry->nameFor($slug);
        $link = $hostingAccount->product?->moduleLinks()->where('module_slug', $slug)->first();

        if ($link === null || ! (bool) $link->enabled) {
            return back()->with('error', "Module {$displayName} is not enabled on this product.");
        }

        if ($registry->has($slug)) {
            $driver = $registry->instanceFor($slug);
            if ($driver === null || ! method_exists($driver, 'provision')) {
                return back()->with('error', "Module {$displayName} cannot provision.");
            }
        } else {
            $module = $manager->find($slug);
            if ($module === null || $module->status !== \App\Models\Module::STATUS_ACTIVE) {
                return back()->with('error', 'Module is not active.');
            }
            $displayName = $module->name ?? $displayName;
            $driver = $manager->capabilityInstance($module, 'provisioning');
            if ($driver === null) {
                return back()->with('error', "Module {$displayName} cannot provision.");
            }
        }

        $config = $registry->decryptConfigFor($slug, is_array($link->config ?? null) ? $link->config : []);
        $service = $this->serviceForHosting($hostingAccount, $slug);

        // The VM is named after the product's hostname so host and record
        // agree (HyperV::createRemote reads host_name first).
        if ($validated['action'] === 'create') {
            $config['host_name'] = (string) $hostingAccount->host_name;
        }

        // Hyper-V power verbs + legacy billing verbs (kept for other modules
        // and old clients). delete stays aliased to terminate.
        $verbMap = [
            'create' => 'provision',
            'start' => 'unsuspend',
            'stop' => 'suspend',
            'restart' => 'restart',
            'delete' => 'terminate',
            'suspend' => 'suspend',
            'unsuspend' => 'unsuspend',
            'terminate' => 'terminate',
        ];
        $verb = $verbMap[$validated['action']] ?? $validated['action'];

        // Power actions on a terminated service would hand out free compute
        // (and Start would even re-activate local billing status below).
        // Create (re-provision) and Delete (cleanup) stay allowed.
        if ($hostingAccount->status === HostingService::STATUS_TERMINATED
            && in_array($validated['action'], ['start', 'stop', 'restart'], true)) {
            $msg = "Service is terminated — {$validated['action']} is refused. Create re-provisions, Delete cleans up.";
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => $msg], 422);
            }
            return back()->with('error', $msg);
        }

        // Typed confirmation for disruptive actions: the operator must type
        // the visible host_name, not just click through a dialog.
        if (in_array($validated['action'], ['restart', 'delete'], true)) {
            $expected = (string) $hostingAccount->host_name;
            if (trim((string) ($validated['confirm'] ?? '')) !== $expected) {
                $msg = "Type '{$expected}' to confirm {$validated['action']}. Action cancelled — nothing was touched.";
                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json(['ok' => false, 'message' => $msg], 422);
                }
                return back()->with('error', $msg);
            }
        }

        // Per-action VHD choice for Hyper-V delete. The form always submits
        // delete_vhd (hidden 0 + checkbox), so an unchecked box genuinely
        // orphans the disk; callers without the key fall back to config.
        if ($validated['action'] === 'delete' && array_key_exists('delete_vhd', $validated)) {
            $config['delete_vhd_on_terminate'] = (bool) $request->boolean('delete_vhd');
        }

        if ($verb === 'restart' && ! method_exists($driver, 'restart')) {
            $msg = "Module {$displayName} does not support restart.";
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => $msg], 422);
            }
            return back()->with('error', $msg);
        }

        $moduleActionEventTypes = [
            'create' => 'provision',
            'start' => 'unsuspend',
            'stop' => 'suspend',
            'restart' => 'restart',
            'delete' => 'terminate',
            'suspend' => 'suspend',
            'unsuspend' => 'unsuspend',
            'terminate' => 'terminate',
        ];
        $moduleActionEventType = $moduleActionEventTypes[$validated['action']] ?? $validated['action'];

        $event = $this->provisioningEvents->begin(
            $moduleActionEventType,
            [
                'module' => $slug,
                'action' => $validated['action'],
                'hosting_account_id' => $hostingAccount->id,
                'order_id' => $hostingAccount->order_id,
                'order_number' => $hostingAccount->order?->order_number,
            ],
            $service->id,
            $hostingAccount->id,
        );

        try {
            /** @var \App\Contracts\Integrations\ProvisioningResult $result */
            $result = $driver->{$verb}($service, $config);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Hosting module action threw', [
                'hosting_account_id' => $hostingAccount->id,
                'module' => $slug,
                'action' => $verb,
                'error' => $e->getMessage(),
            ]);

            $this->provisioningEvents->fail($event, $e->getMessage());

            $msg = "Module action failed: {$e->getMessage()}";
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => $msg], 422);
            }
            return back()->with('error', $msg);
        }

        if (! $result->success) {
            $this->provisioningEvents->fail($event, $result->message ?? 'Module action failed.');

            $msg = $result->message ?? 'Module action failed.';
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => $msg], 422);
            }
            return back()->with('error', $msg);
        }

        $this->provisioningEvents->complete($event, $result->message ?? 'ok', is_array($result->data ?? null) ? $result->data : []);

        try {
            $hostingAccount->refresh();

            if (in_array($verb, ['provision', 'unsuspend'], true) && $hostingAccount->status !== HostingService::STATUS_ACTIVE) {
                $this->hostingService->unsuspend($hostingAccount);
            } elseif ($verb === 'suspend' && $hostingAccount->status === HostingService::STATUS_ACTIVE) {
                $this->hostingService->suspend($hostingAccount, 'Module action: '.$slug);
            } elseif ($verb === 'terminate' && $hostingAccount->status !== HostingService::STATUS_TERMINATED) {
                $this->hostingService->terminate($hostingAccount, 'Module action: '.$slug);
            }
            // restart: host power-cycled, local billing status untouched.
        } catch (RuntimeException $e) {
            // Module already succeeded — the local guard (e.g. already in the
            // target status) must not mask that.
            $this->hostingService->audit($hostingAccount, 'hosting.module_action', "Module {$slug} {$verb} succeeded; local status already {$hostingAccount->status}", ['module' => $slug, 'action' => $verb]);
        }

        $this->hostingService->audit($hostingAccount, 'hosting.module_action', "Module {$slug} {$verb}: ".($result->message ?? 'ok'), ['module' => $slug, 'action' => $verb]);

        if ($request->expectsJson() || $request->ajax()) {
            $state = $result->data['state'] ?? null;
            return response()->json(['ok' => true, 'action' => $validated['action'], 'message' => $result->message ?? 'done.', 'state' => $state]);
        }

        if ($verb === 'terminate') {
            return redirect()->route('admin.hosting.index')->with('success', "Module {$displayName} {$validated['action']}: ".($result->message ?? 'done.'));
        }

        return back()->with('success', "Module {$displayName} {$validated['action']}: ".($result->message ?? 'done.'));
    }

    /**
     * ServiceInstance the module operates on: delegated to the shared
     * ManualProvisioner factory so the mirror logic lives in one place.
     */
    private function serviceForHosting(HostingAccount $hostingAccount, string $slug): \App\Models\ServiceInstance
    {
        return $this->manualProvisioner->serviceForHosting($hostingAccount, $slug);
    }

    /**
     * JSON status/progress API for the admin VM panel. Always 200, never throws.
     */
    public function vmStatus(Request $request, HostingAccount $hostingAccount): JsonResponse
    {
        try {
            $data = $this->vmStatusPresenter->build($hostingAccount, $request->boolean('refresh'));
            return response()->json($data);
        } catch (\Throwable $e) {
            Log::warning('vmStatus failed', ['hosting_account_id' => $hostingAccount->id, 'error' => $e->getMessage()]);
            return response()->json(['ok' => true, 'action' => null, 'vm' => ['exists' => false, 'state' => null, 'name' => null, 'vmId' => null, 'probe_error' => null], 'account' => ['status' => $hostingAccount->status], 'credentials' => ['stored' => false, 'username' => 'Administrator'], 'can' => ['create' => true, 'start' => false, 'stop' => false, 'restart' => false, 'delete' => false, 'reset_password' => false], 'reasons' => []]);
        }
    }

    /**
     * Reveal the stored guest Administrator credentials for a VM.
     *
     * WHY on-demand + audited: the password lives encrypted on the
     * PanelAccount and must never be server-rendered into the page HTML —
     * the Credentials modal fetches it here only when the operator clicks
     * Show/Copy. Every successful reveal is audited (best-effort so the
     * audit never blocks the response). Never 500s — unknown states return
     * the 422 {ok:false, stored:false} shape.
     */
    public function vmCredentials(Request $request, HostingAccount $hostingAccount): JsonResponse
    {
        $notStored = static fn (?string $message = null): JsonResponse => response()->json([
            'ok' => false,
            'stored' => false,
            'message' => $message ?? 'No Administrator credentials are stored for this VM.',
        ], 422);

        try {
            // Lookup only — a GET must never create a ServiceInstance row
            // (same approach as VmStatusPresenter::panelAccountFor).
            $service = null;
            try {
                if ($hostingAccount->order_id !== null) {
                    $service = ServiceInstance::where('order_id', $hostingAccount->order_id)->first();
                }
                if ($service === null) {
                    $service = ServiceInstance::where('service_tag', 'HOST-'.$hostingAccount->id)->first();
                }
            } catch (\Throwable) {
                $service = null;
            }

            $panel = $service !== null
                ? PanelAccount::where('service_instance_id', $service->id)->where('panel', 'hyperv')->first()
                : null;

            if ($panel === null) {
                return $notStored();
            }

            $stored = app(\App\Services\Provisioning\VmGuestCredentialStore::class)->read($panel);

            if (($stored['password'] ?? null) === null || $stored['password'] === '') {
                return $notStored();
            }

            $username = ($stored['username'] ?? null) !== null && trim((string) $stored['username']) !== ''
                ? trim((string) $stored['username'])
                : 'Administrator';

            try {
                $this->hostingService->audit($hostingAccount, 'hosting.module_action', 'Administrator credentials revealed', ['module' => 'hyperv', 'action' => 'reveal_credentials']);
            } catch (\Throwable) {
                // Audit must never block the response.
            }

            return response()->json([
                'ok' => true,
                'stored' => true,
                'username' => $username,
                'password' => $stored['password'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('vmCredentials failed', ['hosting_account_id' => $hostingAccount->id, 'error' => $e->getMessage()]);

            return $notStored();
        }
    }

    public function resetVmPassword(Request $request, HostingAccount $hostingAccount): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
            'current_password' => ['nullable', 'string', 'max:128'],
            'username' => ['nullable', 'string', 'max:64'],
        ]);

        $newPassword = $validated['password'];
        $username = isset($validated['username']) ? trim((string) $validated['username']) : null;
        if ($username === '') {
            $username = null;
        }
        $currentPassword = $validated['current_password'] ?? null;

        // Resolve service + driver for reset
        $service = $this->serviceForHosting($hostingAccount, 'hyperv');
        $driver = HypervDriver::resolve();

        if ($driver === null || ! method_exists($driver, 'resetGuestAdminPassword')) {
            $msg = 'Hyper-V module does not support password reset.';
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => $msg], 422);
            }
            return back()->with('error', $msg);
        }

        // Check stored credentials requirement
        try {
            $panel = PanelAccount::where('service_instance_id', $service->id)->where('panel', 'hyperv')->first();
            $store = app(\App\Services\Provisioning\VmGuestCredentialStore::class);
            $stored = $panel !== null ? $store->read($panel) : ['username' => null, 'password' => null];
            if (($stored['password'] ?? null) === null && ($currentPassword === null || trim($currentPassword) === '')) {
                $msg = 'No Administrator credentials are stored for this VM — enter the current password.';
                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json(['ok' => false, 'message' => $msg], 422);
                }
                return back()->with('error', $msg);
            }
        } catch (\Throwable) {
        }

        // Record attempt as provisioning event
        try {
            $event = $this->provisioningEvents->begin('update', [
                'module' => 'hyperv',
                'action' => 'reset_password',
                'hosting_account_id' => $hostingAccount->id,
                'order_id' => $hostingAccount->order_id,
            ], $service->id, $hostingAccount->id);
        } catch (\Throwable $e) {
            Log::error('resetVmPassword event begin failed', ['error' => $e->getMessage()]);
            $event = null;
        }

        try {
            $result = $driver->resetGuestAdminPassword($service, $newPassword, $username, $currentPassword);
        } catch (\Throwable $e) {
            if ($event !== null) {
                try { $this->provisioningEvents->fail($event, $e->getMessage()); } catch (\Throwable) {}
            }
            $msg = $e->getMessage();
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => $msg], 422);
            }
            return back()->with('error', $msg);
        }

        if (! $result->success) {
            if ($event !== null) {
                try { $this->provisioningEvents->fail($event, $result->message ?? 'Password reset failed'); } catch (\Throwable) {}
            }
            $msg = $result->message ?? 'Password reset failed';
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => $msg], 422);
            }
            return back()->with('error', $msg);
        }

        if ($event !== null) {
            try { $this->provisioningEvents->complete($event, $result->message ?? 'Administrator password reset', is_array($result->data ?? null) ? $result->data : []); } catch (\Throwable) {}
        }
        try { $this->hostingService->audit($hostingAccount, 'hosting.module_action', $result->message ?? 'Password reset', ['module' => 'hyperv', 'action' => 'reset_password']); } catch (\Throwable) {}

        $resolvedUsername = $result->data['username'] ?? $username ?? 'Administrator';

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => true, 'password' => $newPassword, 'username' => $resolvedUsername, 'message' => 'Administrator password reset']);
        }

        return back()->with('success', 'Administrator password reset');
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
                    "Product/Service #{$hostingAccount->id} password changed",
                    ['by' => $request->user()->email],
                );
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not change password: '.$e->getMessage()]);
        }

        return back()->with('success', "Product/Service #{$hostingAccount->id} password changed.");
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

        return back()->with('success', "Product/Service #{$hostingAccount->id} package changed.");
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
                    "Billing info for Product/Service #{$hostingAccount->id} updated",
                    ['by' => $request->user()->email],
                );
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not update billing info: '.$e->getMessage()]);
        }

        return back()->with('success', "Billing info for Product/Service {$hostingAccount->username} updated.");
    }

    /**
     * JSON endpoint for the searchable "Assign selected" pool on the edit page.
     * Searches the *entire* free pool (not just the first 100), so an address
     * like 10.1.3.133 is findable even when it is not in the initial slice.
     */
    public function searchAvailableIps(Request $request, HostingAccount $hostingAccount): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $subnetId = $request->query('subnet_id');
        $limit = min(100, max(10, (int) $request->query('limit', 100)));

        $query = IpAddress::query()
            ->whereNull('assigned_to_type')
            ->with('subnet:id,name,subnet_cidr');

        if ($subnetId !== null && $subnetId !== '' && ctype_digit((string) $subnetId)) {
            $query->where('subnet_id', (int) $subnetId);
        }

        if ($q !== '') {
            $query->where(function ($qq) use ($q) {
                $qq->where('ip_address', 'like', "%{$q}%")
                    ->orWhereHas('subnet', function ($sq) use ($q) {
                        $sq->where('subnet_cidr', 'like', "%{$q}%")
                            ->orWhere('name', 'like', "%{$q}%");
                    });
            });
        }

        $ips = $query->orderBy('id')->limit($limit)->get(['id', 'subnet_id', 'ip_address', 'ip_version', 'type', 'ptr_record']);

        return response()->json($ips->map(fn ($ip) => [
            'id' => $ip->id,
            'ip_address' => $ip->ip_address,
            'ip_version' => $ip->ip_version,
            'type' => $ip->type,
            'type_label' => ucfirst(str_replace('_', ' ', $ip->type ?? 'available')),
            'ptr_record' => $ip->ptr_record,
            'subnet_name' => $ip->subnet?->name,
            'subnet_cidr' => $ip->subnet?->subnet_cidr,
        ]));
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
            ? "IP {$list} assigned to #{$hostingAccount->id}."
            : "{$n} IPs assigned to #{$hostingAccount->id}: {$list}.");
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

        $message = 'Assigned '.$result['assigned']->count().' IP(s) to #'.$hostingAccount->id.': '
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
            ? "IP lease released from #{$hostingAccount->id}."
            : count($ids)." IP leases released from #{$hostingAccount->id}.");
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
     *     assetRelationships: Collection<int, AssetRelationship>,
     *     assetNames: array<int, array{parent: ?string, child: ?string}>,
     *     inventoryAssets: Collection<int, InventoryAsset>,
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
                ->get(['id', 'name', 'ip_address', 'server_type']),
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

        $domainRules = ['nullable', 'string', 'max:255', 'regex:'.self::DOMAIN_PATTERN];

        if ($customerId > 0) {
            $domainRules[] = Rule::unique('hosting_accounts', 'domain')
                ->where(fn ($q) => $q->where('customer_id', $customerId))
                ->ignore($ignore);
        }

        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'server_id' => ['nullable', 'integer', 'exists:servers,id'],
            // username is legacy/module-managed — optional, no longer required or unique enforced
            'username' => ['nullable', 'string', 'max:100'],
            'domain' => $domainRules,
            // host_name is the product's identifier across the app — globally unique, auto-generated when blank
            'host_name' => ['nullable', 'string', 'max:255', Rule::unique('hosting_accounts', 'host_name')->ignore($ignore)],
            'username_prefix' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
