<?php

declare(strict_types=1);

namespace Modules\RdpConsole\Services\VmConnect;

use App\Models\HostingAccount;
use App\Models\PanelAccount;
use App\Models\ServiceInstance;
use App\Services\Provisioning\HypervDriver;
use Illuminate\Support\Facades\Cache;
use Modules\RdpConsole\Exceptions\VmConnectUnavailableException;
use Throwable;

/**
 * Resolves a VMConnect target strictly server-side: the account's own
 * hyperv PanelAccount (VM GUID) and that VM's Server row (host address +
 * HOST administrator credentials). Nothing here reads request input, and a
 * missing fact fails closed — it never falls back to guest credentials or to
 * a different VM.
 *
 * The VM GUID prefers the live host probe (the host's own Get-VM answer, the
 * same value VmStatusPresenter surfaces as vm.vmId) and falls back to the
 * recorded external_id / meta.vmId when WinRM is unreachable, so a panel that
 * cannot reach the host does not block a console the guacd host can still
 * open on port 2179.
 */
final class VmConnectTargetResolver
{
    /**
     * Same short-lived cache key and TTL VmStatusPresenter uses for its host
     * probe, so a show-page render and the following console mint share one
     * Get-VM call instead of probing twice.
     */
    private const PROBE_CACHE_KEY = 'hyperv:vm-state:%d';

    private const PROBE_TTL_SECONDS = 10;

    /**
     * @throws VmConnectUnavailableException when any server-side fact is missing
     */
    public function resolve(HostingAccount $hostingAccount): VmConnectTarget
    {
        $panelAccount = $this->panelAccountFor($hostingAccount);

        if ($panelAccount === null) {
            throw new VmConnectUnavailableException('No Hyper-V VM is recorded for this service.');
        }

        // The PanelAccount's server is the host that actually owns the VM;
        // the account's server is the fallback when the panel row predates it.
        $server = $panelAccount->server ?? $hostingAccount->server;

        if ($server === null) {
            throw new VmConnectUnavailableException('No Hyper-V host is linked to this service.');
        }

        $hostname = $this->hostnameFrom($server->api_url, $server->ip_address);

        if ($hostname === '') {
            throw new VmConnectUnavailableException('The Hyper-V host address is not configured on the server.');
        }

        $username = trim((string) $server->api_username);
        $password = (string) ($server->api_password_encrypted ?? '');

        if ($username === '' || trim($password) === '') {
            throw new VmConnectUnavailableException('The Hyper-V host administrator credentials are not configured on the server.');
        }

        $vmGuid = $this->vmGuidFor($hostingAccount, $panelAccount);

        if ($vmGuid === '') {
            throw new VmConnectUnavailableException('No VM GUID is recorded for this VM — create it on the host first.');
        }

        return new VmConnectTarget(
            hostname: $hostname,
            vmGuid: $vmGuid,
            username: $username,
            password: $password,
        );
    }

    /**
     * The account's hyperv PanelAccount, or null. Same lookup chain
     * VmStatusPresenter uses: order id first, then the HOST-{id} service tag.
     * Lookup only — never creates a ServiceInstance row.
     */
    private function panelAccountFor(HostingAccount $hostingAccount): ?PanelAccount
    {
        try {
            $service = null;

            if ($hostingAccount->order_id !== null) {
                $service = ServiceInstance::where('order_id', $hostingAccount->order_id)->first();
            }

            $service ??= ServiceInstance::where('service_tag', 'HOST-'.$hostingAccount->id)->first();

            return $service !== null
                ? PanelAccount::where('service_instance_id', $service->id)->where('panel', 'hyperv')->first()
                : null;
        } catch (Throwable) {
            // Un-migrated tables degrade to "no VM recorded", never a 500.
            return null;
        }
    }

    /**
     * The bare host from the Server row, mirroring HyperVClient::connection()
     * (strip scheme, port and path). Returns '' when nothing usable remains so
     * the caller can fail closed.
     */
    private function hostnameFrom(?string $apiUrl, ?string $ipAddress): string
    {
        $raw = trim((string) ($apiUrl ?: $ipAddress));

        if ($raw === '') {
            return '';
        }

        if (str_contains($raw, '://')) {
            $host = parse_url($raw, PHP_URL_HOST);

            return is_string($host) ? trim($host) : '';
        }

        // host:port without a scheme.
        if (str_contains($raw, ':')) {
            return trim((string) explode(':', $raw)[0]);
        }

        return $raw;
    }

    /**
     * The live probe's GUID when it sees the VM; the recorded GUID otherwise.
     */
    private function vmGuidFor(HostingAccount $hostingAccount, PanelAccount $panelAccount): string
    {
        try {
            $driver = HypervDriver::resolve();

            if ($driver !== null && method_exists($driver, 'recordedVmState')) {
                $cacheKey = sprintf(self::PROBE_CACHE_KEY, $hostingAccount->id);

                $probe = Cache::remember(
                    $cacheKey,
                    self::PROBE_TTL_SECONDS,
                    fn (): array => $driver->recordedVmState($panelAccount),
                );

                if (is_array($probe) && ($probe['exists'] ?? null) === true) {
                    $live = trim((string) ($probe['vmId'] ?? ''));

                    if ($live !== '') {
                        return $live;
                    }
                }
            }
        } catch (Throwable) {
            // Best-effort only: the recorded identity below is authoritative
            // when the panel cannot reach the host over WinRM.
        }

        return $this->recordedVmGuid($panelAccount);
    }

    /**
     * external_id first (documented as the VM's Id), then the meta mirrors.
     */
    private function recordedVmGuid(PanelAccount $panelAccount): string
    {
        $recorded = trim((string) ($panelAccount->external_id ?? ''));

        if ($recorded !== '') {
            return $recorded;
        }

        $meta = is_array($panelAccount->meta) ? $panelAccount->meta : [];
        $inner = is_array($meta['meta'] ?? null) ? $meta['meta'] : [];

        return trim((string) ($inner['vmId'] ?? $meta['vmId'] ?? ''));
    }
}
