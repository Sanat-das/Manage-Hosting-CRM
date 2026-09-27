<?php

namespace App\Http\Controllers\Client;

use App\Contracts\Integrations\Capabilities\HostingAccountInfoProvider;
use App\Http\Controllers\Controller;
use App\Models\HostingAccount;
use App\Models\PanelAccount;
use App\Services\HostingService;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Modules\ModuleManager;
use App\Services\OrderConfigSnapshot;
use App\Services\Provisioning\ComputeDriver;
use App\Services\Provisioning\ComputeTemplateCatalog;
use App\Services\Provisioning\HypervDriver;
use App\Services\Provisioning\ManualProvisioner;
use App\Services\Provisioning\ProvisioningDispatcher;
use App\Services\Provisioning\ProvisioningEventRecorder;
use App\Services\Provisioning\VmBuildDispatcher;
use App\Services\Provisioning\VmGuestCredentialStore;
use App\Services\Provisioning\VmStatusPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Client portal — hosting account listing and detail.
 */
class HostingController extends Controller
{
    private const PER_PAGE = 15;

    public function __construct(
        private readonly OrderConfigSnapshot $snapshot,
        private readonly ManualProvisioner $provisioner,
        private readonly VmBuildDispatcher $vmBuildDispatcher,
        private readonly VmStatusPresenter $vmStatusPresenter,
        private readonly ProvisioningEventRecorder $provisioningEvents,
        private readonly HostingService $hostingService,
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

        $provisionCard = $this->provisionCardFor($account);

        // Frozen vm-status contract shared with the admin page: drives the
        // progress panel, the reset-password button state and the polling
        // endpoint below. Built here so the first paint already knows.
        $vmStatus = $this->vmStatusPresenter->build($account);

        return view('client.hosting.show', array_merge([
            'account' => $account,
            'billing' => $this->billingFor($account),
            'modulePanels' => $modulePanels,
            'provisionCard' => $provisionCard,
            'vmStatus' => $vmStatus,
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
            Log::error('Client queued VM create failed', [
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
            Log::warning('client vmStatus failed', ['hosting_account_id' => $hostingAccount->id, 'error' => $e->getMessage()]);

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
     * credentials it already has stored. Mirrors the admin reset flow
     * (update event + audit) without ever echoing the password back.
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

        // Resolve service + driver for reset (same factory the admin uses).
        $service = app(ManualProvisioner::class)->serviceForHosting($account, 'hyperv');
        $driver = HypervDriver::resolve();

        if ($driver === null || ! method_exists($driver, 'resetGuestAdminPassword')) {
            $msg = 'Password reset is not available for this service.';
            if ($this->wantsJson($request)) {
                return response()->json(['ok' => false, 'message' => $msg], 422);
            }

            return back()->with('error', $msg);
        }

        // The customer never knows the current password — without a stored
        // credential there is nothing to authenticate into the guest with.
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
        } catch (\Throwable) {
        }

        // Record the attempt as a provisioning event, exactly like admin.
        try {
            $event = $this->provisioningEvents->begin('update', [
                'module' => 'hyperv',
                'action' => 'reset_password',
                'hosting_account_id' => $account->id,
                'order_id' => $account->order_id,
            ], $service->id, $account->id);
        } catch (\Throwable $e) {
            Log::error('client resetVmPassword event begin failed', ['error' => $e->getMessage()]);
            $event = null;
        }

        try {
            $result = $driver->resetGuestAdminPassword($service, $newPassword);
        } catch (\Throwable $e) {
            if (isset($event) && $event !== null) {
                try {
                    $this->provisioningEvents->fail($event, $e->getMessage());
                } catch (\Throwable) {
                }
            }
            // Driver messages are already sanitized — pass through verbatim.
            $msg = $e->getMessage();
            if ($this->wantsJson($request)) {
                return response()->json(['ok' => false, 'message' => $msg], 422);
            }

            return back()->with('error', $msg);
        }

        if (! $result->success) {
            if (isset($event) && $event !== null) {
                try {
                    $this->provisioningEvents->fail($event, $result->message ?? 'Password reset failed');
                } catch (\Throwable) {
                }
            }
            $msg = $result->message ?? 'Password reset failed';
            if ($this->wantsJson($request)) {
                return response()->json(['ok' => false, 'message' => $msg], 422);
            }

            return back()->with('error', $msg);
        }

        if (isset($event) && $event !== null) {
            try {
                $this->provisioningEvents->complete($event, $result->message ?? 'Administrator password reset', is_array($result->data ?? null) ? $result->data : []);
            } catch (\Throwable) {
            }
        }
        try {
            $this->hostingService->audit($account, 'hosting.module_action', $result->message ?? 'Administrator password reset', ['module' => 'hyperv', 'action' => 'reset_password']);
        } catch (\Throwable) {
        }

        // Never echo the password: the customer typed it themselves.
        if ($this->wantsJson($request)) {
            return response()->json(['ok' => true, 'message' => 'Administrator password reset. Use the new password on your next RDP login.']);
        }

        return back()->with('success', 'Administrator password reset. Use the new password on your next RDP login.');
    }

    /**
     * Customer "Start VM" / "Stop VM": power verbs only, gated by the live VM
     * state. Mirrors resetVmPassword() (customer scoping, driver via
     * HypervDriver::resolve(), event + audit, JSON/redirect) but never
     * touches hosting_accounts.status — a customer powering off their VM is
     * not a billing suspension (only the PanelAccount mirror set by the
     * driver changes).
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

        // Resolve service + driver for power (same factory the admin uses).
        $account->loadMissing(['product.moduleLinks', 'server']);
        $slug = $this->computeSlugFor($account);

        if ($slug === null) {
            $msg = 'Power actions are not available for this service.';
            if ($this->wantsJson($request)) {
                return response()->json(['ok' => false, 'message' => $msg], 422);
            }

            return back()->with('error', $msg);
        }

        $service = app(ManualProvisioner::class)->serviceForHosting($account, $slug);
        $driver = ComputeDriver::resolve($slug);

        if ($action === 'restart') {
            if ($driver === null || ! method_exists($driver, 'restart')) {
                $msg = 'Restart is not available for this service.';
                if ($this->wantsJson($request)) {
                    return response()->json(['ok' => false, 'message' => $msg], 422);
                }

                return back()->with('error', $msg);
            }
        } elseif ($driver === null || ! method_exists($driver, 'suspend') || ! method_exists($driver, 'unsuspend')) {
            $msg = 'Power actions are not available for this service.';
            if ($this->wantsJson($request)) {
                return response()->json(['ok' => false, 'message' => $msg], 422);
            }

            return back()->with('error', $msg);
        }

        // Decrypt the product's link config for this module (empty when no link).
        $config = [];
        try {
            $link = $account->product?->moduleLinks?->firstWhere('module_slug', $slug)
                ?? ($account->product ? $account->product->moduleLinks()->where('module_slug', $slug)->first() : null);
            if ($link) {
                $rawConfig = is_array($link->config ?? null) ? $link->config : [];
                $config = app(IntegrationRegistry::class)->decryptConfigFor($slug, $rawConfig);
            }
        } catch (\Throwable) {
            $config = [];
        }

        $eventType = $action === 'start' ? 'unsuspend' : ($action === 'restart' ? 'restart' : 'suspend');

        // Record the attempt as a provisioning event, exactly like reset.
        try {
            $event = $this->provisioningEvents->begin($eventType, [
                'module' => $slug,
                'action' => $action,
                'hosting_account_id' => $account->id,
                'order_id' => $account->order_id,
            ], $service->id, $account->id);
        } catch (\Throwable $e) {
            Log::error('client vmPower event begin failed', ['error' => $e->getMessage()]);
            $event = null;
        }

        try {
            if ($action === 'restart') {
                $result = $driver->restart($service, $config);
            } elseif ($action === 'start') {
                $result = $driver->unsuspend($service, $config);
            } else {
                $result = $driver->suspend($service, $config);
            }
        } catch (\Throwable $e) {
            if (isset($event) && $event !== null) {
                try {
                    $this->provisioningEvents->fail($event, $e->getMessage());
                } catch (\Throwable) {
                }
            }
            // The host may have changed state even when the call failed.
            $this->vmStatusPresenter->forgetVmState($slug, $account->id);
            // Driver messages are already sanitized — pass through verbatim.
            $msg = $e->getMessage();
            if ($this->wantsJson($request)) {
                return response()->json(['ok' => false, 'message' => $msg], 422);
            }

            return back()->with('error', $msg);
        }

        // Any module call can change the live VM state; drop the presenter's
        // short probe cache so the post-action render cannot replay it.
        $this->vmStatusPresenter->forgetVmState($slug, $account->id);

        if (! $result->success) {
            if (isset($event) && $event !== null) {
                try {
                    $this->provisioningEvents->fail($event, $result->message ?? 'Power action failed');
                } catch (\Throwable) {
                }
            }
            $msg = $result->message ?? 'Power action failed';
            if ($this->wantsJson($request)) {
                return response()->json(['ok' => false, 'message' => $msg], 422);
            }

            return back()->with('error', $msg);
        }

        if (isset($event) && $event !== null) {
            try {
                $fallback = $action === 'start' ? 'VM started.' : ($action === 'restart' ? 'VM restarted.' : 'VM stopped.');
                $this->provisioningEvents->complete($event, $result->message ?? $fallback, is_array($result->data ?? null) ? $result->data : []);
            } catch (\Throwable) {
            }
        }
        try {
            $fallback = $action === 'start' ? 'VM started.' : ($action === 'restart' ? 'VM restarted.' : 'VM stopped.');
            $this->hostingService->audit($account, 'hosting.module_action', $result->message ?? $fallback, ['module' => $slug, 'action' => $action]);
        } catch (\Throwable) {
        }

        $state = $action === 'start' || $action === 'restart' ? 'Running' : 'Off';
        $fallback = $action === 'start' ? 'VM started.' : ($action === 'restart' ? 'VM restarted.' : 'VM stopped.');
        $message = $result->message ?? $fallback;
        if ($this->wantsJson($request)) {
            return response()->json(['ok' => true, 'action' => $action, 'message' => $message, 'state' => $state]);
        }

        return back()->with('success', $message);
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
     * not a VM service. The server's own type wins (a machine lives on the
     * server it was built on), then the product's provisioning module, then
     * any enabled module link. Single home used by show() and provision().
     */
    private function computeSlugFor(HostingAccount $account): ?string
    {
        $serverType = strtolower(trim((string) ($account->server?->server_type ?? '')));
        if (ComputeTemplateCatalog::supports($serverType)) {
            return $serverType;
        }

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
     * @return array{isCompute: bool, isHyperv: bool, slug: ?string, templateKey: string, templateLabel: string, templates: list<array{id: string, label: string, node?: string}>, default: ?string, curatedCount: int, noEffective: bool}
     */
    private function provisionCardFor(HostingAccount $account): array
    {
        $slug = $this->computeSlugFor($account);
        $templates = [];
        $default = null;
        $curatedCount = 0;
        $noEffective = false;

        if ($slug !== null && $account->server !== null) {
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
                $noEffective = true;
                $default = null;
            }
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
            'noEffective' => $noEffective,
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
