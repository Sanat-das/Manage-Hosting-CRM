<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\Integrations\PanelException;
use App\Models\ProvisioningEvent;
use App\Models\Server;
use App\Modules\Proxmox\Services\ProxmoxClient;
use App\Services\Provisioning\ProvisioningEventRecorder;
use App\Support\Logging\AppLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Queued destroy of a VM that exists on the host but has no provisioned
 * record (queued by Admin\ServerController::destroyVm after its guards).
 *
 * Never rethrows: a missing record or a gone VM completes the durable event
 * (that is the desired end state), any other failure fails it, and failed()
 * covers a killed worker. The server page has no progress UI for this event
 * (flash-only) — the row exists for the audit trail.
 */
class RunUnrecordedVmDestroy implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public int $timeout = 600;

    public function __construct(
        public readonly int $eventId,
        public readonly int $serverId,
        public readonly int $vmid,
        public readonly string $node,
    ) {
        // Dedicated queue so the destroy does not compete with the 1-minute
        // emails,default scheduled worker. Equivalent to: public string $queue = 'provisioning';
        $this->onQueue('provisioning');
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
                'Unrecorded VM destroy interrupted before it finished: '.($exception?->getMessage() ?? 'worker stopped')
            );
        } catch (Throwable) {
        }
    }

    public function handle(ProvisioningEventRecorder $recorder): void
    {
        $event = ProvisioningEvent::find($this->eventId);

        if ($event === null || $event->status !== 'running') {
            return;
        }

        $server = Server::find($this->serverId);

        if ($server === null) {
            $this->failQuietly($recorder, $event, 'The server this VM lived on no longer exists.');

            return;
        }

        $client = new ProxmoxClient($server);

        try {
            $exists = $client->vmExists($this->node, $this->vmid)['exists'] === true;
        } catch (PanelException $e) {
            if ($client->isMissingVm($e->getMessage())) {
                $this->completeQuietly($recorder, $event, sprintf('VMID %d was already gone — nothing to destroy.', $this->vmid));

                return;
            }

            $this->failQuietly($recorder, $event, $e->getMessage());

            return;
        } catch (Throwable $e) {
            $this->failQuietly($recorder, $event, 'Proxmox VE probe failed unexpectedly: '.$e->getMessage());

            return;
        }

        if (! $exists) {
            $this->completeQuietly($recorder, $event, sprintf('VMID %d was already gone — nothing to destroy.', $this->vmid));

            return;
        }

        try {
            $client->destroyVm($this->node, $this->vmid);
        } catch (PanelException $e) {
            // PVE 500s when the VM is already gone; that is the desired end
            // state, so it must not fail the event.
            if ($client->isMissingVm($e->getMessage())) {
                $this->completeQuietly($recorder, $event, sprintf('VMID %d was already gone — nothing to destroy.', $this->vmid));

                return;
            }

            AppLog::provisioning()->error('Queued unrecorded VM destroy failed', [
                'server_id' => $server->id,
                'node' => $this->node,
                'vmid' => $this->vmid,
                'error' => $e->getMessage(),
            ]);
            $this->failQuietly($recorder, $event, $e->getMessage());

            return;
        } catch (Throwable $e) {
            AppLog::provisioning()->error('Queued unrecorded VM destroy threw', [
                'server_id' => $server->id,
                'node' => $this->node,
                'vmid' => $this->vmid,
                'error' => $e->getMessage(),
            ]);
            $this->failQuietly($recorder, $event, 'Proxmox VE destroy failed unexpectedly: '.$e->getMessage());

            return;
        }

        AppLog::provisioning()->info('Admin destroyed an unrecorded Proxmox VE VM', [
            'server_id' => $server->id,
            'node' => $this->node,
            'vmid' => $this->vmid,
        ]);

        $this->completeQuietly(
            $recorder,
            $event,
            sprintf('VMID %d was destroyed on node "%s".', $this->vmid, $this->node)
        );
    }

    private function failQuietly(ProvisioningEventRecorder $recorder, ProvisioningEvent $event, string $message): void
    {
        try {
            $recorder->fail($event, $message);
        } catch (Throwable $e) {
            AppLog::provisioning()->warning('Could not record failed unrecorded VM destroy', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function completeQuietly(ProvisioningEventRecorder $recorder, ProvisioningEvent $event, string $message): void
    {
        try {
            $recorder->complete($event, $message);
        } catch (Throwable $e) {
            AppLog::provisioning()->warning('Could not record completed unrecorded VM destroy', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
