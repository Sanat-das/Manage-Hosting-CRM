<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

use App\Contracts\Module\ProvisioningResult;
use App\Models\Module;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProvisioningEvent;
use App\Models\ServiceInstance;
use App\Services\HostingService;
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
 * provisioning module through ModuleManager, calls provision(), and reports
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

    public function __construct(
        private readonly ModuleManager $modules,
        private readonly HostingService $hosting,
        private readonly ServerAllocator $servers,
        private readonly WelcomeMailer $welcome,
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
        $module = $this->moduleFor($product);

        if ($module === null) {
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

        $service = $this->serviceInstanceFor($order);
        $config = $this->configFor($module, $product);

        try {
            /** @var ProvisioningResult $result */
            $result = $this->modules
                ->capabilityInstance($module, self::CAPABILITY)
                ->provision($service, $config);
        } catch (Throwable $e) {
            Log::error('Provisioning module threw', [
                'order_id' => $order->id,
                'module' => $module->slug,
                'error' => $e->getMessage(),
            ]);

            $service->update(['status' => 'pending']);

            return ProvisioningAttempt::failed($e->getMessage(), $this->recordEvent(
                $order,
                $service,
                'failed',
                ['module' => $module->slug],
                ['error' => $e->getMessage()],
            ));
        }

        if (! $result->success) {
            $service->update(['status' => 'pending']);

            return ProvisioningAttempt::failed(
                $result->message ?? 'Provisioning failed',
                $this->recordEvent($order, $service, 'failed', ['module' => $module->slug], [
                    'error' => $result->message,
                ]),
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

        $event = $this->recordEvent(
            $order,
            $service,
            'completed',
            ['module' => $module->slug],
            ['message' => $result->message] + $this->redact($result->data),
        );

        // Deliver the credentials the module just generated. This is the only
        // point they exist in plaintext - they are redacted out of the event
        // row above and stored encrypted (or not at all) by the module.
        $this->welcome->send($order, $service->refresh(), $result->data);

        return ProvisioningAttempt::provisioned($result->message, $event);
    }

    /**
     * The active module that will provision this product, or null.
     *
     * `provisioning_module = 'manual'` is an explicit operator opt-out and is
     * checked FIRST: a manual product must stay manual even when it also has a
     * provisioning-capable module linked for other capabilities. Getting this
     * order wrong would provision the service while the order sat waiting in
     * 'provisioning', since the manual path never activates.
     *
     * Otherwise, two sources in priority order:
     *  1. a module explicitly linked to the product and enabled on that link
     *     (`product_module`) — the per-product wiring the admin UI manages;
     *  2. failing that, an installed module whose slug matches the product's
     *     `provisioning_module` string ('cpanel', 'plesk', …).
     *
     * In both cases the module must be active AND actually implement the
     * provisioning capability — `capabilityInstance()` returns null otherwise,
     * and never throws.
     */
    public function moduleFor(?Product $product): ?Module
    {
        if ($product === null) {
            return null;
        }

        $slug = trim((string) ($product->provisioning_module ?? ''));

        if ($slug === '' || $slug === 'manual') {
            return null;
        }

        foreach ($product->moduleLinks()->where('enabled', true)->with('module')->get() as $link) {
            if ($this->isProvisioner($link->module)) {
                return $link->module;
            }
        }

        $module = $this->modules->find($slug);

        return $this->isProvisioner($module) ? $module : null;
    }

    private function isProvisioner(?Module $module): bool
    {
        return $module !== null
            && $module->status === Module::STATUS_ACTIVE
            && $this->modules->capabilityInstance($module, self::CAPABILITY) !== null;
    }

    /**
     * The decrypted config handed to the module: the product's own link config
     * when one exists, otherwise the module's global config. Modules always
     * receive decrypted values (see ModuleContext).
     *
     * @return array<string, mixed>
     */
    private function configFor(Module $module, ?Product $product): array
    {
        $link = $product?->moduleLinks()->where('module_id', $module->id)->first();
        $config = $link?->config ?? $module->config ?? [];

        return $this->modules->decryptConfig($module, $config);
    }

    /**
     * The service instance the module operates on, created on first use.
     *
     * `catalog_product_id` stays null — order-born instances belong to the
     * storefront `products` table, not the enterprise catalog (see the
     * 2026_09_06_120000 migration).
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
     * @param  array<string, mixed>|null  $result
     */
    private function recordEvent(
        Order $order,
        ?ServiceInstance $service,
        string $status,
        array $payload,
        ?array $result,
    ): ?ProvisioningEvent {
        try {
            return ProvisioningEvent::create([
                'service_instance_id' => $service?->id,
                'event_type' => 'provision',
                'event_status' => $status,
                'status' => $status === 'completed' ? 'completed' : ($status === 'failed' ? 'failed' : 'pending'),
                'triggered_by' => auth()->id(),
                'payload' => $payload + [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ],
                'result' => $result,
                'completed_at' => $status === 'completed' ? now() : null,
            ]);
        } catch (Throwable $e) {
            // The audit row must never be the reason an order fails to advance.
            Log::warning('Could not record provisioning event', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
