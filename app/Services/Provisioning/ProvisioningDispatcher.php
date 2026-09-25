<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

use App\Contracts\Integrations\Capabilities\ProvisioningModule as ProvisioningModuleContract;
use App\Contracts\Integrations\ProvisioningResult;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProvisioningEvent;
use App\Models\ServiceInstance;
use App\Services\HostingService;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Modules\ModuleManager;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Routes an order's provisioning to the module that actually owns the
 * infrastructure, and records what happened in `provisioning_events`.
 *
 * Before this existed, OrderService::advanceAfterPayment() "auto-provisioned"
 * by doing nothing but flipping the order status to active: no module was ever
 * consulted, so a paid cPanel order produced local billing/hosting rows and no
 * remote account. This class closes that: it resolves the product's
 * provisioning module through IntegrationRegistry (builtins) with a
 * ModuleManager fallback for plugin modules, calls provision(), and reports
 * the outcome so the state machine can activate or fail the order truthfully.
 *
 * The call is deliberately SYNCHRONOUS, unlike ModuleManager::dispatchCapability()
 * which queues through RunModuleCapability. The order state machine has to know
 * whether provisioning succeeded before it can pick active vs failed, and a
 * queued job cannot hand that verdict back to the caller. Every call is wrapped
 * so a module can never bubble an exception into the payment path — the same
 * isolation RunModuleCapability provides, applied inline.
 */
class ProvisioningDispatcher
{
    /** The capability name a module must implement to provision anything. */
    private const CAPABILITY = 'provisioning';

    /**
     * Keys stripped from a module's result data before it is written to the
     * `provisioning_events` audit row. A module hands back the credentials it
     * generated so the caller can deliver them; persisting those into an
     * append-only log would leave plaintext panel passwords in the database
     * forever. Matched case-insensitively as substrings.
     */
    private const SECRET_KEYS = ['password', 'secret', 'token', 'api_key', 'apikey', 'private_key'];

    /**
     * Canonical provisioning scalars that are audited into
     * `service_instances.provisioning_config`. Only these keys are ever written
     * by the dispatcher so unrelated seeder keys (e.g. package/shell) survive.
     *
     * @var list<string>
     */
    private const CANONICAL_KEYS = ['cpu', 'ram', 'disk', 'bandwidth', 'plan'];

    public function __construct(
        private readonly ModuleManager $modules,
        private readonly IntegrationRegistry $registry,
        private readonly HostingService $hosting,
        private readonly ServerAllocator $servers,
        private readonly WelcomeMailer $welcome,
        private readonly ProvisioningEventRecorder $recorder,
    ) {}

    /**
     * Provision the order through its product's module.
     *
     * Always records a `provisioning_events` row — including when no module is
     * available, which is the case that used to be invisible.
     */
    public function run(Order $order): ProvisioningAttempt
    {
        $product = $order->product;
        $slug = $this->moduleFor($product);

        if ($slug === null) {
            return ProvisioningAttempt::noModule($this->recordEvent(
                $order,
                null,
                'pending',
                [
                    'reason' => 'no_provisioning_module',
                    'provisioning_module' => $product?->provisioning_module,
                ],
                null,
            ));
        }

        // Per-link Manual never auto-provisions: the operator creates the
        // resource from the hosting page (HostingController::moduleAction).
        // No ServiceInstance is created here so the manual queue
        // (provisioning_events pending) stays the single source of truth.
        if ($this->isManualLink($product, $slug)) {
            return ProvisioningAttempt::noModule($this->recordEvent(
                $order,
                null,
                'pending',
                [
                    'reason' => 'manual_link',
                    'module' => $slug,
                    'provisioning_module' => $product?->provisioning_module,
                ],
                null,
            ));
        }

        $service = $this->serviceInstanceFor($order);
        $mergedBeforeDecrypt = $this->mergedConfig($slug, $product, $order);

        // Name compute resources after the product's hostname when the
        // hosting row already exists (re-provision/retry). In the first auto
        // pass the row does not exist yet and modules fall back to the
        // service username — never blank either way.
        try {
            $hostName = $order->hostingAccount?->host_name;

            if (is_string($hostName) && trim($hostName) !== '') {
                $mergedBeforeDecrypt['host_name'] = trim($hostName);
            }
        } catch (Throwable) {
            // Hostname is cosmetic — never block provisioning on it.
        }

        $config = $this->registry->decryptConfigFor($slug, $mergedBeforeDecrypt);

        $this->persistProvisioningSnapshot($service, $mergedBeforeDecrypt);

        $missing = ModuleRequiredOptions::missingConfigKeys($config, $slug);

        if ($missing !== []) {
            $message = 'Missing required provisioning options for '.$slug.': '.implode(', ', $missing).' - set them via product options or module config.';

            $service->update(['status' => 'pending']);

            return ProvisioningAttempt::failed($message, $this->recordEvent(
                $order,
                $service,
                'failed',
                ['module' => $slug, 'missing_keys' => $missing],
                ['error' => $message],
            ));
        }

        $driver = $this->resolveDriver($slug);

        if ($driver === null) {
            $message = 'No provisioning driver found for '.$slug.'.';
            $service->update(['status' => 'pending']);

            return ProvisioningAttempt::failed($message, $this->recordEvent(
                $order,
                $service,
                'failed',
                ['module' => $slug],
                ['error' => $message],
            ));
        }

        // The driver call runs behind a durable `running` event so a crash
        // between the host call and the row write stays visible instead of
        // vanishing. complete()/fail() flip this same row — one row per attempt.
        $event = $this->recorder->begin(
            'provision',
            ['module' => $slug] + [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ],
            $service->id,
            $this->hostingAccountIdFor($order),
            auth()->id(),
        );

        try {
            /** @var ProvisioningResult $result */
            $result = $driver->provision($service, $config);
        } catch (Throwable $e) {
            Log::error('Provisioning module threw', [
                'order_id' => $order->id,
                'module' => $slug,
                'error' => $e->getMessage(),
            ]);

            $service->update(['status' => 'pending']);

            return ProvisioningAttempt::failed($e->getMessage(), $this->failRunning($event, $e->getMessage()));
        }

        if (! $result->success) {
            $service->update(['status' => 'pending']);

            return ProvisioningAttempt::failed(
                $result->message ?? 'Provisioning failed',
                $this->failRunning($event, $result->message ?? 'Provisioning failed'),
            );
        }

        // Provider-side identifiers (remote account id, panel username, …) are
        // whatever the module chose to hand back; persist the ones the service
        // instance has columns for and keep the rest in the event payload.
        $service->update(array_filter([
            'status' => 'active',
            'external_id' => $result->data['external_id'] ?? null,
            'username' => $result->data['username'] ?? null,
        ], static fn ($value) => $value !== null));

        $event = $this->completeRunning($event, $result->message ?? 'Provisioned', $result->data, $order);

        // A successful auto provision also settles any stale manual-VM queue
        // entries still pending for this order.
        try {
            $this->recorder->resolveAwaiting($order, 'Provisioning completed');
        } catch (Throwable $e) {
            Log::warning('Could not resolve awaiting provisioning events', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Deliver the credentials the module just generated. This is the only
        // point they exist in plaintext - they are redacted out of the event
        // row above and stored encrypted (or not at all) by the module.
        $this->welcome->send($order, $service->refresh(), $result->data);

        return ProvisioningAttempt::provisioned($result->message, $event);
    }

    /**
     * Record that a Hyper-V manual order is awaiting explicit VM build.
     * Writes a pending provisioning_events row with reason awaiting_manual_vm
     * and creates no ServiceInstance. Used by OrderService::advanceAfterPayment
     * for the paid hyperv+manual ACTIVE path.
     */
    public function noteAwaitingManualVm(Order $order): ?ProvisioningEvent
    {
        return $this->recorder->record(
            'provision',
            'pending',
            [
                'reason' => 'awaiting_manual_vm',
                'module' => 'hyperv',
                'provisioning_module' => $order->product?->provisioning_module,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ],
            null,
            [],
            null,
            $this->hostingAccountIdFor($order),
            auth()->id(),
        );
    }

    /**
     * Whether this Hyper-V order has no active PanelAccount (VM never built).
     * Used to guard lifecycle driver calls so an unprovisioned VM never
     * triggers host HTTP.
     */
    private function isHypervUnprovisioned(Order $order): bool
    {
        $product = $order->product;

        if ($product === null) {
            return false;
        }

        if (! $this->isManual($product)) {
            return false;
        }

        $slug = $this->moduleFor($product);
        $rawSlug = trim((string) ($product->provisioning_module ?? ''));

        $isHyperv = $slug === 'hyperv' || $rawSlug === 'hyperv';

        if (! $isHyperv) {
            return false;
        }

        $service = ServiceInstance::where('order_id', $order->id)->first();

        if ($service === null) {
            return true;
        }

        return ! \App\Models\PanelAccount::where('service_instance_id', $service->id)
            ->where('status', \App\Models\PanelAccount::STATUS_ACTIVE)
            ->exists();
    }

    /**
     * Suspend the order's remote service through its product's module.
     *
     * Order suspension used to be a status flip and nothing else: the order
     * read 'suspended' in the admin UI while the cPanel/Plesk account carried
     * on serving the site. The modules have implemented suspend()/unsuspend()/
     * terminate() since they were written — nothing ever called them.
     */
    public function suspend(Order $order, ?string $reason = null): ProvisioningAttempt
    {
        if ($this->isHypervUnprovisioned($order)) {
            $service = ServiceInstance::where('order_id', $order->id)->first();
            if ($service !== null) {
                $service->update(['status' => 'suspended']);
            }

            return ProvisioningAttempt::noModule(null);
        }

        return $this->lifecycle($order, 'suspend', 'suspended', $reason);
    }

    /**
     * Re-enable a suspended service (the suspended -> active hop).
     */
    public function unsuspend(Order $order, ?string $reason = null): ProvisioningAttempt
    {
        if ($this->isHypervUnprovisioned($order)) {
            $service = ServiceInstance::where('order_id', $order->id)->first();
            if ($service !== null) {
                $service->update(['status' => 'active']);
            }

            return ProvisioningAttempt::noModule(null);
        }

        return $this->lifecycle($order, 'unsuspend', 'active', $reason);
    }

    /**
     * Destroy the order's remote service. Used for both `terminated` and a
     * `cancelled` that ends an order which had already been provisioned.
     */
    public function terminate(Order $order, ?string $reason = null): ProvisioningAttempt
    {
        if ($this->isHypervUnprovisioned($order)) {
            $service = ServiceInstance::where('order_id', $order->id)->first();
            if ($service !== null) {
                $service->update(['status' => 'terminated', 'terminated_at' => now()]);
            }

            // Release any leased IPs on the linked hosting account (best-effort).
            $hosting = $order->hostingAccount()->first();
            if ($hosting === null) {
                $hosting = \App\Models\HostingAccount::where('order_id', $order->id)->first();
            }
            if ($hosting !== null) {
                try {
                    app(\App\Services\IpAssignmentService::class)->release($hosting, $reason ?? 'Terminated (hyperv unprovisioned)');
                } catch (\Throwable $e) {
                    Log::warning('IP release on hyperv unprovisioned terminate failed', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return ProvisioningAttempt::noModule(null);
        }

        return $this->lifecycle($order, 'terminate', 'terminated', $reason);
    }

    /**
     * Shared body of suspend/unsuspend/terminate.
     *
     * Deliberately does NOT create a ServiceInstance: unlike provisioning,
     * these verbs act on something that must already exist. An order that was
     * never provisioned remotely (no service instance) is a silent no-op with
     * no event row — there is nothing to report. An order that HAS a service
     * but no usable module is recorded, because that is a real gap an operator
     * needs to see: local state says suspended, the panel was never told.
     *
     * @param  string  $verb  the module method and the event_type written
     * @param  string  $serviceStatus  service_instances.status on success
     */
    private function lifecycle(Order $order, string $verb, string $serviceStatus, ?string $reason): ProvisioningAttempt
    {
        $service = ServiceInstance::where('order_id', $order->id)->first();

        if ($service === null) {
            return ProvisioningAttempt::noModule(null);
        }

        $product = $order->product;
        $slug = $this->moduleFor($product);

        if ($slug === null) {
            return ProvisioningAttempt::noModule($this->recordEvent(
                $order,
                $service,
                'pending',
                [
                    'reason' => 'no_provisioning_module',
                    'provisioning_module' => $product?->provisioning_module,
                    'note' => $reason,
                ],
                null,
                $verb,
            ));
        }

        $config = $this->configFor($slug, $product, $order);
        $driver = $this->resolveDriver($slug);

        if ($driver === null) {
            return ProvisioningAttempt::failed('No provisioning driver found for '.$slug.'.', $this->recordEvent(
                $order,
                $service,
                'failed',
                ['module' => $slug, 'note' => $reason],
                ['error' => 'No provisioning driver found for '.$slug.'.'],
                $verb,
            ));
        }

        try {
            /** @var ProvisioningResult $result */
            $result = $driver->{$verb}($service, $config);
        } catch (Throwable $e) {
            Log::error('Provisioning module threw on '.$verb, [
                'order_id' => $order->id,
                'module' => $slug,
                'error' => $e->getMessage(),
            ]);

            return ProvisioningAttempt::failed($e->getMessage(), $this->recordEvent(
                $order,
                $service,
                'failed',
                ['module' => $slug, 'note' => $reason],
                ['error' => $e->getMessage()],
                $verb,
            ));
        }

        if (! $result->success) {
            return ProvisioningAttempt::failed(
                $result->message ?? ucfirst($verb).' failed',
                $this->recordEvent($order, $service, 'failed', ['module' => $slug, 'note' => $reason], [
                    'error' => $result->message,
                ], $verb),
            );
        }

        $service->update(['status' => $serviceStatus]);

        return ProvisioningAttempt::provisioned($result->message, $this->recordEvent(
            $order,
            $service,
            'completed',
            ['module' => $slug, 'note' => $reason],
            ['message' => $result->message] + $this->redact($result->data),
            $verb,
        ));
    }

    /**
     * The active module slug that will provision this product, or null.
     *
     * `provisioning_module = 'manual'` is an explicit operator opt-out and is
     * checked FIRST: a manual product must stay manual even when it also has a
     * provisioning-capable module linked for other capabilities. Getting this
     * order wrong would provision the service while the order sat waiting in
     * 'provisioning', since the manual path never activates.
     *
     * Otherwise, two sources in priority order:
     *  1. a module explicitly linked to the product and enabled on that link
     *     (`product_module` by module_slug) — the per-product wiring the admin UI manages;
     *  2. failing that, the product's `provisioning_module` string ('cpanel', 'plesk', …)
     *     when it resolves to a provisioning driver via registry or plugin fallback.
     *
     * In both cases the resolved slug must actually implement the
     * provisioning capability — resolveDriver() returns null otherwise.
     *
     * @return string|null  builtin or plugin slug
     */
    public function moduleFor(?Product $product): ?string
    {
        if ($product === null) {
            return null;
        }

        $slug = trim((string) ($product->provisioning_module ?? ''));

        if ($slug === '' || $slug === 'manual') {
            return null;
        }

        foreach ($product->moduleLinks()->where('enabled', true)->get() as $link) {
            $linkSlug = trim((string) ($link->module_slug ?? ''));

            if ($linkSlug === '') {
                continue;
            }

            if ($this->isProvisionerSlug($linkSlug)) {
                return $linkSlug;
            }
        }

        return $this->isProvisionerSlug($slug) ? $slug : null;
    }

    private function isProvisionerSlug(string $slug): bool
    {
        return $this->resolveDriver($slug) !== null;
    }

    /**
     * Resolve a slug to a provisioning driver instance.
     *
     * Builtins are checked first via IntegrationRegistry (must be instanceof
     * ProvisioningModule); plugin modules fall back to ModuleManager.
     */
    private function resolveDriver(string $slug): ?ProvisioningModuleContract
    {
        $slug = trim($slug);

        if ($slug === '') {
            return null;
        }

        // Builtin path
        if ($this->registry->has($slug)) {
            $instance = $this->registry->instanceFor($slug);

            if ($instance instanceof ProvisioningModuleContract) {
                return $instance;
            }
        }

        // Plugin fallback via ModuleManager
        $module = $this->modules->find($slug);

        if ($module === null || $module->status !== \App\Models\Module::STATUS_ACTIVE) {
            return null;
        }

        $instance = $this->modules->capabilityInstance($module, self::CAPABILITY);

        return $instance instanceof ProvisioningModuleContract ? $instance : null;
    }

    /**
     * Whether the product's link for this slug is set to Manual.
     *
     * Manual is strictly an auto-provisioning opt-out: run() will not touch
     * the module, but suspend/unsuspend/terminate (lifecycle) still route to
     * it so an operator-managed resource still follows the order. Direct
     * operator action always goes through HostingController::moduleAction.
     */
    public function isManualLink(?Product $product, ?string $slug): bool
    {
        if ($product === null || $slug === null || trim($slug) === '') {
            return false;
        }

        $link = $product->moduleLinks()->where('module_slug', trim($slug))->first();

        return $link !== null && ($link->provisioning_mode ?? 'auto') === 'manual';
    }

    /**
     * Whether the product is parked for manual provisioning: either the
     * legacy `provisioning_module = 'manual'` opt-out or the resolved
     * module's link set to Manual.
     */
    public function isManual(?Product $product): bool
    {
        if ($product === null) {
            return true;
        }

        if (trim((string) ($product->provisioning_module ?? '')) === '' || trim((string) ($product->provisioning_module ?? '')) === 'manual') {
            return true;
        }

        return $this->isManualLink($product, $this->moduleFor($product));
    }

    /**
     * Whether this product is a Hyper-V manual product whose IP lease is
     * intentionally deferred until the VM is built.
     *
     * Single home for the hyperv-manual check previously duplicated in
     * OrderService and HostingService.
     */
    public function isHypervManualProduct(?Product $product): bool
    {
        if ($product === null) {
            return false;
        }

        if (! $this->isManual($product)) {
            return false;
        }

        $resolved = $this->moduleFor($product);

        if ($resolved === 'hyperv') {
            return true;
        }

        return trim((string) ($product->provisioning_module ?? '')) === 'hyperv';
    }

    /**
     * Whether the order's service is already live on the remote side: an
     * active ServiceInstance with an active PanelAccount behind it.
     *
     * Used as the double-run guard so the activation hook (OrderService
     * pending/failed -> active) never re-provisions — and never re-sends the
     * welcome mail — after advanceAfterPayment already provisioned the order.
     */
    public function alreadyProvisioned(Order $order): bool
    {
        $service = ServiceInstance::where('order_id', $order->id)->where('status', 'active')->first();

        if ($service === null) {
            return false;
        }

        return \App\Models\PanelAccount::where('service_instance_id', $service->id)
            ->where('status', \App\Models\PanelAccount::STATUS_ACTIVE)
            ->exists();
    }

    /**
     * The decrypted config handed to the module: the snapshot-derived values
     * (order_items.config_options options keyed by `key` -> selected scalar)
     * UNDER the product's own link config. Module/link
     * config wins on collision. Modules always receive decrypted values.
     *
     * @return array<string, mixed>
     */
    private function configFor(string $slug, ?Product $product, ?Order $order = null): array
    {
        return $this->registry->decryptConfigFor($slug, $this->mergedConfig($slug, $product, $order));
    }

    /**
     * Merged config BEFORE decrypt: snapshot UNDER module/link config so
     * module config wins on collision. Canonical keys are lower-cased.
     *
     * @return array<string, mixed>
     */
    private function mergedConfig(string $slug, ?Product $product, ?Order $order = null): array
    {
        /** @var array<string, mixed> $snapshotConfig */
        $snapshotConfig = [];

        if ($order !== null) {
            $items = $order->relationLoaded('items') ? $order->items : $order->items()->get();

            foreach ($items as $item) {
                /** @var mixed $raw */
                $raw = $item->config_options ?? null;

                if (! is_array($raw)) {
                    continue;
                }

                $options = $raw['options'] ?? null;

                if (! is_array($options)) {
                    continue;
                }

                foreach ($options as $option) {
                    if (! is_array($option)) {
                        continue;
                    }

                    $rawKey = $option['key'] ?? null;

                    if (! is_string($rawKey) && ! is_int($rawKey)) {
                        continue;
                    }

                    $canonical = strtolower(trim((string) $rawKey));

                    if ($canonical === '') {
                        continue;
                    }

                    $selected = $option['selected'] ?? null;

                    if ($selected === null) {
                        continue;
                    }

                    if (is_array($selected)) {
                        continue;
                    }

                    // Keep first value for this key; module config will override anyway.
                    if (! array_key_exists($canonical, $snapshotConfig)) {
                        $snapshotConfig[$canonical] = $selected;
                    }
                }
            }
        }

        $link = $product?->moduleLinks()->where('module_slug', $slug)->first();
        $rawConfig = $link?->config ?? null;

        // Plugin fallback: when link has no config but a plugin Module row exists, use its global config.
        if (($rawConfig === null || $rawConfig === []) && ! $this->registry->has($slug)) {
            try {
                $module = $this->modules->find($slug);
                if ($module !== null) {
                    $rawConfig = $module->config ?? [];
                }
            } catch (Throwable) {
                $rawConfig = [];
            }
        }

        if (! is_array($rawConfig)) {
            $rawConfig = [];
        }

        /** @var array<string, mixed> $normalizedModuleConfig */
        $normalizedModuleConfig = [];
        foreach ($rawConfig as $k => $v) {
            $ck = strtolower(trim((string) $k));
            if ($ck === '') {
                continue;
            }
            $normalizedModuleConfig[$ck] = $v;
        }

        // Snapshot UNDER module config: module config wins on collision,
        // except for module-required resource keys where the order's
        // Configuration Options snapshot is authoritative (the customer chose
        // concrete resources at order time; the link only holds fallbacks).
        $merged = array_merge($snapshotConfig, $normalizedModuleConfig);

        foreach (ModuleRequiredOptions::requiredFor($slug) as $key) {
            if (array_key_exists($key, $snapshotConfig)) {
                $merged[$key] = $snapshotConfig[$key];
            }
        }

        return $merged;
    }

    /**
     * Canonical audit snapshot: only cpu/ram/disk/bandwidth/plan scalars from
     * the merged config before decrypt, with secrets stripped.
     *
     * @param  array<string, mixed>  $mergedBeforeDecrypt
     * @return array<string, mixed>
     */
    private function canonicalSnapshot(array $mergedBeforeDecrypt): array
    {
        /** @var array<string, mixed> $snapshot */
        $snapshot = [];

        foreach ($mergedBeforeDecrypt as $rawKey => $value) {
            $key = strtolower(trim((string) $rawKey));

            if (! in_array($key, self::CANONICAL_KEYS, true)) {
                continue;
            }

            if ($value === null || is_array($value)) {
                continue;
            }

            if (! is_scalar($value)) {
                continue;
            }

            $isSecret = false;
            foreach (self::SECRET_KEYS as $needle) {
                if (stripos($key, $needle) !== false) {
                    $isSecret = true;
                    break;
                }
            }

            if ($isSecret) {
                continue;
            }

            $snapshot[$key] = $value;
        }

        return $snapshot;
    }

    /**
     * Persist the canonical snapshot into service_instances.provisioning_config
     * without overwriting unrelated keys (e.g. seeder package/shell).
     *
     * @param  array<string, mixed>  $mergedBeforeDecrypt
     */
    private function persistProvisioningSnapshot(ServiceInstance $service, array $mergedBeforeDecrypt): void
    {
        $snapshot = $this->canonicalSnapshot($mergedBeforeDecrypt);

        if ($snapshot === []) {
            return;
        }

        try {
            $existing = $service->provisioning_config;

            if (! is_array($existing)) {
                $existing = [];
            }

            /** @var array<string, mixed> $updated */
            $updated = array_merge($existing, $snapshot);

            $service->update(['provisioning_config' => $updated]);
        } catch (Throwable $e) {
            Log::warning('Could not persist provisioning snapshot', [
                'service_instance_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The service instance the module operates on, created on first use.
     *
     * `catalog_product_id` stays null — order-born instances belong to the
     * storefront `products` table, not the enterprise catalog (see the
     * 2026_09_06_120000 migration). provisioning_config audit is handled by
     * persistProvisioningSnapshot() in run() so this method only ensures the
     * status flip does not wipe unrelated config keys.
     */
    private function serviceInstanceFor(Order $order): ServiceInstance
    {
        $existing = ServiceInstance::where('order_id', $order->id)->first();

        if ($existing !== null) {
            $existing->update(['status' => 'provisioning']);

            return $existing;
        }

        $product = $order->product;

        return ServiceInstance::create([
            'customer_id' => $order->customer_id,
            'catalog_product_id' => null,
            'order_id' => $order->id,
            // The module is handed a server to talk to; choosing it is core's
            // job (see ServerAllocator). Null when no server matches — the
            // module decides whether it can work without one.
            'server_id' => $this->servers->allocate($product, $product?->provisioning_module)?->id,
            'service_tag' => 'SVC-'.$order->order_number,
            // Same convention as the local hosting account so the two records
            // for one order agree on the identity a module may create remotely.
            'username' => $this->hosting->usernameForOrder($order),
            'domain' => $order->domain_name,
            'provisioning_method' => $product?->provisioning_module,
            'status' => 'provisioning',
        ]);
    }

    /**
     * Strip credential-bearing keys from module result data before it is
     * persisted. Nested arrays are walked so a module returning
     * `['account' => ['password' => …]]` is covered too.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function redact(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            $isSecret = false;

            foreach (self::SECRET_KEYS as $needle) {
                if (stripos((string) $key, $needle) !== false) {
                    $isSecret = true;
                    break;
                }
            }

            if ($isSecret) {
                $clean[$key] = '[redacted]';

                continue;
            }

            $clean[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $result  pre-shaped terminal result (kept for call-site compatibility)
     * @param  string  $eventType  'provision' | 'suspend' | 'unsuspend' | 'terminate'
     */
    private function recordEvent(
        Order $order,
        ?ServiceInstance $service,
        string $status,
        array $payload,
        ?array $result,
        string $eventType = 'provision',
    ): ?ProvisioningEvent {
        // Unpack the pre-shaped result back into the recorder's
        // (message, data) pair so every row is written through the single
        // writer with identical shapes to before.
        $message = null;
        $data = [];

        if (is_array($result)) {
            if ($status === 'completed') {
                $message = $result['message'] ?? null;
                $data = array_diff_key($result, ['message' => true]);
            } elseif ($status === 'failed') {
                $message = $result['error'] ?? null;
                $data = array_diff_key($result, ['error' => true]);
            }
        }

        return $this->recorder->record(
            $eventType,
            $status,
            $payload + [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ],
            is_string($message) ? $message : null,
            $data,
            $service?->id,
            $this->hostingAccountIdFor($order),
            auth()->id(),
        );
    }

    /**
     * Best-effort link from an order to its hosting account for the
     * hosting_account_id event column. Null when the row does not exist yet
     * (first auto-provision pass) — the column is nullable.
     */
    private function hostingAccountIdFor(Order $order): ?int
    {
        try {
            return $order->hostingAccount()->first()?->id;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Flip a running event to failed without ever masking the verdict it describes.
     */
    private function failRunning(ProvisioningEvent $event, string $message): ProvisioningEvent
    {
        try {
            return $this->recorder->fail($event, $message);
        } catch (Throwable $e) {
            Log::warning('Could not record failed provisioning event', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);

            return $event;
        }
    }

    /**
     * Flip a running event to completed without ever masking the success it describes.
     *
     * @param  array<string, mixed>  $data
     */
    private function completeRunning(ProvisioningEvent $event, string $message, array $data, Order $order): ProvisioningEvent
    {
        try {
            return $this->recorder->complete($event, $message, $data);
        } catch (Throwable $e) {
            Log::warning('Could not record completed provisioning event', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return $event;
        }
    }
}
