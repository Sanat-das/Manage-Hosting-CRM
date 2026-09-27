<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

use App\Jobs\ProvisionComputeVm;
use App\Models\HostingAccount;
use App\Models\ProvisioningEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single entry point for starting a compute VM build (Hyper-V, Proxmox VE,
 * future compute drivers).
 *
 * Both the admin module-action and the client portal go through here, so the
 * durable `running` event, the queue dispatch and the "already building"
 * guard exist once. The caller only decides who may ask (permissions) and
 * which template was picked, never how the build is recorded.
 *
 * `$moduleSlug` defaults to `hyperv` for back-compat with the original
 * Hyper-V-only signature.
 */
class VmBuildDispatcher
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
        ?string $template = null,
        bool $startAfterCreate = true,
        ?string $guestUsername = null,
        ?string $guestPassword = null,
        bool $applyGeneratedPassword = false,
        string $moduleSlug = 'hyperv',
    ): ProvisioningEvent {
        $moduleSlug = strtolower(trim($moduleSlug)) !== '' ? strtolower(trim($moduleSlug)) : 'hyperv';

        $this->reconcileStaleBuilds($account);

        $service = $this->provisioner->serviceForHosting($account, $moduleSlug);

        $event = $this->recorder->begin('provision', [
            'module' => $moduleSlug,
            'action' => 'create',
            'hosting_account_id' => $account->id,
            'order_id' => $account->order_id,
            'order_number' => $account->order?->order_number,
            'stage' => 'queued',
            'template' => $template,
        ], $service->id, $account->id);

        ProvisionComputeVm::dispatch(
            $event->id,
            $account->id,
            $template,
            $startAfterCreate,
            $guestUsername,
            $guestPassword,
            $applyGeneratedPassword,
            $moduleSlug,
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
                    Log::warning('VmBuildDispatcher: failed to reconcile stale build', [
                        'event_id' => $event->id,
                        'hosting_account_id' => $account->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (Throwable $e) {
            Log::warning('VmBuildDispatcher: reconcileStaleBuilds failed', [
                'hosting_account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
