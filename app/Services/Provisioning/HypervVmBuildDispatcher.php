<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

use App\Jobs\ProvisionHypervVm;
use App\Models\HostingAccount;
use App\Models\ProvisioningEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single entry point for starting a Hyper-V VM build.
 *
 * Both the admin module-action and the client portal go through here, so the
 * durable `running` event, the queue dispatch and the "already building"
 * guard exist once. The caller only decides who may ask (permissions), never
 * how the build is recorded.
 */
final class HypervVmBuildDispatcher
{
    public function __construct(
        private readonly ProvisioningEventRecorder $recorder,
        private readonly ManualProvisioner $provisioner,
    ) {}

    /**
     * Open the durable running event (stage `queued`) and queue the build.
     */
    public function dispatch(
        HostingAccount $account,
        ?string $templateVm,
        bool $startAfterCreate,
        ?string $guestUsername,
        ?string $guestPassword,
        bool $applyGeneratedPassword = false,
    ): ProvisioningEvent {
        $this->reconcileStaleBuilds($account);

        $service = $this->provisioner->serviceForHosting($account, 'hyperv');

        $event = $this->recorder->begin('provision', [
            'module' => 'hyperv',
            'action' => 'create',
            'hosting_account_id' => $account->id,
            'order_id' => $account->order_id,
            'order_number' => $account->order?->order_number,
            'stage' => 'queued',
        ], $service->id, $account->id);

        ProvisionHypervVm::dispatch(
            $event->id,
            $account->id,
            $templateVm,
            $startAfterCreate,
            $guestUsername,
            $guestPassword,
            $applyGeneratedPassword,
        );

        return $event;
    }

    /**
     * Is a build already queued/running for this account? The durable event is
     * the guard (it survives cache flushes); ManualProvisioner additionally
     * holds a lock while the job runs.
     */
    public function isBuildRunning(HostingAccount $account): bool
    {
        return ProvisioningEvent::where('hosting_account_id', $account->id)
            ->where('status', 'running')
            ->where('event_type', 'provision')
            ->get()
            ->contains(function ($event): bool {
                if (! is_array($event->payload) || ($event->payload['action'] ?? null) !== 'create') {
                    return false;
                }

                // Stale workers (killed on Windows without pcntl timeout) must not block a new build.
                if (method_exists($event, 'isStaleRunning') && $event->isStaleRunning()) {
                    return false;
                }

                return true;
            });
    }

    private function reconcileStaleBuilds(HostingAccount $account): void
    {
        try {
            $stales = ProvisioningEvent::where('hosting_account_id', $account->id)
                ->where('status', 'running')
                ->where('event_type', 'provision')
                ->get()
                ->filter(fn ($event) => method_exists($event, 'isStaleRunning') && $event->isStaleRunning());

            foreach ($stales as $event) {
                try {
                    $this->recorder->fail($event, 'The build was interrupted before it finished (worker stopped) — retry the create.');
                } catch (Throwable $e) {
                    Log::warning('HypervVmBuildDispatcher: failed to reconcile stale build', [
                        'event_id' => $event->id,
                        'hosting_account_id' => $account->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (Throwable $e) {
            Log::warning('HypervVmBuildDispatcher: reconcileStaleBuilds failed', [
                'hosting_account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
