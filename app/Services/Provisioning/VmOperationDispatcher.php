<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

use App\Jobs\RunVmOperation;
use App\Models\HostingAccount;
use App\Models\ProvisioningEvent;
use App\Models\ServiceInstance;
use App\Services\Integrations\IntegrationRegistry;
use App\Support\Logging\AppLog;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Single entry point for queued VM operations (start/stop/restart/
 * suspend/unsuspend/terminate/reset_password).
 *
 * Mirrors VmBuildDispatcher: reconcile stale running rows, guard against a
 * second concurrent action, open the durable `running` event (stage
 * `queued`) and dispatch the job. The caller only decides who may ask —
 * never how the operation is recorded.
 */
class VmOperationDispatcher
{
    /**
     * Request verb => provisioning event type. Matches the inline
     * moduleAction mapping so the audit trail reads the same as before.
     *
     * @var array<string, string>
     */
    private const EVENT_TYPES = [
        'start' => 'unsuspend',
        'stop' => 'suspend',
        'restart' => 'restart',
        'suspend' => 'suspend',
        'unsuspend' => 'unsuspend',
        'terminate' => 'terminate',
        'reset_password' => 'update',
    ];

    /**
     * Event types that describe an operator-visible VM action. Mirrors
     * VmStatusPresenter::ACTION_EVENT_TYPES (private there) — a running row
     * of any of these types blocks a new operation.
     *
     * @var list<string>
     */
    private const ACTION_EVENT_TYPES = ['provision', 'unsuspend', 'suspend', 'restart', 'terminate', 'update'];

    public function __construct(
        private readonly ProvisioningEventRecorder $recorder,
        private readonly ManualProvisioner $provisioner,
        private readonly IntegrationRegistry $registry,
    ) {}

    /**
     * Open the durable running event (stage `queued`) and queue the operation.
     *
     * When $slug is given it is used as-is (the caller already knows which
     * module link it is syncing — the admin lifecycle forms queue one
     * operation per enabled link); when null the account's compute module is
     * auto-resolved exactly as before.
     *
     * @param  array<string, mixed>  $options  new_password, username, current_password, delete_vhd, reason.
     *                                         Client power actions (start/stop/restart) pass `power_only => true`
     *                                         so the job treats them as host-only and never moves
     *                                         hosting_accounts.status (RULING 1); admin callers never pass it.
     *
     * @throws VmOperationConflictException when another action is still running
     * @throws RuntimeException when no compute module resolves for the account
     */
    public function dispatch(
        HostingAccount $account,
        string $verb,
        array $options = [],
        ?int $actorId = null,
        ?string $slug = null,
    ): ProvisioningEvent {
        $verb = strtolower(trim($verb));

        if (! isset(self::EVENT_TYPES[$verb])) {
            throw new RuntimeException("Unsupported VM operation '{$verb}'.");
        }

        $slug = $slug !== null && trim($slug) !== '' ? strtolower(trim($slug)) : null;

        $event = DB::transaction(function () use ($account, $verb, $actorId, $slug): ProvisioningEvent {
            // Serialize concurrent dispatches on the subject row: the
            // running-event guard below is check-then-insert, so without the
            // lock two concurrent POSTs could both pass the guard and queue.
            $locked = HostingAccount::whereKey($account->id)->lockForUpdate()->first() ?? $account;

            $this->reconcileStaleOperations($locked, $slug);

            if ($this->hasRunningOperation($locked, $slug)) {
                throw new VmOperationConflictException('An action is already running for this service.');
            }

            [$slug, $service] = $this->resolve($locked, $verb, $slug);

            return $this->recorder->begin(
                self::EVENT_TYPES[$verb],
                [
                    'module' => $slug,
                    'action' => $verb,
                    'hosting_account_id' => $locked->id,
                    'order_id' => $locked->order_id,
                    'order_number' => $locked->order?->order_number,
                    'stage' => 'queued',
                ],
                $service->id,
                $locked->id,
                $actorId,
            );
        });

        // Dispatched AFTER the transaction commits, never inside it: a
        // database-queue worker must never race an uncommitted event row.
        RunVmOperation::dispatch($event->id, $account->id, $verb, Crypt::encryptString(json_encode($options)), $actorId);

        $this->recorder->handOff($event);

        return $event;
    }

    /**
     * Queue a VM operation for a service directly (the order-less admin
     * service-instance path, which has no hosting account flow to go
     * through). The hosting account is resolved from the service's HOST-{id}
     * mirror first, then from the order's hosting account; when neither
     * exists the operation still queues against the service alone and the
     * job skips the hosting-side effects.
     *
     * Otherwise behaves like dispatch(): stale rows reconcile, a running
     * action for the same module blocks the next one, the durable event is
     * opened (stage `queued`) and the job is pushed on `provisioning` — with
     * the service id set instead of the hosting account id.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws VmOperationConflictException when another action is still running
     * @throws RuntimeException when no compute module resolves for the service
     */
    public function dispatchForService(
        ServiceInstance $service,
        string $verb,
        array $options = [],
        ?int $actorId = null,
        ?string $slug = null,
    ): ProvisioningEvent {
        $verb = strtolower(trim($verb));

        if (! isset(self::EVENT_TYPES[$verb])) {
            throw new RuntimeException("Unsupported VM operation '{$verb}'.");
        }

        $slug = $slug !== null && trim($slug) !== '' ? strtolower(trim($slug)) : null;

        $event = DB::transaction(function () use ($service, $verb, $actorId, $slug): ProvisioningEvent {
            // Lock order is account-then-service inside this transaction: the
            // running-event guard reads the linked HOSTING ACCOUNT's events,
            // so the account row is locked BEFORE the service row. dispatch()
            // locks only the account row, so the order is consistent
            // everywhere and the two entry points cannot deadlock.
            $account = self::accountForService(ServiceInstance::whereKey($service->id)->first() ?? $service);

            if ($account !== null) {
                $account = HostingAccount::whereKey($account->id)->lockForUpdate()->first() ?? $account;
            }

            // Same serialisation as dispatch(): the guard is check-then-insert,
            // so the subject row is locked before the guard is read.
            $locked = ServiceInstance::whereKey($service->id)->lockForUpdate()->first() ?? $service;

            if ($account !== null) {
                $this->reconcileStaleOperations($account, $slug);

                if ($this->hasRunningOperation($account, $slug)) {
                    throw new VmOperationConflictException('An action is already running for this service.');
                }

                if ($slug === null) {
                    $slug = $this->computeSlugFor($account);
                }
            } else {
                $this->reconcileStaleServiceOperations($locked, $slug);

                if ($this->hasRunningServiceOperation($locked, $slug)) {
                    throw new VmOperationConflictException('An action is already running for this service.');
                }

                if ($slug === null) {
                    $method = strtolower(trim((string) ($locked->provisioning_method ?? '')));

                    $slug = ComputeTemplateCatalog::supports($method) ? $method : null;
                }
            }

            if ($slug === null) {
                throw new RuntimeException('No compute module is enabled for this product.');
            }

            if ($account !== null) {
                $this->validateModule($account, $verb, $slug);
            } else {
                $this->validateServiceDriver($verb, $slug);
            }

            return $this->recorder->begin(
                self::EVENT_TYPES[$verb],
                [
                    'module' => $slug,
                    'action' => $verb,
                    'hosting_account_id' => $account?->id,
                    'order_id' => $account?->order_id ?? $locked->order_id,
                    'order_number' => $account?->order?->order_number,
                    'service_tag' => $locked->service_tag,
                    'stage' => 'queued',
                ],
                $locked->id,
                $account?->id,
                $actorId,
            );
        });

        // Dispatched AFTER the transaction commits, never inside it: a
        // database-queue worker must never race an uncommitted event row.
        RunVmOperation::dispatch($event->id, null, $verb, Crypt::encryptString(json_encode($options)), $actorId, $service->id);

        $this->recorder->handOff($event);

        return $event;
    }

    /**
     * The hosting account behind a service: its HOST-{id} mirror first, then
     * the order's hosting account. Null when the service is linked to
     * neither (a purely order-less record).
     */
    public static function accountForService(ServiceInstance $service): ?HostingAccount
    {
        try {
            if (is_string($service->service_tag) && preg_match('/^HOST-(\d+)$/', $service->service_tag, $m) === 1) {
                $mirror = HostingAccount::find((int) $m[1]);

                if ($mirror !== null) {
                    return $mirror;
                }
            }

            if ($service->order_id !== null) {
                $fromOrder = HostingAccount::where('order_id', $service->order_id)->first();

                if ($fromOrder !== null) {
                    return $fromOrder;
                }
            }
        } catch (Throwable) {
            // A lookup failure fails closed below (null).
        }

        return null;
    }

    /**
     * Resolve the compute slug, driver, service and config the same way
     * Admin\HostingController::moduleAction does. Throws when nothing
     * resolves — the controller maps that to 422/back+error.
     *
     * When $givenSlug is set it wins over auto-resolution (one queued
     * operation per enabled module link); the link/driver checks below still
     * apply to it.
     *
     * @return array{0: string, 1: ServiceInstance}
     *
     * @throws RuntimeException
     */
    private function resolve(HostingAccount $account, string $verb, ?string $givenSlug = null): array
    {
        $slug = $givenSlug ?? $this->computeSlugFor($account);

        if ($slug === null) {
            throw new RuntimeException('No compute module is enabled for this product.');
        }

        $this->validateModule($account, $verb, $slug);

        return [$slug, $this->provisioner->serviceForHosting($account, $slug)];
    }

    /**
     * The link/driver/verb checks shared by resolve() and
     * dispatchForService(): the module link must be enabled on the account's
     * product, a driver must resolve, and the verb must be supported.
     *
     * @throws RuntimeException
     */
    private function validateModule(HostingAccount $account, string $verb, string $slug): void
    {
        $link = $account->product?->moduleLinks()->where('module_slug', $slug)->first();

        if ($link === null || ! (bool) $link->enabled) {
            throw new RuntimeException("Module {$this->registry->nameFor($slug)} is not enabled on this product.");
        }

        $driver = ComputeDriver::resolve($slug);

        if ($driver === null) {
            throw new RuntimeException("Module {$this->registry->nameFor($slug)} cannot provision.");
        }

        if ($verb === 'restart' && ! method_exists($driver, 'restart')) {
            throw new RuntimeException("Module {$this->registry->nameFor($slug)} does not support restart.");
        }

        if ($verb === 'reset_password' && ! method_exists($driver, 'resetGuestAdminPassword')) {
            throw new RuntimeException('Password reset is not available for this service.');
        }

        // Decrypt once to prove the config resolves; the job re-reads the
        // live link config at run time so a queued wait never acts on a
        // stale snapshot.
        $this->registry->decryptConfigFor($slug, is_array($link->config ?? null) ? $link->config : []);
    }

    /**
     * Driver check for an account-less (order-less, mirror-less) service:
     * there is no product link to validate against, so a resolvable driver
     * that supports the verb is enough. The job runs with an empty config.
     *
     * @throws RuntimeException
     */
    private function validateServiceDriver(string $verb, string $slug): void
    {
        $driver = ComputeDriver::resolve($slug);

        if ($driver === null) {
            throw new RuntimeException("Module {$this->registry->nameFor($slug)} cannot provision.");
        }

        $driverVerb = $verb === 'reset_password' ? 'resetGuestAdminPassword' : $verb;

        if (! method_exists($driver, $driverVerb)) {
            throw new RuntimeException("Module {$this->registry->nameFor($slug)} does not support {$verb}.");
        }
    }

    /**
     * The compute module that owns this account, or null when the account is
     * not a VM service. Mirrors
     * Admin\HostingController::computeSlugForHosting(): the server's own
     * type wins, then the product's provisioning module, then any enabled
     * module link — every candidate gated on ComputeTemplateCatalog so a
     * non-compute link can never claim the account.
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
            $links = $product->relationLoaded('moduleLinks')
                ? $product->moduleLinks
                : $product->moduleLinks()->where('enabled', true)->get();

            foreach ($links as $link) {
                if (! (bool) ($link->enabled ?? false)) {
                    continue;
                }

                $slug = strtolower(trim((string) ($link->module_slug ?? '')));

                if (ComputeTemplateCatalog::supports($slug)) {
                    return $slug;
                }
            }
        } catch (Throwable) {
            // An unreadable relation fails closed below.
        }

        return null;
    }

    /**
     * Is an action already queued/running for this account? The durable event
     * is the guard (it survives cache flushes). Stale workers (killed on
     * Windows without pcntl timeout) must not block a new operation.
     *
     * When $slug is set only the same module's rows block — the admin
     * lifecycle forms queue one operation per enabled link, so a queued
     * Hyper-V action must not refuse the Proxmox sync of the same account.
     * Rows with no recorded module fail closed and still block.
     */
    private function hasRunningOperation(HostingAccount $account, ?string $slug = null): bool
    {
        return $this->hasRunningFor('hosting_account_id', $account->id, $slug);
    }

    /**
     * Account-less variant of the guard: running rows addressed at the
     * service itself (opened by dispatchForService for a service with no
     * linked hosting account).
     */
    private function hasRunningServiceOperation(ServiceInstance $service, ?string $slug = null): bool
    {
        return $this->hasRunningFor('service_instance_id', $service->id, $slug);
    }

    private function hasRunningFor(string $column, int $id, ?string $slug): bool
    {
        try {
            return ProvisioningEvent::where($column, $id)
                ->where('status', 'running')
                ->whereIn('event_type', self::ACTION_EVENT_TYPES)
                ->get()
                ->contains(fn ($event): bool => ! (method_exists($event, 'isStaleRunning') && $event->isStaleRunning())
                    && ($slug === null || ($m = self::payloadModule($event)) === '' || $m === $slug));
        } catch (Throwable $e) {
            AppLog::provisioning()->warning('VmOperationDispatcher: running-operation guard failed', [
                $column => $id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function reconcileStaleOperations(HostingAccount $account, ?string $slug = null): void
    {
        $this->reconcileStaleFor('hosting_account_id', $account->id, $slug);
    }

    private function reconcileStaleServiceOperations(ServiceInstance $service, ?string $slug = null): void
    {
        $this->reconcileStaleFor('service_instance_id', $service->id, $slug);
    }

    private function reconcileStaleFor(string $column, int $id, ?string $slug): void
    {
        try {
            $stales = ProvisioningEvent::where($column, $id)
                ->where('status', 'running')
                ->whereIn('event_type', self::ACTION_EVENT_TYPES)
                ->get()
                ->filter(fn ($event) => method_exists($event, 'isStaleRunning') && $event->isStaleRunning()
                    && ($slug === null || ($m = self::payloadModule($event)) === '' || $m === $slug));

            foreach ($stales as $event) {
                try {
                    $this->recorder->fail($event, 'The action was interrupted before it finished (worker stopped) — retry the action.');
                } catch (Throwable $e) {
                    AppLog::provisioning()->warning('VmOperationDispatcher: failed to reconcile stale operation', [
                        'event_id' => $event->id,
                        $column => $id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (Throwable $e) {
            AppLog::provisioning()->warning('VmOperationDispatcher: reconcileStaleOperations failed', [
                $column => $id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function payloadModule(ProvisioningEvent $event): string
    {
        $payload = $event->payload;

        return is_array($payload) ? strtolower(trim((string) ($payload['module'] ?? ''))) : '';
    }
}
