<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\Integrations\ProvisioningResult;
use App\Models\HostingAccount;
use App\Models\ProvisioningEvent;
use App\Models\ServiceInstance;
use App\Services\HostingService;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Provisioning\ComputeDriver;
use App\Services\Provisioning\ManualProvisioner;
use App\Services\Provisioning\ProvisioningEventRecorder;
use App\Services\Provisioning\VmStatusPresenter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Queued VM power lifecycle operation (start/stop/restart/suspend/
 * unsuspend/terminate/reset_password). One job serves every compute driver;
 * the account's compute slug decides which driver runs, exactly like the
 * former inline Admin\HostingController::moduleAction flow.
 *
 * Client power actions (start/stop/restart from the client portal) are
 * HOST-ONLY: the caller passes `power_only => true` in $options and the job
 * then skips the local hosting-service status effect entirely (a customer
 * powering off their VM is never a billing suspension). The audit line is
 * still written. Admin callers never pass it, so billing semantics there are
 * unchanged.
 *
 * Never rethrows: a driver refusal or a missing record fails the durable
 * event so the UI progress bar always lands, and failed() covers a killed
 * worker the same way ProvisionComputeVm does.
 */
class RunVmOperation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public int $timeout;

    /**
     * @var array<string, int>
     */
    private const TIMEOUTS = [
        'start' => 360,
        'stop' => 420,
        'restart' => 360,
        'suspend' => 420,
        'unsuspend' => 360,
        'terminate' => 600,
        'reset_password' => 300,
    ];

    /**
     * Request verb => driver verb.
     *
     * @var array<string, string>
     */
    private const DRIVER_VERBS = [
        'start' => 'unsuspend',
        'stop' => 'suspend',
        'restart' => 'restart',
        'suspend' => 'suspend',
        'unsuspend' => 'unsuspend',
        'terminate' => 'terminate',
    ];

    /**
     * Request verb => progress stage (see VmStatusPresenter::STAGES).
     *
     * @var array<string, string>
     */
    private const STAGES = [
        'start' => 'starting',
        'stop' => 'stopping',
        'restart' => 'restarting',
        'suspend' => 'stopping',
        'unsuspend' => 'starting',
        'terminate' => 'destroying',
        'reset_password' => 'resetting',
    ];

    /**
     * Exactly one of the two ids is set: account-addressed operations carry
     * the hosting account (the service mirror is resolved at run time),
     * service-addressed operations carry the service (the hosting account is
     * resolved when one is linked, and skipped when none is).
     *
     * $encryptedOptions is the dispatcher-encrypted `Crypt::encryptString(
     * json_encode($options))` ciphertext — never a plaintext password. It is
     * only decrypted inside handle(), never in the constructor (the payload
     * is serialized to `jobs.payload` as-is) and never logged.
     */
    public function __construct(
        public readonly int $eventId,
        public readonly ?int $hostingAccountId,
        public readonly string $verb,
        public readonly string $encryptedOptions = '',
        public readonly ?int $actorId = null,
        public readonly ?int $serviceInstanceId = null,
    ) {
        if (($this->hostingAccountId === null) === ($this->serviceInstanceId === null)) {
            throw new InvalidArgumentException('Exactly one of hostingAccountId/serviceInstanceId must be set.');
        }

        // Dedicated queue so VM operations do not compete with the 1-minute
        // emails,default scheduled worker. Equivalent to: public string $queue = 'provisioning';
        $this->onQueue('provisioning');
        $this->timeout = self::TIMEOUTS[$this->verb] ?? 360;
    }

    /**
     * Called when the worker dies (MaxAttemptsExceeded, timeout, killed process).
     * Must never throw — a missing or non-running event is a no-op.
     */
    public function failed(?Throwable $exception): void
    {
        try {
            $event = ProvisioningEvent::find($this->eventId);

            if ($event === null || $event->status !== 'running') {
                return;
            }

            app(ProvisioningEventRecorder::class)->fail(
                $event,
                'VM operation interrupted before it finished: '.($exception?->getMessage() ?? 'worker stopped')
            );
        } catch (Throwable) {
        }
    }

    public function handle(
        ProvisioningEventRecorder $recorder,
        HostingService $hosting,
        VmStatusPresenter $presenter,
        ManualProvisioner $provisioner,
    ): void {
        $event = ProvisioningEvent::find($this->eventId);

        if ($event === null) {
            return;
        }

        // The only place the queued ciphertext is decrypted: the payload
        // stays encrypted at rest in `jobs.payload` and is never logged.
        $options = $this->decryptOptions();

        if ($this->hostingAccountId !== null) {
            $account = HostingAccount::find($this->hostingAccountId);

            if ($account === null) {
                try {
                    $recorder->fail($event, 'Hosting account or event not found.');
                } catch (Throwable) {
                }

                return;
            }

            if ($event->status !== 'running') {
                return;
            }

            $payload = is_array($event->payload) ? $event->payload : [];
            $slug = strtolower(trim((string) ($payload['module'] ?? '')));

            if ($slug === '') {
                $this->failQuietly($recorder, $event, 'No compute module is recorded for this operation.');

                return;
            }

            $driver = ComputeDriver::resolve($slug);

            if ($driver === null) {
                $this->failQuietly($recorder, $event, "Module {$slug} cannot provision.");

                return;
            }

            try {
                $service = $provisioner->serviceForHosting($account, $slug);
                $config = $this->driverConfig($account, $slug);
            } catch (Throwable $e) {
                $this->failQuietly($recorder, $event, $e->getMessage());

                return;
            }

            $this->execute($event, $account, $service, $slug, $driver, $config, $recorder, $hosting, $presenter, $options);

            return;
        }

        $service = ServiceInstance::find($this->serviceInstanceId);

        if ($service === null) {
            try {
                $recorder->fail($event, 'Service instance or event not found.');
            } catch (Throwable) {
            }

            return;
        }

        if ($event->status !== 'running') {
            return;
        }

        $payload = is_array($event->payload) ? $event->payload : [];
        $slug = strtolower(trim((string) ($payload['module'] ?? '')));

        if ($slug === '') {
            $this->failQuietly($recorder, $event, 'No compute module is recorded for this operation.');

            return;
        }

        $driver = ComputeDriver::resolve($slug);

        if ($driver === null) {
            $this->failQuietly($recorder, $event, "Module {$slug} cannot provision.");

            return;
        }

        $account = VmOperationDispatcher::accountForService($service);
        $config = $account !== null ? $this->driverConfig($account, $slug) : [];

        $this->execute($event, $account, $service, $slug, $driver, $config, $recorder, $hosting, $presenter, $options);
    }

    /**
     * Run the driver call and land the event. $account is null for an
     * account-less service — then the hosting-side effects and audit are
     * skipped while the service row itself is still synced.
     */
    private function execute(
        ProvisioningEvent $event,
        ?HostingAccount $account,
        ServiceInstance $service,
        string $slug,
        object $driver,
        array $config,
        ProvisioningEventRecorder $recorder,
        HostingService $hosting,
        VmStatusPresenter $presenter,
        array $options,
    ): void {
        if (array_key_exists('delete_vhd', $options)) {
            $config['delete_vhd_on_terminate'] = (bool) $options['delete_vhd'];
        }

        try {
            $recorder->progress($event, self::STAGES[$this->verb] ?? 'finalizing');
        } catch (Throwable) {
        }

        try {
            $result = $this->callDriver($driver, $service, $config, $options);
        } catch (Throwable $e) {
            Log::error('Queued VM operation threw', [
                'hosting_account_id' => $account?->id,
                'service_instance_id' => $service->id,
                'module' => $slug,
                'action' => $this->verb,
                'error' => $e->getMessage(),
            ]);

            $this->failQuietly($recorder, $event, $e->getMessage());

            if ($account !== null) {
                $presenter->forgetVmState($slug, $account->id);
            }

            return;
        }

        // Any module call can change the live VM state; drop the presenter's
        // short probe cache so the post-action render cannot replay it.
        if ($account !== null) {
            $presenter->forgetVmState($slug, $account->id);
        }

        if (! $result->success) {
            $this->failQuietly($recorder, $event, $result->message ?? 'Module action failed.');

            return;
        }

        $driverVerb = self::DRIVER_VERBS[$this->verb] ?? $this->verb;

        if ($account !== null && ! $this->isPowerOnly($options)) {
            try {
                $account->refresh();

                if ($driverVerb === 'unsuspend' && $account->status !== HostingService::STATUS_ACTIVE) {
                    $hosting->unsuspend($account);
                } elseif ($driverVerb === 'suspend' && $account->status === HostingService::STATUS_ACTIVE) {
                    $hosting->suspend($account, 'Module action: '.$slug);
                } elseif ($driverVerb === 'terminate' && $account->status !== HostingService::STATUS_TERMINATED) {
                    $hosting->terminate($account, is_string($options['reason'] ?? null) ? $options['reason'] : 'Module action: '.$slug);
                }
                // restart / reset_password: host-side only, local billing status untouched.
                // power_only (client start/stop/restart): host-side only too —
                // the whole block above is skipped, hosting_accounts.status
                // never moves, but the audit line below still runs.
            } catch (RuntimeException $e) {
                // The module already succeeded — the local guard (e.g. already in
                // the target status) must not mask that.
                try {
                    $hosting->audit($account, 'hosting.module_action', "Module {$slug} {$driverVerb} succeeded; local status already {$account->status}", ['module' => $slug, 'action' => $driverVerb]);
                } catch (Throwable) {
                }
            } catch (Throwable $e) {
                Log::warning('Queued VM operation local status sync failed', [
                    'hosting_account_id' => $account->id,
                    'module' => $slug,
                    'action' => $driverVerb,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $service->refresh();

            // Service-addressed operations own the service row (the
            // controller flipped it local-first at queue time; this converges
            // it after the host call). Account-addressed operations keep
            // today's contract: only the hosting account moves here.
            if ($this->serviceInstanceId !== null) {
                if ($driverVerb === 'unsuspend' && ! in_array($service->status, ['active'], true)) {
                    $service->update(['status' => 'active', 'provision_status' => 'provisioned']);
                } elseif ($driverVerb === 'suspend' && $service->status !== 'suspended') {
                    $service->update(['status' => 'suspended', 'provision_status' => 'suspended']);
                } elseif ($driverVerb === 'terminate' && ! in_array($service->status, ['terminated', 'cancelled'], true)) {
                    $service->update(['status' => 'terminated', 'provision_status' => 'terminated']);
                }
                // restart / reset_password: host-side only, local status untouched.
            }
        } catch (Throwable $e) {
            Log::warning('Queued VM operation service status sync failed', [
                'service_instance_id' => $service->id,
                'module' => $slug,
                'action' => $driverVerb,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $recorder->complete($event, $result->message ?? 'ok', is_array($result->data ?? null) ? $result->data : []);
        } catch (Throwable $e) {
            Log::warning('Queued VM operation completion write failed', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($account !== null) {
            try {
                $hosting->audit($account, 'hosting.module_action', "Module {$slug} {$driverVerb}: ".($result->message ?? 'ok'), ['module' => $slug, 'action' => $driverVerb]);
            } catch (Throwable) {
            }

            $presenter->forgetVmState($slug, $account->id);
        }
    }

    /**
     * Client power actions are host-only: start/stop/restart from the
     * client portal must never move hosting_accounts.status (RULING 1).
     *
     * @param  array<string, mixed>  $options
     */
    private function isPowerOnly(array $options): bool
    {
        return ($options['power_only'] ?? null) === true;
    }

    private function callDriver(object $driver, ServiceInstance $service, array $config, array $options): ProvisioningResult
    {
        if ($this->verb === 'reset_password') {
            if (! method_exists($driver, 'resetGuestAdminPassword')) {
                return ProvisioningResult::fail('Password reset is not available for this service.');
            }

            $newPassword = trim((string) ($options['new_password'] ?? ''));

            if ($newPassword === '') {
                return ProvisioningResult::fail('A new password is required.');
            }

            $username = $options['username'] ?? null;
            $username = is_string($username) && trim($username) !== '' ? trim($username) : null;
            $current = $options['current_password'] ?? null;
            $current = is_string($current) && $current !== '' ? $current : null;

            return $driver->resetGuestAdminPassword($service, $newPassword, $username, $current);
        }

        $driverVerb = self::DRIVER_VERBS[$this->verb] ?? null;

        if ($driverVerb === null || ! method_exists($driver, $driverVerb)) {
            return ProvisioningResult::fail("Unsupported VM operation '{$this->verb}'.");
        }

        return $driver->{$driverVerb}($service, $config);
    }

    /**
     * Decrypt the queued options ciphertext. An empty or undecryptable
     * payload degrades to no options (the verb then fails the event with its
     * usual message) rather than throwing inside the worker.
     *
     * @return array<string, mixed>
     */
    private function decryptOptions(): array
    {
        if (trim($this->encryptedOptions) === '') {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($this->encryptedOptions), true);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Decrypted product link config for the module, empty when no link.
     *
     * @return array<string, mixed>
     */
    private function driverConfig(HostingAccount $account, string $slug): array
    {
        try {
            $link = $account->product?->moduleLinks()->where('module_slug', $slug)->first();

            if ($link === null) {
                return [];
            }

            $raw = is_array($link->config ?? null) ? $link->config : [];

            return app(IntegrationRegistry::class)->decryptConfigFor($slug, $raw);
        } catch (Throwable) {
            return [];
        }
    }

    private function failQuietly(ProvisioningEventRecorder $recorder, ProvisioningEvent $event, string $message): void
    {
        try {
            $recorder->fail($event, $message);
        } catch (Throwable $e) {
            Log::warning('Could not record failed VM operation event', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
