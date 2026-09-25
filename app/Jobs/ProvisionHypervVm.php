<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\HostingAccount;
use App\Models\PanelAccount;
use App\Models\ProvisioningEvent;
use App\Services\HostingService;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Modules\ModuleManager;
use App\Services\Provisioning\ManualProvisioner;
use App\Services\Provisioning\ProvisioningEventRecorder;
use App\Services\Provisioning\VmGuestCredentialStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProvisionHypervVm implements ShouldQueue
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
        public readonly ?string $templateVm,
        public readonly bool $startAfterCreate,
        public readonly ?string $guestUsername,
        public readonly ?string $guestPassword,
        public readonly bool $applyGeneratedPassword = false,
    ) {
        // Dedicated queue so Hyper-V builds do not compete with the 1-minute
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
        IntegrationRegistry $registry,
        ModuleManager $modules
    ): void {
        $event = ProvisioningEvent::find($this->eventId);
        $account = HostingAccount::find($this->hostingAccountId);

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

        // Resolve hyperv driver (registry first, ModuleManager fallback)
        $driver = null;
        try {
            if ($registry->has('hyperv')) {
                $driver = $registry->instanceFor('hyperv');
            } else {
                $module = $modules->find('hyperv');
                if ($module !== null && $module->status === \App\Models\Module::STATUS_ACTIVE) {
                    $driver = $modules->capabilityInstance($module, 'provisioning');
                }
            }
        } catch (Throwable) {
            $driver = null;
        }

        // If a PanelAccount is recorded and driver exposes recordedVmState, probe.
        try {
            $service = $provisioner->serviceForHosting($account, 'hyperv');
            $panelAccount = PanelAccount::where('service_instance_id', $service->id)
                ->where('panel', 'hyperv')
                ->first();

            if ($panelAccount !== null && $driver !== null && method_exists($driver, 'recordedVmState')) {
                $probe = $driver->recordedVmState($panelAccount);
                if (($probe['exists'] ?? null) === true) {
                    $state = $probe['state'] ?? 'Unknown';
                    // Optionally start when requested. A start failure is
                    // reported in the completed message — the VM does exist,
                    // so the record stays truthful, but the operator must see
                    // why the requested power-on did not happen.
                    $startError = null;
                    if ($this->startAfterCreate && strtolower((string) $state) !== 'running') {
                        try {
                            $link = $account->product?->moduleLinks()->where('module_slug', 'hyperv')->first();
                            $config = [];
                            if ($link !== null) {
                                $raw = is_array($link->config ?? null) ? $link->config : [];
                                $config = $registry->decryptConfigFor('hyperv', $raw);
                            }
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
                            Log::warning('Queued provision: start after existing probe failed', [
                                'hosting_account_id' => $account->id,
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
                        $hosting->audit($account, 'hosting.module_action', "VM already exists on host ({$state})", ['module' => 'hyperv', 'action' => 'provision']);
                    } catch (Throwable) {
                    }
                    return;
                }
            }
        } catch (Throwable $e) {
            Log::warning('Queued provision pre-check failed', [
                'hosting_account_id' => $account->id,
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
            'hyperv',
            $this->templateVm,
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

        // Sync RDP console when credentials supplied and provision succeeded.
        // WHY the re-read: with apply_password the guest password stored by
        // the build is the ROTATED one, not the supplied current password —
        // syncing the supplied value would leave the console stale.
        if ($result->success && $this->guestUsername !== null && $this->guestPassword !== null) {
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
    }
}
