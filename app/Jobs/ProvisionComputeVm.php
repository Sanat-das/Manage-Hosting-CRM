<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\HostingAccount;
use App\Models\PanelAccount;
use App\Models\ProvisioningEvent;
use App\Services\HostingService;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Provisioning\ComputeDriver;
use App\Services\Provisioning\ManualProvisioner;
use App\Services\Provisioning\ProvisioningEventRecorder;
use App\Services\Provisioning\VmGuestCredentialStore;
use App\Support\Logging\AppLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Queued, host-verified compute VM build. One job serves every compute
 * driver; the module slug decides which driver runs and which panel record
 * the machine maps to. Hyper-V guest-credential handling (RDP console sync)
 * stays Hyper-V-only — other drivers ignore it.
 *
 * `$moduleSlug` defaults to `hyperv` so payloads queued before the job was
 * generalized still hydrate correctly (ProvisionHypervVm is kept as a
 * subclass for exactly that reason).
 */
class ProvisionComputeVm implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $eventId,
        public readonly int $hostingAccountId,
        public readonly ?string $template,
        public readonly bool $startAfterCreate,
        public readonly ?string $guestUsername,
        public readonly ?string $guestPassword,
        public readonly bool $applyGeneratedPassword = false,
        public readonly string $moduleSlug = 'hyperv',
    ) {
        // Dedicated queue so VM builds do not compete with the 1-minute
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
                'VM build interrupted before it finished: '.($exception?->getMessage() ?? 'worker stopped')
            );
        } catch (Throwable) {
        }
    }

    public function handle(
        ManualProvisioner $provisioner,
        ProvisioningEventRecorder $recorder,
        VmGuestCredentialStore $credentials,
        HostingService $hosting,
    ): void {
        $event = ProvisioningEvent::find($this->eventId);
        $account = HostingAccount::find($this->hostingAccountId);
        $slug = strtolower(trim($this->moduleSlug)) !== '' ? strtolower(trim($this->moduleSlug)) : 'hyperv';

        if ($event === null || $account === null) {
            if ($event !== null) {
                try {
                    $recorder->fail($event, 'Hosting account or event not found.');
                } catch (Throwable) {
                }
            }

            return;
        }

        try {
            $recorder->progress($event, 'checking');
        } catch (Throwable) {
        }

        $driver = ComputeDriver::resolve($slug);

        // If a PanelAccount is recorded and the driver exposes recordedVmState, probe.
        try {
            $service = $provisioner->serviceForHosting($account, $slug);
            $panelAccount = PanelAccount::where('service_instance_id', $service->id)
                ->where('panel', $slug)
                ->first();

            if ($panelAccount !== null && $driver !== null && method_exists($driver, 'recordedVmState')) {
                $probe = $driver->recordedVmState($panelAccount);
                if (($probe['exists'] ?? null) === true) {
                    // Hyper-V reports `state`; Proxmox VE reports `status`.
                    $state = $probe['state'] ?? $probe['status'] ?? 'Unknown';
                    // Optionally start when requested. A start failure is
                    // reported in the completed message — the VM does exist,
                    // so the record stays truthful, but the operator must see
                    // why the requested power-on did not happen.
                    $startError = null;
                    if ($this->startAfterCreate && strtolower((string) $state) !== 'running') {
                        try {
                            $config = $this->driverConfig($account, $slug);
                            $result = $driver->unsuspend($service, $config);
                            if ($result->success) {
                                $state = 'Running';
                                $account->refresh();
                                if ($account->status !== HostingService::STATUS_ACTIVE) {
                                    try {
                                        $hosting->unsuspend($account);
                                    } catch (Throwable) {
                                    }
                                }
                            } else {
                                $startError = $result->message ?? 'start failed.';
                            }
                        } catch (Throwable $e) {
                            $startError = $e->getMessage();
                            AppLog::provisioning()->warning('Queued provision: start after existing probe failed', [
                                'hosting_account_id' => $account->id,
                                'module' => $slug,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                    $message = "VM already exists on host ({$state}) — nothing to build.";
                    if ($startError !== null) {
                        $message .= ' Start failed: '.$startError;
                    }
                    try {
                        $recorder->complete($event, $message, $startError !== null ? ['start_error' => $startError] : []);
                    } catch (Throwable) {
                    }
                    try {
                        $hosting->audit($account, 'hosting.module_action', "VM already exists on host ({$state})", ['module' => $slug, 'action' => 'provision']);
                    } catch (Throwable) {
                    }

                    return;
                }
            }
        } catch (Throwable $e) {
            AppLog::provisioning()->warning('Queued provision pre-check failed', [
                'hosting_account_id' => $account->id,
                'module' => $slug,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $recorder->progress($event, 'cloning');
        } catch (Throwable) {
        }

        // Dispatch to ManualProvisioner with injected event + overrides.
        // The driver reports the later 'verifying'/'starting' stages itself
        // (HyperV::reportStage), so no stage is guessed here.
        $result = $provisioner->provision(
            $account,
            $slug,
            $this->template,
            $event,
            [
                'start_after_create' => $this->startAfterCreate,
                'guest_username' => $this->guestUsername,
                'guest_password' => $this->guestPassword,
                'apply_password' => $this->applyGeneratedPassword,
            ]
        );

        // Safety net: a failure raised BEFORE the driver call (module link
        // disabled, account status, template rule, host probe) returns without
        // touching the injected event. Leaving it `running` would strand the
        // UI progress bar and block every later create — fail it here.
        try {
            $event->refresh();
            if ($event->status === 'running') {
                if ($result->success) {
                    $recorder->complete($event, $result->message ?? 'Provisioned', is_array($result->data ?? null) ? $result->data : []);
                } else {
                    $recorder->fail($event, $result->message ?? 'VM build failed.');
                }
            }
        } catch (Throwable) {
        }

        // Hyper-V only: sync the RDP console when guest credentials were
        // supplied and provisioning succeeded. WHY the re-read: with
        // apply_password the guest password stored by the build is the ROTATED
        // one, not the supplied current password — syncing the supplied value
        // would leave the console stale.
        if ($slug !== 'hyperv' || ! $result->success || $this->guestUsername === null || $this->guestPassword === null) {
            return;
        }

        try {
            $syncUser = $this->guestUsername;
            $syncPass = $this->guestPassword;
            if ($this->applyGeneratedPassword) {
                try {
                    $rotatedService = $provisioner->serviceForHosting($account, 'hyperv');
                    $rotatedPanel = PanelAccount::where('service_instance_id', $rotatedService->id)
                        ->where('panel', 'hyperv')
                        ->first();
                    if ($rotatedPanel !== null) {
                        $readBack = $credentials->read($rotatedPanel);
                        if (($readBack['password'] ?? null) !== null) {
                            $syncUser = $readBack['username'] ?? $syncUser;
                            $syncPass = $readBack['password'];
                        }
                    }
                } catch (Throwable) {
                    // Fall back to the supplied credentials.
                }
            }
            $credentials->syncRdpConsole($account->fresh(), $syncUser, $syncPass);
        } catch (Throwable) {
        }
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
}
