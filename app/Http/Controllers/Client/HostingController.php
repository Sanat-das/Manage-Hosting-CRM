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
use App\Services\Provisioning\HypervDriver;
use App\Services\Provisioning\HypervVmBuildDispatcher;
use App\Services\Provisioning\ManualProvisioner;
use App\Services\Provisioning\ProvisioningEventRecorder;
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
        private readonly HypervVmBuildDispatcher $vmBuildDispatcher,
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
     *
     * @return JsonResponse|RedirectResponse
     */
    public function provision(Request $request, HostingAccount $hostingAccount): JsonResponse|RedirectResponse
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $account = $customer->hostingAccounts()->findOrFail($hostingAccount->id);

        $validated = $request->validate([
            'template_vm' => ['nullable', 'string', 'max:64'],
        ]);

        $templateVm = isset($validated['template_vm']) ? trim((string) $validated['template_vm']) : null;
        if ($templateVm === '') {
            $templateVm = null;
        }

        $account->loadMissing(['product.moduleLinks', 'server']);

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
            $event = $this->vmBuildDispatcher->dispatch($account, $templateVm, true, null, null);

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
            Log::error('Client Hyper-V queued create failed', [
                'hosting_account_id' => $account->id,
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
     *
     * @return JsonResponse|RedirectResponse
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
     *
     * @return JsonResponse|RedirectResponse
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
        $service = app(ManualProvisioner::class)->serviceForHosting($account, 'hyperv');
        $driver = HypervDriver::resolve();

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

        // Decrypt the product's hyperv link config (empty when no link).
        $config = [];
        try {
            $link = $account->product?->moduleLinks?->firstWhere('module_slug', 'hyperv')
                ?? ($account->product ? $account->product->moduleLinks()->where('module_slug', 'hyperv')->first() : null);
            if ($link) {
                $rawConfig = is_array($link->config ?? null) ? $link->config : [];
                $config = app(IntegrationRegistry::class)->decryptConfigFor('hyperv', $rawConfig);
            }
        } catch (\Throwable) {
            $config = [];
        }

        $eventType = $action === 'start' ? 'unsuspend' : ($action === 'restart' ? 'restart' : 'suspend');

        // Record the attempt as a provisioning event, exactly like reset.
        try {
            $event = $this->provisioningEvents->begin($eventType, [
                'module' => 'hyperv',
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
            $this->hostingService->audit($account, 'hosting.module_action', $result->message ?? $fallback, ['module' => 'hyperv', 'action' => $action]);
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
     * Resolve the hyperv slug for a product, or null when not hyperv.
     * Single home used by show() and provision() so detection logic exists once.
     */
    private function hypervSlugForProduct(?\App\Models\Product $product): ?string
    {
        if ($product === null) {
            return null;
        }

        $raw = trim((string) ($product->provisioning_module ?? ''));
        if ($raw === 'hyperv') {
            return 'hyperv';
        }

        try {
            $links = $product->relationLoaded('moduleLinks') ? $product->moduleLinks : $product->moduleLinks()->get();
            foreach ($links as $link) {
                if (trim((string) ($link->module_slug ?? '')) === 'hyperv' && (bool) $link->enabled) {
                    return 'hyperv';
                }
            }
        } catch (\Throwable) {
        }

        try {
            $dispatcher = app(\App\Services\Provisioning\ProvisioningDispatcher::class);
            if ($dispatcher->moduleFor($product) === 'hyperv') {
                return 'hyperv';
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * Build the provisioning card data for the view.
     *
     * @return array{isHyperv: bool, templates: list<array{name: string, label: string}>, default: ?string}
     */
    private function provisionCardFor(HostingAccount $account): array
    {
        $isHyperv = $this->hypervSlugForProduct($account->product) !== null;
        $templates = [];
        $default = null;
        $curatedCount = 0;
        $noEffective = false;
        if ($isHyperv) {
            $server = $account->server;
            $curatedCount = count($server?->hypervTemplateVms() ?? []);
            $productAllowed = [];
            try {
                $link = $account->product?->moduleLinks?->firstWhere('module_slug', 'hyperv')
                    ?? ($account->product ? $account->product->moduleLinks()->where('module_slug', 'hyperv')->first() : null);
                if ($link) {
                    $rawCfg = is_array($link->config) ? $link->config : [];
                    $dec = app(\App\Services\Integrations\IntegrationRegistry::class)->decryptConfigFor('hyperv', $rawCfg);
                    $rawAllowed = $dec['allowed_templates'] ?? [];
                    $productAllowed = \App\Services\Provisioning\HypervTemplateCatalog::sanitizeAllowed(is_array($rawAllowed) ? $rawAllowed : []);
                }
            } catch (\Throwable) {
                $productAllowed = [];
            }
            if ($server !== null) {
                $templates = \App\Services\Provisioning\HypervTemplateCatalog::effectiveOptions($server, $productAllowed);
                $def = $server->hypervDefaultTemplate();
                if ($def !== null && $def !== '' && in_array($def, array_column($templates, 'name'), true)) {
                    $default = $def;
                } elseif ($def !== null && $def !== '' && $productAllowed !== [] && ! in_array($def, array_column($templates, 'name'), true)) {
                    $default = null;
                } else {
                    $default = $def;
                    if ($default !== null && $default !== '' && ! in_array($default, array_column($templates, 'name'), true)) {
                        $default = $templates[0]['name'] ?? null;
                    }
                }
                if ($templates === [] && $curatedCount > 0) {
                    $noEffective = true;
                    $default = null;
                }
            } else {
                $templates = [];
                $default = null;
            }
        }

        return ['isHyperv' => $isHyperv, 'templates' => $templates, 'default' => $default, 'curatedCount' => $curatedCount, 'noEffective' => $noEffective];
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
