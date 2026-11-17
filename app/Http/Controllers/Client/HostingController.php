<?php

namespace App\Http\Controllers\Client;

use App\Contracts\Integrations\Capabilities\HostingAccountInfoProvider;
use App\Http\Controllers\Controller;
use App\Models\HostingAccount;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PanelAccount;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Services\Billing\AddOnService;
use App\Services\HostingService;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Modules\ModuleManager;
use App\Services\OrderConfigSnapshot;
use App\Services\Provisioning\ComputeTemplateCatalog;
use App\Services\Provisioning\ManualProvisioner;
use App\Services\Provisioning\ProvisioningDispatcher;
use App\Services\Provisioning\ProvisioningEventRecorder;
use App\Services\Provisioning\VmBuildDispatcher;
use App\Services\Provisioning\VmGuestCredentialStore;
use App\Services\Provisioning\VmOperationConflictException;
use App\Services\Provisioning\VmOperationDispatcher;
use App\Services\Provisioning\VmStatusPresenter;
use App\Support\Logging\AppLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Client portal — hosting account listing and detail.
 */
class HostingController extends Controller
{
    private const PER_PAGE = 15;

    /**
     * The priced add-on catalogue per product + cycle, memoised for one page.
     *
     * @var array<string, array{product_id: int, options: Collection<int, array{addon: ProductAddon, cycle: string, unit_price: float, setup_fee: float}>}>
     */
    private array $addonCatalogueCache = [];

    public function __construct(
        private readonly OrderConfigSnapshot $snapshot,
        private readonly ManualProvisioner $provisioner,
        private readonly VmBuildDispatcher $vmBuildDispatcher,
        private readonly VmOperationDispatcher $vmOperationDispatcher,
        private readonly VmStatusPresenter $vmStatusPresenter,
        private readonly ProvisioningEventRecorder $provisioningEvents,
        private readonly HostingService $hostingService,
        private readonly AddOnService $addons,
    ) {}

    public function index(Request $request): View
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $search = trim((string) $request->query('search'));
        $status = $request->query('status');

        $accounts = $customer->hostingAccounts()
            ->with([
                'product:id,name,billing_cycle',
                'server:id,name',
                'ipAddresses:id,assigned_to_type,assigned_to_id,ip_address,type',
                'order:id,order_number,status,billing_cycle,next_billing_date,domain_name',
                'order.items:id,order_id,product_id,product_addon_id,parent_item_id,billing_cycle,unit_price,total,next_billing_date,domain_name,quantity',
            ])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('domain', 'like', "%{$search}%")
                        ->orWhere('host_name', 'like', "%{$search}%")
                        ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('server', fn ($s) => $s->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('order', fn ($o) => $o->where('domain_name', 'like', "%{$search}%"));
                });
            })
            ->when(in_array($status, HostingService::STATUSES, true), function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->gridSort([
                'id' => 'id',
                'host_name' => 'host_name',
                'product' => 'product.name',
                'server' => 'server.name',
                'domain' => 'domain',
                'cycle' => 'order.billing_cycle',
                'amount' => 'order.total',
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

        // The money trail per account: the invoice that actually bills the
        // service line (never an add-on line), keyed by account id.
        $serviceInvoiceByAccount = [];
        foreach ($accounts as $account) {
            $serviceInvoiceByAccount[(int) $account->id] = $this->serviceInvoiceFor($customer, $account);
        }

        $customerDomains = $customer->domains()->latest()->get();

        $addonItems = OrderItem::query()
            ->whereHas('order', fn ($q) => $q->where('customer_id', $customer->id))
            ->whereNotNull('product_addon_id')
            ->with(['order:id,order_number,status', 'parentItem:id,product_name,product_id'])
            ->latest()
            ->get();

        // Resolve the service account behind an add-on's parent line so each row can
        // link to it: "order_id:product_id" => hosting account id. The first
        // account for a key wins — an order carries one service.
        $serviceAccountByOrderProduct = [];

        foreach ($customer->hostingAccounts()->with('order.items:id,order_id,product_id,product_addon_id')->get(['id', 'order_id', 'product_id']) as $account) {
            $serviceAccountByOrderProduct[$account->order_id.':'.$account->product_id] ??= $account->id;

            // An upgrade rewrites the served item's product_id, not the
            // account's — register the line the account actually points at too,
            // so add-ons bought after an upgrade still link to their service.
            foreach ($account->order?->items?->whereNull('product_addon_id')->pluck('product_id')->unique() ?? [] as $pid) {
                $serviceAccountByOrderProduct[$account->order_id.':'.$pid] ??= $account->id;
            }
        }

        // Add-ons every service the customer owns can still buy — the offer
        // covers the whole book of services, not only the rows on this page.
        // The catalogue lookup is memoised per product + cycle, so a customer
        // with many services of one plan still costs one lookup.
        $addonPurchaseOptions = [];
        $purchaseReasons = [];

        $addonPurchaseAccounts = $customer->hostingAccounts()
            ->with([
                'product:id,name,billing_cycle',
                'order:id,order_number,status,billing_cycle,next_billing_date,domain_name',
                'order.items:id,order_id,product_id,product_addon_id,parent_item_id,billing_cycle,unit_price,total,next_billing_date,domain_name,quantity',
            ])
            ->get();

        foreach ($addonPurchaseAccounts as $account) {
            $purchase = $this->applicableAddonsFor($account);
            $purchaseReasons[] = $purchase['reason'];

            if ($purchase['options']->isNotEmpty()) {
                $addonPurchaseOptions[$account->id] = ['account' => $account, 'options' => $purchase['options']];
            }
        }

        $purchaseNotice = $this->purchaseNoticeFor($purchaseReasons);

        return view('client.hosting.index', compact('accounts', 'search', 'status', 'counts', 'billing', 'serviceInvoiceByAccount', 'customerDomains', 'addonItems', 'serviceAccountByOrderProduct', 'addonPurchaseOptions', 'purchaseNotice'));
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

        $provisionCard = $this->provisionCardFor($account);

        // Frozen vm-status contract shared with the admin page: drives the
        // progress panel, the reset-password button state and the polling
        // endpoint below. Built here so the first paint already knows.
        $vmStatus = $this->vmStatusPresenter->build($account);

        // The money trail: the invoice that covers THIS service line and the
        // customer's own domain record for the service's domain — so the page
        // can link the order, its invoice and the domain.
        $orderInvoice = $this->serviceInvoiceFor($customer, $account);

        $domainModel = null;
        $serviceDomain = trim((string) ($account->domain ?? ''));

        if ($serviceDomain !== '') {
            $domainModel = $customer->domains()->where('name', $serviceDomain)->first();
        }

        return view('client.hosting.show', array_merge([
            'account' => $account,
            'billing' => $this->billingFor($account),
            'modulePanels' => $modulePanels,
            'provisionCard' => $provisionCard,
            'vmStatus' => $vmStatus,
            'orderInvoice' => $orderInvoice,
            'domainModel' => $domainModel,
        ], $this->configurationFor($account)));
    }

    /**
     * Customer-triggered VM build. Always asynchronous: the durable running
     * event is opened and the queued job does the multi-minute clone, so the
     * web request returns in milliseconds (202/info) while the page polls
     * vm-status for progress. Client builds always start the VM afterwards.
     */
    public function provision(Request $request, HostingAccount $hostingAccount): JsonResponse|RedirectResponse
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $account = $customer->hostingAccounts()->findOrFail($hostingAccount->id);

        $account->loadMissing(['product.moduleLinks', 'server']);

        $slug = $this->computeSlugFor($account);

        if ($slug === null) {
            $msg = 'This service does not support VM provisioning.';
            if ($this->wantsJson($request)) {
                return response()->json(['ok' => false, 'message' => $msg], 422);
            }

            return back()->with('error', $msg);
        }

        $validated = $request->validate([
            'template' => ['nullable', 'string', 'max:64'],
            // Legacy field name from the Hyper-V-only form; still accepted.
            'template_vm' => ['nullable', 'string', 'max:64'],
        ]);

        $template = trim((string) ($validated['template'] ?? $validated['template_vm'] ?? ''));
        $template = $template !== '' ? $template : null;

        // Pending accounts may always (re)build. An active account may build
        // only when no VM exists on the host yet — WHY: hyperv-manual orders
        // activate immediately ("Awaiting manual VM provisioning"), so an
        // active account without a VM is waiting for self-provisioning, not
        // already provisioned. Anything else (suspended, terminated, or a VM
        // already on the host) is refused.
        if ($account->status !== HostingService::STATUS_PENDING) {
            $vmExists = $this->vmStatusPresenter->build($account)['vm']['exists'] ?? null;
            if (! ($account->status === HostingService::STATUS_ACTIVE && $vmExists === false)) {
                $msg = $vmExists === true
                    ? 'This service already has a VM.'
                    : 'This service cannot be provisioned in its current status.';
                if ($this->wantsJson($request)) {
                    return response()->json(['ok' => false, 'message' => $msg], 422);
                }

                return back()->with('error', $msg);
            }
        }

        // Guard: a build already queued/running for this account (shared
        // with the admin side through the dispatcher service).
        if ($this->vmBuildDispatcher->isBuildRunning($account)) {
            $msg = 'A VM build is already running for this service.';
            if ($this->wantsJson($request)) {
                return response()->json(['ok' => false, 'message' => $msg], 409);
            }

            return back()->with('error', $msg);
        }

        try {
            $event = $this->vmBuildDispatcher->dispatch($account, $template, true, null, null, false, $slug);

            if ($this->wantsJson($request)) {
                return response()->json([
                    'ok' => true,
                    'started' => true,
                    'event_id' => $event->id,
                    'action' => 'create',
                    'message' => 'Your VM build has started.',
                ], 202);
            }

            return back()->with('info', 'Your VM build has started — progress is shown on this page.');
        } catch (\Throwable $e) {
            AppLog::provisioning()->error('Client queued VM create failed', [
                'hosting_account_id' => $account->id,
                'module' => $slug,
                'error' => $e->getMessage(),
            ]);
            $msg = $e->getMessage();
            if ($this->wantsJson($request)) {
                return response()->json(['ok' => false, 'message' => $msg], 422);
            }

            return back()->with('error', $msg);
        }
    }

    /**
     * JSON status/progress API for the client VM panel. Customer-scoped
     * (another customer's id 404s) and never throws — same frozen contract
     * as the admin endpoint via the shared presenter.
     */
    public function vmStatus(Request $request, HostingAccount $hostingAccount): JsonResponse
    {
        // Customer scoping stays outside the try: another customer's id must
        // 404, never degrade to the 200 fallback below.
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $account = $customer->hostingAccounts()->findOrFail($hostingAccount->id);

        try {
            return response()->json($this->vmStatusPresenter->build($account, $request->boolean('refresh')));
        } catch (\Throwable $e) {
            AppLog::provisioning()->warning('client vmStatus failed', ['hosting_account_id' => $hostingAccount->id, 'error' => $e->getMessage()]);

            return response()->json([
                'ok' => true,
                'action' => null,
                'vm' => ['exists' => false, 'state' => null, 'name' => null, 'vmId' => null, 'probe_error' => null],
                'account' => ['status' => $hostingAccount->status],
                'credentials' => ['stored' => false, 'username' => 'Administrator'],
                'can' => ['create' => false, 'start' => false, 'stop' => false, 'restart' => false, 'delete' => false, 'reset_password' => false],
                'reasons' => [],
            ]);
        }
    }

    /**
     * Customer "Reset Administrator password": the customer types only the
     * NEW password — the panel authenticates into the running guest with the
     * credentials it already has stored. Queued through VmOperationDispatcher
     * (202/started contract, progress via vm-status), mirroring the admin
     * reset flow without ever echoing the password back.
     */
    public function resetVmPassword(Request $request, HostingAccount $hostingAccount): JsonResponse|RedirectResponse
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $account = $customer->hostingAccounts()->findOrFail($hostingAccount->id);

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ]);

        $newPassword = $validated['password'];

        // Resolve the compute module that owns this account (the dispatcher
        // re-validates the link/driver when it opens the event).
        $account->loadMissing(['product.moduleLinks', 'server']);
        $slug = $this->computeSlugFor($account);

        if ($slug === null) {
            $msg = 'Password reset is not available for this service.';
            if ($this->wantsJson($request)) {
                return response()->json(['ok' => false, 'message' => $msg], 422);
            }

            return back()->with('error', $msg);
        }

        $service = app(ManualProvisioner::class)->serviceForHosting($account, $slug);

        // The customer never knows the current password — without a stored
        // credential there is nothing to authenticate into the guest with.
        // Hyper-V-only: host-authoritative drivers such as Proxmox VE reset
        // without it.
        $storedUsername = null;
        if ($slug === 'hyperv') {
            try {
                $panel = PanelAccount::where('service_instance_id', $service->id)->where('panel', 'hyperv')->first();
                $stored = $panel !== null
                    ? app(VmGuestCredentialStore::class)->read($panel)
                    : ['username' => null, 'password' => null];
                if (($stored['password'] ?? null) === null) {
                    $msg = 'No stored credentials for this VM — contact support.';
                    if ($this->wantsJson($request)) {
                        return response()->json(['ok' => false, 'message' => $msg], 422);
                    }

                    return back()->with('error', $msg);
                }
                $storedUsername = $stored['username'] ?? null;
            } catch (\Throwable) {
            }
        }

        $options = ['new_password' => $newPassword];
        if (is_string($storedUsername) && trim($storedUsername) !== '') {
            $options['username'] = trim($storedUsername);
        }

        try {
            $event = $this->vmOperationDispatcher->dispatch($account, 'reset_password', $options, $request->user()?->id, $slug);
        } catch (VmOperationConflictException $e) {
            if ($this->wantsJson($request)) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 409);
            }

            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            AppLog::provisioning()->error('Client queued VM password reset failed', [
                'hosting_account_id' => $account->id,
                'module' => $slug,
                'error' => $e->getMessage(),
            ]);
            // Driver messages are already sanitized — pass through verbatim.
            $msg = $e->getMessage();
            if ($this->wantsJson($request)) {
                return response()->json(['ok' => false, 'message' => $msg], 422);
            }

            return back()->with('error', $msg);
        }

        // Never echo the password: the customer typed it themselves.
        if ($this->wantsJson($request)) {
            return response()->json(['ok' => true, 'started' => true, 'event_id' => $event->id, 'action' => 'reset_password'], 202);
        }

        return back()->with('success', 'Password reset queued — progress is shown on this page.');
    }

    /**
     * Customer "Start VM" / "Stop VM" / "Restart VM": power verbs only, queued
     * through VmOperationDispatcher (202/started contract, progress via
     * vm-status). Never touches hosting_accounts.status — a customer powering
     * off their VM is not a billing suspension (only the PanelAccount mirror
     * set by the driver changes). Enforced by passing `power_only => true`,
     * which makes the job skip the local status effect (host action only).
     */
    public function vmPower(Request $request, HostingAccount $hostingAccount): JsonResponse|RedirectResponse
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $account = $customer->hostingAccounts()->findOrFail($hostingAccount->id);

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:start,stop,restart'],
            'confirm' => ['nullable', 'string', 'max:255'],
        ]);

        $action = $validated['action'];

        // Customer power actions must never wake a suspended/terminated service.
        if ($account->status !== HostingService::STATUS_ACTIVE) {
            $msg = 'This service is not active.';
            if ($this->wantsJson($request)) {
                return response()->json(['ok' => false, 'message' => $msg], 422);
            }

            return back()->with('error', $msg);
        }

        // Guard: a build already queued/running for this account.
        if ($this->vmBuildDispatcher->isBuildRunning($account)) {
            $msg = 'A VM build is already running for this service.';
            if ($this->wantsJson($request)) {
                return response()->json(['ok' => false, 'message' => $msg], 409);
            }

            return back()->with('error', $msg);
        }

        // Typed confirmation for restart: must type the host name exactly — WHY: destructive power action, never rely on modal alone.
        if ($action === 'restart') {
            $expected = (string) $account->host_name;
            if (trim((string) ($validated['confirm'] ?? '')) !== $expected) {
                $msg = "Type '{$expected}' to confirm restart. Action cancelled — nothing was touched.";
                if ($this->wantsJson($request)) {
                    return response()->json(['ok' => false, 'message' => $msg], 422);
                }

                return back()->with('error', $msg);
            }
        }

        // Resolve the compute module that owns this account (the dispatcher
        // re-validates the link/driver when it opens the event).
        $account->loadMissing(['product.moduleLinks', 'server']);
        $slug = $this->computeSlugFor($account);

        if ($slug === null) {
            $msg = 'Power actions are not available for this service.';
            if ($this->wantsJson($request)) {
                return response()->json(['ok' => false, 'message' => $msg], 422);
            }

            return back()->with('error', $msg);
        }

        // Restart never surprise-starts a stopped VM: refuse before queueing
        // when the live probe shows a non-running machine.
        if ($action === 'restart') {
            $refusal = $this->stoppedRestartRefusal($request, $account, $slug);

            if ($refusal !== null) {
                return $refusal;
            }
        }

        try {
            // Host-only by rule: client power actions must never move
            // hosting_accounts.status, so the job skips its local
            // status effect (admin callers omit the flag and keep the
            // billing semantics).
            $event = $this->vmOperationDispatcher->dispatch($account, $action, ['power_only' => true], $request->user()?->id, $slug);
        } catch (VmOperationConflictException $e) {
            if ($this->wantsJson($request)) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 409);
            }

            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            AppLog::provisioning()->error('Client queued VM power action failed', [
                'hosting_account_id' => $account->id,
                'module' => $slug,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
            // Driver messages are already sanitized — pass through verbatim.
            $msg = $e->getMessage();
            if ($this->wantsJson($request)) {
                return response()->json(['ok' => false, 'message' => $msg], 422);
            }

            return back()->with('error', $msg);
        }

        if ($this->wantsJson($request)) {
            return response()->json(['ok' => true, 'started' => true, 'event_id' => $event->id, 'action' => $action], 202);
        }

        return back()->with('success', ucfirst($action).' queued — progress is shown on this page.');
    }

    /**
     * Refuse a restart against a stopped VM before queueing: only a RUNNING
     * VM is rebooted — a stopped VM is refused, never surprise-started. The
     * refusal is recorded as a failed restart event (the same verdict the
     * former inline driver refusal wrote) and answered 422. Null when the
     * restart may queue. A probe failure fails open to the queue — the job
     * records its verdict on its own event.
     */
    private function stoppedRestartRefusal(Request $request, HostingAccount $account, string $slug): JsonResponse|RedirectResponse|null
    {
        try {
            $status = $this->vmStatusPresenter->build($account);
        } catch (\Throwable) {
            return null;
        }

        $vm = is_array($status['vm'] ?? null) ? $status['vm'] : null;

        if ($vm === null || ($vm['exists'] ?? null) !== true) {
            return null;
        }

        $state = (string) ($vm['state'] ?? '');

        if (strtolower(trim($state)) === 'running') {
            return null;
        }

        $name = trim((string) ($vm['name'] ?? ''));
        $shownState = $state !== '' ? $state : 'not running';
        $msg = $name !== ''
            ? "VM '{$name}' is {$shownState}, not Running — start it instead of restarting."
            : "This VM is {$shownState}, not Running — start it instead of restarting.";

        $serviceId = null;
        try {
            $serviceId = app(ManualProvisioner::class)->serviceForHosting($account, $slug)->id;
        } catch (\Throwable) {
            $serviceId = null;
        }

        try {
            $event = $this->provisioningEvents->begin('restart', [
                'module' => $slug,
                'action' => 'restart',
                'hosting_account_id' => $account->id,
                'order_id' => $account->order_id,
            ], $serviceId, $account->id);
            $this->provisioningEvents->fail($event, $msg);
        } catch (\Throwable $e) {
            AppLog::provisioning()->error('client vmPower restart refusal event failed', ['error' => $e->getMessage()]);
        }

        if ($this->wantsJson($request)) {
            return response()->json(['ok' => false, 'message' => $msg], 422);
        }

        return back()->with('error', $msg);
    }

    /**
     * Single home for the AJAX test so JSON and XHR posts behave alike.
     */
    private function wantsJson(Request $request): bool
    {
        return $request->expectsJson() || $request->ajax();
    }

    /**
     * The compute module that owns this account, or null when the account is
     * not a VM service.
     *
     * RULE: a compute account always has a host. An account with no server
     * is never a VM service — whatever the product's provisioning module or
     * an enabled module link says — so a shared-hosting service never
     * renders the VM surface. With a server, the server's own type wins (a
     * machine lives on the server it was built on), then the product's
     * provisioning module, then any enabled module link, then the
     * dispatcher: a server that is not itself a compute host can still carry
     * a compute module through the product's link. Single home used by
     * show(), provision(), vmPower() and resetVmPassword().
     */
    private function computeSlugFor(HostingAccount $account): ?string
    {
        $server = $account->server;

        if ($server === null) {
            return null;
        }

        $serverType = strtolower(trim((string) ($server->server_type ?? '')));
        if (ComputeTemplateCatalog::supports($serverType)) {
            return $serverType;
        }

        return $this->computeModuleFor($account);
    }

    /**
     * The compute module the product is configured for, ignoring the host.
     *
     * The product's provisioning module wins, then any enabled module link,
     * then the dispatcher. Used to tell a non-compute service apart from a
     * compute service whose host has not been assigned yet.
     */
    private function computeModuleFor(HostingAccount $account): ?string
    {
        $product = $account->product;

        if ($product === null) {
            return null;
        }

        $raw = strtolower(trim((string) ($product->provisioning_module ?? '')));
        if (ComputeTemplateCatalog::supports($raw)) {
            return $raw;
        }

        try {
            $links = $product->relationLoaded('moduleLinks') ? $product->moduleLinks : $product->moduleLinks()->get();
            foreach ($links as $link) {
                $slug = strtolower(trim((string) ($link->module_slug ?? '')));
                if ((bool) $link->enabled && ComputeTemplateCatalog::supports($slug)) {
                    return $slug;
                }
            }
        } catch (\Throwable) {
        }

        try {
            $dispatcher = app(ProvisioningDispatcher::class);
            $resolved = strtolower(trim((string) ($dispatcher->moduleFor($product) ?? '')));
            if (ComputeTemplateCatalog::supports($resolved)) {
                return $resolved;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * Build the provisioning card data for the view.
     *
     * The `reason` says WHY the card has nothing to offer, so the page can
     * explain itself instead of showing a dead form: 'ok' (a template is
     * selectable), 'not-compute' (the service is not a VM service),
     * 'no-server' (the service is a VM service but no host has been assigned
     * to it yet), 'no-template' (the host curates no template at all),
     * 'no-effective' (the host curates templates but the product allows none
     * of them).
     *
     * @return array{isCompute: bool, isHyperv: bool, slug: ?string, templateKey: string, templateLabel: string, templates: list<array{id: string, label: string, node?: string}>, default: ?string, curatedCount: int, reason: string}
     */
    private function provisionCardFor(HostingAccount $account): array
    {
        $slug = $this->computeSlugFor($account);
        $templates = [];
        $default = null;
        $curatedCount = 0;
        $reason = 'not-compute';

        if ($slug === null) {
            // No compute module resolved. A service whose product is a VM plan
            // but whose host has not been assigned yet is a different case
            // from a service that is not a VM service at all.
            $reason = $account->server === null && $this->computeModuleFor($account) !== null
                ? 'no-server'
                : 'not-compute';
        } else {
            $server = $account->server;
            $curatedCount = count(ComputeTemplateCatalog::curatedIds($server, $slug));

            $productAllowed = [];
            try {
                $link = $account->product?->moduleLinks?->firstWhere('module_slug', $slug)
                    ?? ($account->product ? $account->product->moduleLinks()->where('module_slug', $slug)->first() : null);
                if ($link) {
                    $rawCfg = is_array($link->config) ? $link->config : [];
                    $dec = app(IntegrationRegistry::class)->decryptConfigFor($slug, $rawCfg);
                    $rawAllowed = $dec['allowed_templates'] ?? [];
                    $productAllowed = ComputeTemplateCatalog::sanitizeAllowed($slug, is_array($rawAllowed) ? $rawAllowed : []);
                }
            } catch (\Throwable) {
                $productAllowed = [];
            }

            $templates = ComputeTemplateCatalog::options($server, $slug, $productAllowed);
            $optionIds = array_column($templates, 'id');
            $def = ComputeTemplateCatalog::default($server, $slug);

            if ($def !== null && $def !== '' && in_array($def, $optionIds, true)) {
                $default = $def;
            } elseif ($optionIds !== []) {
                $default = $optionIds[0];
            }

            if ($templates === [] && $curatedCount > 0) {
                $default = null;
            }

            $reason = match (true) {
                $templates !== [] => 'ok',
                $curatedCount > 0 => 'no-effective',
                default => 'no-template',
            };
        }

        return [
            'isCompute' => $slug !== null,
            'isHyperv' => $slug === 'hyperv',
            'slug' => $slug,
            'templateKey' => $slug !== null ? (ComputeTemplateCatalog::templateKey($slug) ?? 'template') : 'template',
            'templateLabel' => $slug !== null ? ComputeTemplateCatalog::templateLabel($slug) : 'Template',
            'templates' => $templates,
            'default' => $default,
            'curatedCount' => $curatedCount,
            'reason' => $reason,
        ];
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
            ?->first(fn ($item) => $item->product_addon_id === null && (int) $item->product_id === (int) $account->product_id && ! empty($item->config_options))
            ?? $account->order?->items?->first(fn ($item) => $item->product_addon_id === null && ! empty($item->config_options));

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
            ?->first(fn ($i) => $i->product_addon_id === null && (int) $i->product_id === (int) $account->product_id)
            ?? $account->order?->items?->first(fn ($i) => $i->product_addon_id === null);

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

    /**
     * The order line that carries this service — never an add-on line, so the
     * service's own amount and configuration are never read off an add-on row.
     */
    private function serviceItemFor(HostingAccount $account): ?OrderItem
    {
        $items = $account->order?->items;

        if ($items === null) {
            return null;
        }

        return $this->addons->serviceLineFor($items, $account->product_id);
    }

    // The newest invoice on the order is NOT the service invoice: an add-on
    // purchase raises its own invoice on the same order, so the service's
    // document is the newest invoice that actually bills the service line.
    private function serviceInvoiceFor(\App\Models\Customer $customer, HostingAccount $account): ?Invoice
    {
        $serviceName = trim((string) ($this->serviceItemFor($account)?->product_name ?? ''));

        if ($account->order === null || $serviceName === '') {
            return null;
        }

        $pattern = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $serviceName).'%';

        return Invoice::query()
            ->where('order_id', $account->order->id)
            ->where('customer_id', $customer->id)
            ->whereHas('items', fn ($items) => $items->where('description', 'like', $pattern))
            // A live document beats a dead one: a void or refunded invoice
            // is not what the customer is billed against.
            ->orderByRaw("case when status in ('void', 'cancelled', 'refunded', 'draft') then 1 else 0 end")
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The add-ons this service can still take, priced the way the purchase
     * form will charge them: the service's own cycle wins when the add-on's
     * pricing matrix prices it.
     *
     * Only add-ons the server will actually accept are offered: the order
     * must be one an add-on can be purchased on, the add-on must belong to
     * the product the service line carries, and an add-on the service already
     * has is left out — the purchase refuses to attach it twice.
     *
     * The reason says WHY a service has no options, so the page can explain
     * itself instead of showing an empty form: 'inactive' (no order, or an
     * order that is neither active nor suspended), 'no-catalogue' (the
     * product has no active applicable add-ons), 'owned' (every applicable
     * add-on is already active on this service), null when there are options.
     *
     * @return array{options: Collection<int, array{addon: ProductAddon, cycle: string, unit_price: float, setup_fee: float}>, reason: ?string}
     */
    private function applicableAddonsFor(HostingAccount $account): array
    {
        $order = $account->order;
        $serviceItem = $this->serviceItemFor($account);

        if ($order === null || $serviceItem === null) {
            return ['options' => collect(), 'reason' => 'inactive'];
        }

        if (! in_array($order->status, [Order::STATUS_ACTIVE, Order::STATUS_SUSPENDED], true)) {
            return ['options' => collect(), 'reason' => 'inactive'];
        }

        $product = $this->productForServiceLine($account, $serviceItem);

        if ($product === null) {
            return ['options' => collect(), 'reason' => 'no-catalogue'];
        }

        $cycle = (string) ($serviceItem->billing_cycle ?? $order->billing_cycle ?? 'monthly');
        $catalogue = $this->addonCatalogueFor($account, $product, $cycle);

        if ($catalogue->isEmpty()) {
            return ['options' => collect(), 'reason' => 'no-catalogue'];
        }

        $owned = $this->ownedAddonIdsFor($account, $serviceItem);

        $options = $catalogue
            ->reject(fn (array $option) => in_array((int) $option['addon']->id, $owned, true))
            ->values();

        if ($options->isEmpty()) {
            return ['options' => collect(), 'reason' => 'owned'];
        }

        return ['options' => $options, 'reason' => null];
    }

    /**
     * The priced add-on catalogue of the product a service line carries,
     * memoised per product + cycle so many services of one plan cost one
     * lookup.
     *
     * The cache key stays the account's product + cycle; the product the
     * catalogue was built for is kept alongside it, so an upgraded service
     * (whose order line moved to another product) never inherits the add-ons
     * of another plan.
     *
     * @return Collection<int, array{addon: ProductAddon, cycle: string, unit_price: float, setup_fee: float}>
     */
    private function addonCatalogueFor(HostingAccount $account, Product $product, string $cycle): Collection
    {
        $cacheKey = $account->product_id.':'.$cycle;
        $cached = $this->addonCatalogueCache[$cacheKey] ?? null;

        if ($cached === null || $cached['product_id'] !== (int) $product->id) {
            $cached = [
                'product_id' => (int) $product->id,
                'options' => $this->addons->applicableFor($product)
                    ->map(function (ProductAddon $addon) use ($cycle): array {
                        $resolved = $this->addons->resolveCycleAndPrice($addon, $cycle);

                        return [
                            'addon' => $addon,
                            'cycle' => $resolved['cycle'],
                            'unit_price' => $resolved['unit_price'],
                            'setup_fee' => $resolved['setup_fee'],
                        ];
                    })
                    ->values(),
            ];

            $this->addonCatalogueCache[$cacheKey] = $cached;
        }

        return $cached['options'];
    }

    /**
     * The page-level notice for the case where no service can take an add-on:
     * why the customer cannot buy one right now.
     *
     * @param  array<int, string|null>  $reasons
     */
    private function purchaseNoticeFor(array $reasons): string
    {
        if ($reasons === []) {
            return 'You do not have any services to add add-ons to.';
        }

        $parts = [];

        if (in_array('inactive', $reasons, true)) {
            $parts[] = 'Add-ons can be added to active services only.';
        }

        if (in_array('no-catalogue', $reasons, true)) {
            $parts[] = 'No add-ons are available for your plan yet.';
        }

        if (in_array('owned', $reasons, true)) {
            $parts[] = 'Your services already have every add-on available to them.';
        }

        return implode(' ', $parts);
    }

    /**
     * The product the service line carries. The account's own product is the
     * same product unless the service was upgraded — an upgrade moves the
     * order line to the new product, and that line is what an add-on is
     * validated against.
     */
    private function productForServiceLine(HostingAccount $account, OrderItem $serviceItem): ?Product
    {
        if ($account->product !== null && (int) $account->product->id === (int) $serviceItem->product_id) {
            return $account->product;
        }

        return Product::find($serviceItem->product_id);
    }

    /**
     * The add-ons this service already has running: the purchase refuses to
     * attach the same add-on twice.
     *
     * @return array<int, int>
     */
    private function ownedAddonIdsFor(HostingAccount $account, OrderItem $serviceItem): array
    {
        return $account->order?->items
            ?->filter(fn (OrderItem $line) => (int) $line->parent_item_id === (int) $serviceItem->id
                && $line->product_addon_id !== null
                && $line->next_billing_date !== null)
            ->map(fn (OrderItem $line) => (int) $line->product_addon_id)
            ->all() ?? [];
    }
}
