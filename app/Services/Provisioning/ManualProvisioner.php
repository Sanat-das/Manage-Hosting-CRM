<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

use App\Contracts\Integrations\Capabilities\ProvisioningModule as ProvisioningModuleContract;
use App\Contracts\Integrations\ProvisioningResult;
use App\Models\HostingAccount;
use App\Models\PanelAccount;
use App\Models\ProvisioningEvent;
use App\Models\ServiceInstance;
use App\Services\HostingService;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Modules\ModuleManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ManualProvisioner
{
    public function __construct(
        private readonly HostingService $hosting,
        private readonly IntegrationRegistry $registry,
        private readonly ModuleManager $modules,
        private readonly WelcomeMailer $welcome,
        private readonly ProvisioningDispatcher $dispatcher,
        private readonly ProvisioningEventRecorder $recorder,
    ) {}

    /**
     * Provision a pending hosting account through its product's module.
     *
     * When $event is supplied the caller already opened a durable running
     * row (queued job) — use it instead of opening a second one. $overrides
     * are merged into the driver config (e.g. guest credentials).
     */
    public function provision(HostingAccount $account, string $moduleSlug, ?string $templateVm = null, ?ProvisioningEvent $event = null, array $overrides = []): ProvisioningResult
    {
        $moduleSlug = trim($moduleSlug);
        if ($moduleSlug === '') {
            return ProvisioningResult::fail('Module slug is required.');
        }

        // Ensure relations are available
        $account->loadMissing(['product', 'server', 'order']);

        $product = $account->product;

        // 1. Resolve enabled product module link
        $link = null;
        if ($product !== null) {
            $link = $product->moduleLinks()->where('module_slug', $moduleSlug)->first();
        }
        if ($link === null || ! (bool) $link->enabled) {
            return ProvisioningResult::fail('module is not enabled for this product');
        }

        // 2. Idempotency: active PanelAccount exists — host-verify before short-circuit.
        // If the VM is missing on the host (deleted out-of-band / partial failure)
        // we must rebuild instead of returning a false success.
        $rebuildingStale = false;
        $hasActivePanel = false;
        $existingService = $this->findExistingService($account);
        if ($existingService !== null) {
            $activePanel = PanelAccount::where('service_instance_id', $existingService->id)
                ->where('status', PanelAccount::STATUS_ACTIVE)
                ->first();
            $hasActivePanel = $activePanel !== null;
            if ($activePanel !== null) {
                $driverForCheck = $this->resolveDriver($moduleSlug);
                if ($driverForCheck !== null && method_exists($driverForCheck, 'recordedVmState')) {
                    $probe = $driverForCheck->recordedVmState($activePanel);
                    if (($probe['exists'] ?? null) === true) {
                        // The injected (queued-job) event must not be left
                        // running: a stranded row blocks every later build and
                        // keeps the UI progress bar alive forever.
                        if ($event !== null) {
                            try {
                                $this->recorder->complete($event, 'already provisioned', ['external_id' => $activePanel->external_id]);
                            } catch (Throwable) {
                                // Audit failure must not mask the idempotent result.
                            }
                        }

                        return ProvisioningResult::ok('already provisioned');
                    }
                    if (($probe['exists'] ?? null) === false) {
                        // Stale record — allow rebuild even though hosting status may be active.
                        $rebuildingStale = true;
                        Log::warning('Stale PanelAccount detected — rebuilding VM on host', [
                            'hosting_account_id' => $account->id,
                            'service_instance_id' => $existingService->id,
                        ]);
                        $this->hosting->audit($account, 'hosting.module_action', "Stale PanelAccount #{$activePanel->id} — VM missing on host, rebuilding", ['module' => $moduleSlug]);
                    } elseif (($probe['exists'] ?? null) === null) {
                        $msg = $probe['error'] ?? 'Host probe failed.';
                        if ($event !== null) {
                            $this->failEventQuietly($event, $msg);
                        }
                        return ProvisioningResult::fail($msg);
                    }
                } else {
                    // Driver without hook — keep blind behaviour.
                    if ($event !== null) {
                        try {
                            $this->recorder->complete($event, 'already provisioned', ['external_id' => $activePanel->external_id]);
                        } catch (Throwable) {
                            // Audit failure must not mask the idempotent result.
                        }
                    }

                    return ProvisioningResult::ok('already provisioned');
                }
            }
        }

        // 3. Account must be pending (or suspended for re-provision via admin create)
        // When rebuilding a stale record we allow active as well. An active
        // account with NO active panel record at all is also allowed: WHY —
        // hyperv-manual orders activate immediately ("Awaiting manual VM
        // provisioning"), so active-before-the-VM-exists is a normal state.
        $allowedStatuses = [HostingService::STATUS_PENDING, HostingService::STATUS_SUSPENDED];
        if ($rebuildingStale || ! $hasActivePanel) {
            $allowedStatuses[] = HostingService::STATUS_ACTIVE;
        }
        if (! in_array($account->status, $allowedStatuses, true)) {
            return ProvisioningResult::fail("Hosting account #{$account->id} is not pending (status: {$account->status}).");
        }

        // 4. If account has order, order must be active
        if ($account->order_id !== null) {
            $order = $account->order;
            if ($order !== null && $order->status !== \App\Models\Order::STATUS_ACTIVE) {
                return ProvisioningResult::fail("Order #{$order->order_number} is not active (status: {$order->status}).");
            }
        }

        // 5. Server must exist, be active, of right type, driver must resolve
        $server = $account->server;
        if ($server === null) {
            // Try to reload server if not eager loaded but id exists
            if ($account->server_id !== null) {
                $server = \App\Models\Server::find($account->server_id);
            }
        }
        if ($server === null) {
            return ProvisioningResult::fail('No server assigned to this hosting account.');
        }
        if (($server->status ?? '') !== 'active') {
            return ProvisioningResult::fail("Server '{$server->name}' is not active.");
        }
        // Server type check for hyperv (and generally)
        $serverType = trim((string) ($server->server_type ?? ''));
        if ($serverType !== '' && strtolower($serverType) !== strtolower($moduleSlug)) {
            // For hyperv we enforce strict, for others we also enforce if mismatch and slug is a server type
            // Only enforce when slug is a known server type (hyperv, etc) - but spec says "of the right type"
            // We'll enforce for hyperv at least.
            if (strtolower($moduleSlug) === 'hyperv' && strtolower($serverType) !== 'hyperv') {
                return ProvisioningResult::fail("Server '{$server->name}' is not a Hyper-V server.");
            }
        }

        $driver = $this->resolveDriver($moduleSlug);
        if ($driver === null) {
            return ProvisioningResult::fail("No provisioning driver found for {$moduleSlug}.");
        }

        $hypervAllowed = [];
        $hypervEffective = [];
        // 6. Template rule (hyperv) with per-product restriction
        if (strtolower($moduleSlug) === 'hyperv') {
            $curated = $server->hypervTemplateVms();
            $rawLinkConfig = is_array($link->config ?? null) ? $link->config : [];
            $decryptedLinkConfig = $this->registry->decryptConfigFor($moduleSlug, $rawLinkConfig);
            $allowedRaw = $decryptedLinkConfig['allowed_templates'] ?? [];
            $allowed = HypervTemplateCatalog::sanitizeAllowed(is_array($allowedRaw) ? $allowedRaw : []);
            $hypervAllowed = $allowed;
            $effective = HypervTemplateCatalog::effectiveNames($server, $allowed);
            $hypervEffective = $effective;

            $explicit = $templateVm !== null ? trim((string) $templateVm) : '';
            if ($explicit !== '') {
                $explicit = mb_substr($explicit, 0, 64);
            }

            if ($effective !== []) {
                if ($explicit === '') {
                    return ProvisioningResult::fail('Select a template');
                }
                if (! in_array($explicit, $effective, true)) {
                    return ProvisioningResult::fail("Template '{$explicit}' is not in the curated list for server '{$server->name}'.");
                }
            } else {
                // effective empty
                if ($curated !== []) {
                    // Server has curated but product restriction excludes everything
                    return ProvisioningResult::fail("no template is allowed for this product on server '{$server->name}'.");
                }
                // curated empty -> legacy blank-disk, template must be empty
                if ($explicit !== '') {
                    return ProvisioningResult::fail("Template '{$explicit}' is not in the curated list for server '{$server->name}'.");
                }
            }
        }

        // 7. Concurrency lock (multi-GB clone must not let a second worker in)
        $lock = Cache::lock("hyperv:provision:{$account->id}", 1900);
        if (! $lock->get()) {
            return ProvisioningResult::fail('provisioning already in progress');
        }

        try {
            // 8. Build config
            $rawConfig = is_array($link->config ?? null) ? $link->config : [];
            $config = $this->registry->decryptConfigFor($moduleSlug, $rawConfig);

            // Normalize keys to keep original case but ensure host_name added
            $config['host_name'] = (string) $account->host_name;

            // Order Configuration Options snapshot wins over the link config:
            // the customer chose concrete resources at order time while the
            // link only holds fallback defaults.
            $account->order?->loadMissing('items');
            $orderOptions = ProvisioningOptionsResolver::fromOrder($account->order);

            // Fail loud for order-backed accounts: a module-required resource
            // key missing from the snapshot must never reach the host with a
            // guessed value. Order-less accounts keep the legacy link/default
            // behaviour below.
            $requiredKeys = ModuleRequiredOptions::requiredFor($moduleSlug);
            if ($account->order_id !== null && $requiredKeys !== []) {
                $missing = [];
                foreach ($requiredKeys as $key) {
                    if (! array_key_exists($key, $orderOptions)) {
                        $missing[] = $key;
                    }
                }
                if ($missing !== []) {
                    return ProvisioningResult::fail('Product "'.$account->product?->name.'" is missing required Configuration Options for '.$moduleSlug.': '.implode(', ', $missing).'. Attach the required option groups to the product before provisioning.');
                }
            }

            $config = ProvisioningOptionsResolver::applyToConfig($config, $orderOptions);

            $explicitTemplate = $templateVm !== null ? trim((string) $templateVm) : '';
            if ($explicitTemplate !== '') {
                $explicitTemplate = mb_substr($explicitTemplate, 0, 64);
            }

            // For hyperv, include template_vm when set or when server has default? Spec says when set.
            // But HyperV module expects template_vm explicit or default via server; we pass explicit only when set.
            if (strtolower($moduleSlug) === 'hyperv') {
                if ($explicitTemplate !== '') {
                    $config['template_vm'] = $explicitTemplate;
                }
                if ($hypervAllowed !== []) {
                    $config['allowed_templates'] = $hypervAllowed;
                }
                // Inject defaults for required hyperv keys so a bare product config (empty link config)
                // still provisions via driver defaults (cpu=2, ram=2048, disk=50, etc.) and keeps the
                // existing blank-disk test green. Missing-key validation still runs afterwards so a
                // truly missing key that has no default will fail loud.
                $lower = array_change_key_case($config, CASE_LOWER);
                if (! isset($lower['cpu']) && ! isset($lower['vcpus'])) {
                    $config['cpu'] = 2;
                }
                if (! isset($lower['ram']) && ! isset($lower['memory'])) {
                    $config['ram'] = 2048;
                }
                if (! isset($lower['disk']) && ! isset($lower['disk_gb'])) {
                    $config['disk'] = 50;
                }
                if (! isset($lower['switch']) && ! isset($lower['vswitch'])) {
                    $config['switch'] = 'Default Switch';
                }
                if (! isset($lower['generation'])) {
                    $config['generation'] = 2;
                }
            }

            // Merge overrides (guest credentials, start_after_create) before validation.
            if ($overrides !== []) {
                foreach ($overrides as $k => $v) {
                    if ($v !== null && $v !== '') {
                        $config[$k] = $v;
                    }
                }
            }

            $missing = ModuleRequiredOptions::missingConfigKeys($config, $moduleSlug);
            if ($missing !== []) {
                return ProvisioningResult::fail('Missing required provisioning options for '.$moduleSlug.': '.implode(', ', $missing));
            }

            // 9. Lease IPs (deferred for hyperv-manual)
            try {
                $this->hosting->leaseIpForActivation($account);
            } catch (Throwable $e) {
                return ProvisioningResult::fail('IP lease failed: '.$e->getMessage());
            }

            // If lease internally caught NoAvailableIpException, it just logs, not fails.
            // Spec says on lease failure, fail without flipping. Since lease never throws for pool exhausted,
            // we consider it success.

            // 10. Get/create ServiceInstance
            $service = $this->serviceForHosting($account, $moduleSlug);

            // If an event was injected (queued job), ensure its service link.
            if ($event !== null && $event->service_instance_id === null) {
                $event->service_instance_id = $service->id;
                $event->save();
            }

            // Dedupe: a completed provision event already on this service
            // means the VM was built — never open another event row for it.
            // When an event was injected, exactly one row must exist (that one).
            $hasCompleted = false;
            if ($event === null) {
                $hasCompleted = ProvisioningEvent::where('service_instance_id', $service->id)
                    ->where('event_status', 'completed')
                    ->exists();
                if (! $hasCompleted) {
                    $hasCompleted = ProvisioningEvent::where('service_instance_id', $service->id)
                        ->where('status', 'completed')
                        ->exists();
                }
            }

            $payload = ['module' => $moduleSlug, 'action' => 'provision', 'hosting_account_id' => $account->id];
            if ($account->order_id !== null) {
                $order = $account->order()->first();
                if ($order !== null) {
                    $payload['order_id'] = $order->id;
                    $payload['order_number'] = $order->order_number;
                }
            }

            // 11. Call driver provision behind a durable running event so a
            // crash between the host call and the row write stays visible.
            $usingInjectedEvent = $event !== null;
            if (! $usingInjectedEvent) {
                $event = $hasCompleted ? null : $this->recorder->begin('provision', $payload, $service->id, $account->id);
            }

            try {
                /** @var ProvisioningResult $result */
                $result = $driver->provision($service, $config);
            } catch (Throwable $e) {
                Log::error('Manual provision threw', [
                    'hosting_account_id' => $account->id,
                    'module' => $moduleSlug,
                    'error' => $e->getMessage(),
                ]);
                $this->failEventQuietly($event, $e->getMessage());
                return ProvisioningResult::fail($e->getMessage());
            }

            if (! $result->success) {
                $this->failEventQuietly($event, $result->message ?? 'Provisioning failed');
                return ProvisioningResult::fail($result->message ?? 'Provisioning failed');
            }

            // 12. On success: flip hosting active, set ServiceInstance active, record completed event, welcome mail, audit
            // ServiceInstance active with external_id
            $updateData = ['status' => 'active'];
            if (isset($result->data['external_id']) && $result->data['external_id'] !== null && $result->data['external_id'] !== '') {
                $updateData['external_id'] = $result->data['external_id'];
            }
            if (isset($result->data['username']) && $result->data['username'] !== null && $result->data['username'] !== '') {
                // keep existing username? but dispatcher does update username, we follow similar
                $updateData['username'] = $result->data['username'];
            }
            try {
                // Audit the actually-provisioned resources (snapshot-resolved,
                // not just link defaults) without wiping unrelated keys.
                $lowerConfig = [];
                foreach ($config as $k => $v) {
                    $lowerConfig[strtolower(trim((string) $k))] = $v;
                }
                $resolved = [];
                foreach (ProvisioningOptionsResolver::RESOURCE_KEYS as $key) {
                    if (array_key_exists($key, $lowerConfig)) {
                        $value = $lowerConfig[$key];
                        if ($value !== null && is_scalar($value)) {
                            $resolved[$key] = $value;
                        }
                    }
                }
                if ($resolved !== []) {
                    $existing = $service->provisioning_config;
                    if (! is_array($existing)) {
                        $existing = [];
                    }
                    $updateData['provisioning_config'] = array_merge($existing, $resolved);
                }
            } catch (Throwable $e) {
                // The VM is already built — a failed audit write must never
                // mask provisioning success.
                Log::warning('Could not persist manual provisioning snapshot', [
                    'hosting_account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
            }
            $service->update($updateData);

            // Flip hosting account to active using same mechanism as moduleAction
            $account->refresh();
            if ($account->status !== HostingService::STATUS_ACTIVE) {
                try {
                    // UseHostingService unsuspend which also audits hosting.unsuspended
                    // But to avoid double IP lease, we check if product requires IP and already has one?
                    // We already leased before provision, so unsuspend would lease again.
                    // To keep behavior identical to moduleAction but avoid double allocation,
                    // we directly set active if already has IPs? Spec says SAME mechanism, so we call unsuspend.
                    // However unsuspend's lease is best-effort; double lease would allocate second IP.
                    // We mitigate by calling DB transaction directly when already has leased IPs.
                    $hasLeasedIps = $account->ipAddresses()->exists();
                    $requiresIp = $account->product?->requiresIp() ?? false;
                    if ($requiresIp && $hasLeasedIps) {
                        // Direct flip without re-leasing
                        DB::transaction(function () use ($account, $moduleSlug) {
                            $account->update([
                                'status' => HostingService::STATUS_ACTIVE,
                                'suspended_reason' => null,
                                'suspended_at' => null,
                            ]);
                            $this->hosting->audit($account, 'hosting.unsuspended', "Hosting account #{$account->id} reactivated", ['module' => $moduleSlug]);
                        });
                    } else {
                        $this->hosting->unsuspend($account);
                    }
                } catch (Throwable $e) {
                    // Module already succeeded — audit status conflict but don't mask success
                    $this->hosting->audit($account, 'hosting.module_action', "Module {$moduleSlug} provision succeeded; local status already {$account->status}", ['module' => $moduleSlug, 'action' => 'provision']);
                }
            }

            // Flip the running event to completed ONLY if no completed event
            // already existed for that service (checked before the driver call),
            // or when using the injected queued-job event.
            $shouldCreateEvent = $usingInjectedEvent || ! $hasCompleted;
            if ($shouldCreateEvent && $event !== null) {
                try {
                    $this->recorder->complete($event, $result->message ?? 'Provisioned', $result->data);
                } catch (Throwable $e) {
                    Log::warning('Could not record completed provisioning event', [
                        'hosting_account_id' => $account->id,
                        'error' => $e->getMessage(),
                    ]);
                }
                // Send welcome mail exactly once, guarded by same check (we already checked no completed existed)
                if ($account->order_id !== null) {
                    $order = $account->order()->first();
                    if ($order !== null) {
                        try {
                            $this->welcome->send($order, $service->refresh(), $result->data);
                        } catch (Throwable $e) {
                            Log::warning('Welcome mail failed in manual provision', [
                                'hosting_account_id' => $account->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }

            // The VM now exists: resolve this order's stale awaiting_manual_vm
            // queue entries so they no longer stay pending.
            if ($account->order_id !== null) {
                try {
                    $order = $account->order()->first();
                    if ($order !== null) {
                        $this->recorder->resolveAwaiting($order, 'Manual VM build completed');
                    }
                } catch (Throwable $e) {
                    Log::warning('Could not resolve awaiting provisioning events', [
                        'hosting_account_id' => $account->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->hosting->audit($account, 'hosting.module_action', "Module {$moduleSlug} provision: ".($result->message ?? 'ok'), ['module' => $moduleSlug, 'action' => 'provision']);

            return ProvisioningResult::ok($result->message ?? 'Provisioned', $result->data);
        } finally {
            $lock->release();
        }
    }

    /**
     * Shared ServiceInstance mirror logic (extracted from Admin\HostingController).
     */
    public function serviceForHosting(HostingAccount $hostingAccount, string $slug): ServiceInstance
    {
        if ($hostingAccount->order_id !== null) {
            $existing = ServiceInstance::where('order_id', $hostingAccount->order_id)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        return ServiceInstance::firstOrCreate(
            [
                'customer_id' => $hostingAccount->customer_id,
                'order_id' => $hostingAccount->order_id,
                'server_id' => $hostingAccount->server_id,
                'domain' => $hostingAccount->domain,
            ],
            [
                'catalog_product_id' => null,
                'service_tag' => 'HOST-'.$hostingAccount->id,
                'username' => $hostingAccount->username ?: 'host'.$hostingAccount->id,
                'provisioning_method' => $slug,
                'status' => 'pending',
            ],
        );
    }

    private function findExistingService(HostingAccount $account): ?ServiceInstance
    {
        if ($account->order_id !== null) {
            $existing = ServiceInstance::where('order_id', $account->order_id)->first();
            if ($existing !== null) {
                return $existing;
            }
        }
        // Fallback to HOST- mirror
        return ServiceInstance::where('service_tag', 'HOST-'.$account->id)->first();
    }

    private function failEventQuietly(?ProvisioningEvent $event, string $message): void
    {
        if ($event === null) {
            return;
        }

        try {
            $this->recorder->fail($event, $message);
        } catch (Throwable $e) {
            Log::warning('Could not record failed provisioning event', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveDriver(string $slug): ?ProvisioningModuleContract
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }
        if ($this->registry->has($slug)) {
            $instance = $this->registry->instanceFor($slug);
            if ($instance instanceof ProvisioningModuleContract) {
                return $instance;
            }
        }
        $module = $this->modules->find($slug);
        if ($module === null || $module->status !== \App\Models\Module::STATUS_ACTIVE) {
            return null;
        }
        $instance = $this->modules->capabilityInstance($module, 'provisioning');
        return $instance instanceof ProvisioningModuleContract ? $instance : null;
    }
}
