<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

use App\Models\HostingAccount;
use App\Models\PanelAccount;
use App\Models\ProvisioningEvent;
use App\Models\ServiceInstance;
use App\Services\HostingService;
use Illuminate\Support\Facades\Cache;

/**
 * The single builder for the VM status/progress contract consumed by the
 * admin and client hosting pages (and their polling endpoints).
 *
 * The contract is frozen — both UIs and their tests depend on it:
 *
 *   {
 *     ok: true,
 *     action: {running, event_id, event_type, action, status, stage,
 *              stage_label, progress, elapsed, message, error} | null,
 *     vm: {exists: bool|null, state: ?string, name: ?string, vmId: ?string,
 *          probe_error: ?string},
 *     account: {status: string},
 *     credentials: {stored: bool, username: string},
 *     can: {create, start, stop, restart, delete, reset_password: bool},
 *     reasons: {action: string}
 *   }
 *
 * `vm.exists` is deliberately tri-state: true (observed), false (observed
 * missing) and null (host unreachable / action running — unknown).
 * `vm.probe_error` carries the host error behind an unknown state so the UI
 * can explain it and offer a retry. The can matrix mirrors the server-side
 * guards exactly: a disabled button must mean the server would refuse, and
 * its reason must say why.
 */
final class VmStatusPresenter
{
    /**
     * Stage (payload.stage) → label + bar percent. The bar is striped and
     * animated in the UI, so a coarse milestone map stays honest: each value
     * marks a real step the job/driver reported, never a fabricated percent.
     *
     * @var array<string, array{label: string, progress: int}>
     */
    private const STAGES = [
        'queued' => ['label' => 'Queued — waiting for the worker', 'progress' => 5],
        'checking' => ['label' => 'Checking the host for an existing VM', 'progress' => 10],
        'cloning' => ['label' => 'Creating the VM on the host', 'progress' => 35],
        'building' => ['label' => 'Creating the VM on the host', 'progress' => 35],
        'verifying' => ['label' => 'Verifying the VM on the host', 'progress' => 75],
        'starting' => ['label' => 'Starting the VM', 'progress' => 88],
        'credentials' => ['label' => 'Verifying Administrator credentials', 'progress' => 92],
        'password' => ['label' => 'Setting a new Administrator password', 'progress' => 94],
        'finalizing' => ['label' => 'Finishing up', 'progress' => 95],
        'done' => ['label' => 'Done', 'progress' => 100],
    ];

    /** Event types that describe an operator-visible VM action. */
    private const ACTION_EVENT_TYPES = ['provision', 'unsuspend', 'suspend', 'restart', 'terminate', 'update'];

    /**
     * Per-slug guest-password reset capability, memoized so the hot render
     * path resolves the compute driver at most once per slug per process.
     * Fail-closed: an unresolvable slug reads as "no reset", same as before.
     *
     * @var array<string, bool>
     */
    private static array $resetCapabilityCache = [];

    /**
     * @param  bool  $refresh  bypass the short VM-state cache (the panel's
     *                         "Retry" affordance after a host probe failure)
     * @return array<string, mixed>
     */
    public function build(HostingAccount $hostingAccount, bool $refresh = false): array
    {
        $action = $this->actionFromLatestEvent($hostingAccount);
        [$slug, $panelAccount] = $this->panelAccountFor($hostingAccount);

        $vmProbeError = null;
        $vm = $this->probeVm($hostingAccount, $slug, $panelAccount, $action, $vmProbeError, $refresh);
        // Explicit probe error so the UI can explain an unknown state and
        // offer a retry instead of leaving every action silently disabled.
        $vm['probe_error'] = $vmProbeError;

        $stored = false;
        $credUsername = $slug === 'hyperv' ? 'Administrator' : 'root';
        if ($panelAccount !== null) {
            try {
                $read = app(VmGuestCredentialStore::class)->read($panelAccount);
                $stored = $read['username'] !== null && $read['password'] !== null;
                if ($read['username'] !== null && $read['username'] !== '') {
                    $credUsername = $read['username'];
                }
            } catch (\Throwable) {
                // Un-migrated credential columns must degrade to "not stored".
            }
        }

        [$can, $reasons] = $this->permissions($slug, $hostingAccount, $vm, $action, $vmProbeError);

        return [
            'ok' => true,
            'action' => $action,
            'vm' => $vm,
            'account' => ['status' => $hostingAccount->status],
            'credentials' => ['stored' => $stored, 'username' => $credUsername],
            'can' => $can,
            'reasons' => $reasons,
        ];
    }

    /**
     * Drop the cached live state for a hosting account after a module action.
     *
     * The action's own page reload renders immediately, so without this the
     * presenter can replay the pre-action state for the rest of the 10s TTL
     * (a stopped VM shown after Start, for example). Never throws.
     */
    public function forgetVmState(string $slug, int $hostingAccountId): void
    {
        try {
            Cache::forget(self::vmStateCacheKey($slug, $hostingAccountId));
        } catch (\Throwable) {
            // Invalidation must never break the action that just ran.
        }
    }

    /**
     * The short-lived live VM-state probe cache. One place owns the key so
     * post-action invalidation can never drift from the read path.
     */
    private static function vmStateCacheKey(string $slug, int $hostingAccountId): string
    {
        return "{$slug}:vm-state:{$hostingAccountId}";
    }

    /**
     * Latest operator-visible action, preferring a still-running row so a
     * queued build always wins over an older terminal one.
     *
     * @return array<string, mixed>|null
     */
    private function actionFromLatestEvent(HostingAccount $hostingAccount): ?array
    {
        $event = ProvisioningEvent::where('hosting_account_id', $hostingAccount->id)
            ->whereIn('event_type', self::ACTION_EVENT_TYPES)
            ->orderByDesc('id')
            ->first();

        if ($event === null || $event->status !== 'running') {
            $running = ProvisioningEvent::where('hosting_account_id', $hostingAccount->id)
                ->where('status', 'running')
                ->orderByDesc('id')
                ->first();

            if ($running !== null) {
                $event = $running;
            }
        }

        if ($event === null) {
            return null;
        }

        $payload = is_array($event->payload) ? $event->payload : [];
        $stage = $payload['stage'] ?? null;
        $running = $event->status === 'running';

        if (is_string($stage) && isset(self::STAGES[$stage])) {
            $stageLabel = self::STAGES[$stage]['label'];
            $progress = self::STAGES[$stage]['progress'];
        } elseif ($running) {
            $stageLabel = 'Working…';
            $progress = 20;
        } else {
            $stageLabel = 'Done';
            $progress = 100;
        }

        $result = is_array($event->result) ? $event->result : [];
        $elapsed = 0;
        try {
            if ($event->created_at !== null) {
                // Carbon 3's diffInSeconds is signed — absolute keeps the
                // counter positive even under host clock skew.
                $elapsed = (int) $event->created_at->diffInSeconds(now(), absolute: true);
            }
        } catch (\Throwable) {
            $elapsed = 0;
        }

        // Killed workers on Windows (no pcntl) leave a `running` row with no
        // failure trace. After the stale threshold the UI must not spin
        // forever and must not block a retry — present as interrupted.
        $isStale = false;
        try {
            $isStale = $running && method_exists($event, 'isStaleRunning') && $event->isStaleRunning();
        } catch (\Throwable) {
            $isStale = false;
        }

        if ($isStale) {
            return [
                'running' => false,
                'event_id' => $event->id,
                'event_type' => $event->event_type,
                'action' => $payload['action'] ?? $event->event_type,
                'status' => $event->status,
                'stage' => $stage,
                'stage_label' => 'Interrupted — retry the build',
                'progress' => $progress,
                'elapsed' => $elapsed,
                'message' => 'The build was interrupted before it finished — retry the create.',
                'error' => null,
            ];
        }

        return [
            'running' => $running,
            'event_id' => $event->id,
            'event_type' => $event->event_type,
            'action' => $payload['action'] ?? $event->event_type,
            'status' => $event->status,
            'stage' => $stage,
            'stage_label' => $stageLabel,
            'progress' => $progress,
            'elapsed' => $elapsed,
            // Terminal rows carry the verdict; a running row must not leak a
            // stale message from a previous attempt.
            'message' => $running ? null : ($result['message'] ?? null),
            'error' => $running ? null : ($event->last_error ?? $result['error'] ?? null),
        ];
    }

    /**
     * Live VM probe. Skipped while an action runs (the host is busy) and on a
     * read path the ServiceInstance is only looked up, never created.
     *
     * @param  array<string, mixed>|null  $action
     * @param  string|null  $probeError  set when the host could not be reached
     * @return array{exists: bool|null, state: ?string, name: ?string, vmId: ?string}
     */
    private function probeVm(HostingAccount $hostingAccount, ?string $slug, ?PanelAccount $panelAccount, ?array $action, ?string &$probeError, bool $refresh = false): array
    {
        $unknown = ['exists' => null, 'state' => null, 'name' => null, 'vmId' => null];

        if ($panelAccount === null) {
            return ['exists' => false, 'state' => null, 'name' => null, 'vmId' => null];
        }

        // No compute module resolves for this account (panel hosting, for
        // example): there is no VM contract to report.
        if ($slug === null) {
            return ['exists' => false, 'state' => null, 'name' => null, 'vmId' => null];
        }

        $identityFallback = [
            'name' => $this->vmNameFromPanel($panelAccount),
            'vmId' => $panelAccount->external_id,
        ];

        if ($action !== null && ($action['running'] ?? false) === true) {
            return ['exists' => null, 'state' => null] + $identityFallback;
        }

        try {
            $driver = ComputeDriver::resolve($slug);

            if ($driver === null || ! method_exists($driver, 'recordedVmState')) {
                // Driver without the hook: trust the recorded identity.
                return ['exists' => true, 'state' => null] + $identityFallback;
            }

            $cacheKey = self::vmStateCacheKey($slug, $hostingAccount->id);
            if ($refresh) {
                // Operator asked to re-check: drop the short TTL so the next
                // probe talks to the host again instead of replaying the error.
                Cache::forget($cacheKey);
            }
            $probe = Cache::remember($cacheKey, 10, fn () => $driver->recordedVmState($panelAccount));

            if (($probe['exists'] ?? null) === true) {
                return [
                    'exists' => true,
                    // Hyper-V reports `state`; Proxmox VE reports `status`.
                    'state' => $probe['state'] ?? $probe['status'] ?? null,
                    'name' => ($probe['name'] ?? null) ?: $identityFallback['name'],
                    'vmId' => ($probe['vmId'] ?? null) ?: $identityFallback['vmId'],
                ];
            }

            if (($probe['exists'] ?? null) === false) {
                return ['exists' => false, 'state' => null, 'name' => null, 'vmId' => null];
            }

            // Transport error: unknown, never "exists".
            $probeError = $probe['error'] ?? null;

            return $unknown;
        } catch (\Throwable $e) {
            $probeError = $e->getMessage();

            return $unknown;
        }
    }

    /**
     * The can/reasons matrix. Mirrors the server guards in
     * HostingController::moduleAction and HyperV::resetGuestAdminPassword.
     *
     * @param  array{exists: bool|null, state: ?string, name: ?string, vmId: ?string}  $vm
     * @param  array<string, mixed>|null  $action
     * @return array{0: array<string, bool>, 1: array<string, string>}
     */
    private function permissions(?string $slug, HostingAccount $hostingAccount, array $vm, ?array $action, ?string $probeError): array
    {
        $can = ['create' => false, 'start' => false, 'stop' => false, 'restart' => false, 'delete' => false, 'reset_password' => false];
        $reasons = [];

        $isRunning = $action !== null && ($action['running'] ?? false) === true;
        $isTerminated = $hostingAccount->status === HostingService::STATUS_TERMINATED;
        $vmExists = $vm['exists'] === true;
        $vmState = $vm['state'] !== null ? strtolower((string) $vm['state']) : null;
        $vmRunning = $vmState === 'running';
        // "off" and "saved" (Hyper-V) and "stopped" (Proxmox VE) are all
        // verified-not-running states.
        $vmOff = in_array($vmState, ['off', 'saved', 'stopped'], true);

        if ($isRunning) {
            foreach (array_keys($can) as $k) {
                $reasons[$k] = 'An action is already running.';
            }
        } elseif ($vm['exists'] === null && $probeError !== null) {
            foreach (array_keys($can) as $k) {
                $reasons[$k] = 'Could not verify the VM on the host — '.$probeError;
            }
        } elseif ($isTerminated) {
            $msgTerm = 'Service is terminated — start/stop/restart is refused. Create re-provisions, Delete cleans up.';

            if (! $vmExists) {
                $can['create'] = true;
                $reasons['delete'] = 'VM is not created on the host yet.';
            } elseif ($vmRunning) {
                $reasons['create'] = 'A VM already exists on the host — delete it first to rebuild.';
                $reasons['delete'] = 'Stop the VM first.';
            } elseif ($vmOff) {
                $reasons['create'] = 'A VM already exists on the host — delete it first to rebuild.';
                $can['delete'] = true;
            } else {
                $reasons['create'] = 'A VM already exists on the host — delete it first to rebuild.';
            }

            foreach (['start', 'stop', 'restart', 'reset_password'] as $k) {
                $reasons[$k] = $k === 'reset_password' ? 'Service is terminated — reset is refused.' : $msgTerm;
            }
        } elseif (! $vmExists) {
            $can['create'] = true;
            foreach (['start', 'stop', 'restart', 'delete', 'reset_password'] as $k) {
                $reasons[$k] = 'VM is not created on the host yet.';
            }
        } elseif ($vmRunning) {
            $reasons['create'] = 'A VM already exists on the host — delete it first to rebuild.';
            $reasons['start'] = 'VM is already running.';
            $can['stop'] = true;
            $can['restart'] = true;
            $reasons['delete'] = 'Stop the VM first.';
            $can['reset_password'] = true;
        } elseif ($vmOff) {
            $reasons['create'] = 'A VM already exists on the host — delete it first to rebuild.';
            $can['start'] = true;
            $reasons['stop'] = 'VM is not running.';
            $reasons['restart'] = 'VM is not running.';
            $can['delete'] = true;
            $reasons['reset_password'] = 'Start the VM to reset the Administrator password.';
        } else {
            // Exists, state unknown: deny the destructive guesses, explain.
            $reasons['create'] = 'A VM already exists on the host — delete it first to rebuild.';
            foreach (['start', 'stop', 'restart', 'delete', 'reset_password'] as $k) {
                $reasons[$k] = 'VM state is unknown — refresh the page or re-test the connection.';
            }
        }

        // Guest-password reset is a driver capability: it is advertised only
        // when the resolved compute driver exposes resetGuestAdminPassword()
        // (Hyper-V via PowerShell Direct, Proxmox VE via the guest agent with
        // a cloud-init fallback). Anything else keeps the frozen reason.
        if (($can['reset_password'] ?? false) === true) {
            if (! self::supportsGuestPasswordReset($slug)) {
                $can['reset_password'] = false;
                $reasons['reset_password'] = 'Password reset is not available for this service.';
            }
        }

        // A reason is only meaningful for a disabled action.
        foreach ($reasons as $key => $reason) {
            if (($can[$key] ?? false) === true) {
                unset($reasons[$key]);
            }
        }

        return [$can, $reasons];
    }

    /**
     * Does this compute module expose a guest-password reset flow? Resolved
     * once per slug per process (see $resetCapabilityCache) — the render
     * hot path calls permissions() on every poll, and driver resolution is
     * pure for a given slug. Fail-closed on any throwable, same as before.
     */
    private static function supportsGuestPasswordReset(?string $slug): bool
    {
        $slug = strtolower(trim((string) ($slug ?? '')));

        if ($slug === '') {
            return false;
        }

        if (! array_key_exists($slug, self::$resetCapabilityCache)) {
            try {
                $driver = ComputeDriver::resolve($slug);
                self::$resetCapabilityCache[$slug] = $driver !== null && method_exists($driver, 'resetGuestAdminPassword');
            } catch (\Throwable) {
                self::$resetCapabilityCache[$slug] = false;
            }
        }

        return self::$resetCapabilityCache[$slug];
    }

    /**
     * The account's compute panel record, plus the module slug it belongs to.
     *
     * Candidate order: the server's own type (a machine lives on the server it
     * was built on), then the product's provisioning module and enabled links.
     * Lookup only — a GET must never create a ServiceInstance or PanelAccount.
     *
     * @return array{0: ?string, 1: ?PanelAccount}
     */
    private function panelAccountFor(HostingAccount $hostingAccount): array
    {
        $slugs = $this->computeSlugs($hostingAccount);

        if ($slugs === []) {
            return [null, null];
        }

        try {
            $service = $this->findServiceForStatus($hostingAccount);

            if ($service === null) {
                return [$slugs[0], null];
            }

            $accounts = PanelAccount::where('service_instance_id', $service->id)
                ->whereIn('panel', $slugs)
                ->get();

            foreach ($slugs as $slug) {
                $match = $accounts->first(fn ($account) => (string) $account->panel === $slug);

                if ($match !== null) {
                    return [$slug, $match];
                }
            }
        } catch (\Throwable) {
            // Un-resolvable service/account must degrade to "no record".
        }

        return [$slugs[0], null];
    }

    /**
     * Module slugs that could own a VM for this account, most-specific first.
     *
     * @return list<string>
     */
    private function computeSlugs(HostingAccount $hostingAccount): array
    {
        $slugs = [];

        $serverType = strtolower(trim((string) ($hostingAccount->server?->server_type ?? '')));
        if (ComputeTemplateCatalog::supports($serverType)) {
            $slugs[] = $serverType;
        }

        $rawModule = strtolower(trim((string) ($hostingAccount->product?->provisioning_module ?? '')));
        if (ComputeTemplateCatalog::supports($rawModule)) {
            $slugs[] = $rawModule;
        }

        try {
            $links = $hostingAccount->product?->relationLoaded('moduleLinks')
                ? $hostingAccount->product->moduleLinks
                : $hostingAccount->product?->moduleLinks()->get();

            foreach ($links ?? [] as $link) {
                if (! (bool) ($link->enabled ?? false)) {
                    continue;
                }

                $slug = strtolower(trim((string) ($link->module_slug ?? '')));

                if (ComputeTemplateCatalog::supports($slug)) {
                    $slugs[] = $slug;
                }
            }
        } catch (\Throwable) {
            // Relation unavailable — the candidates above still apply.
        }

        return array_values(array_unique(array_filter($slugs, static fn (string $slug): bool => $slug !== '')));
    }

    private function findServiceForStatus(HostingAccount $hostingAccount): ?ServiceInstance
    {
        try {
            if ($hostingAccount->order_id !== null) {
                $existing = ServiceInstance::where('order_id', $hostingAccount->order_id)->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            return ServiceInstance::where('service_tag', 'HOST-'.$hostingAccount->id)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Recorded VM name (meta shapes first, username last), capped at 64.
     */
    private function vmNameFromPanel(PanelAccount $account): ?string
    {
        try {
            $meta = is_array($account->meta) ? $account->meta : [];
            $inner = is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
            $name = trim((string) ($inner['vmName'] ?? $inner['name'] ?? $meta['vmName'] ?? $meta['name'] ?? $account->username ?? ''));

            return $name !== '' ? substr($name, 0, 64) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
