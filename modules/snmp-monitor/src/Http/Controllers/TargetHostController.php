<?php

declare(strict_types=1);

namespace Modules\SnmpMonitor\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\HostingAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\SnmpMonitor\Models\SnmpTarget;
use Modules\SnmpMonitor\Services\TargetService;
use Modules\SnmpMonitor\Services\SnmpMetricRepository;

class TargetHostController extends Controller
{
    public function update(Request $request, HostingAccount $hostingAccount): RedirectResponse
    {
        // Guard: product must have snmp-monitor enabled
        $repo = app(SnmpMetricRepository::class);
        try {
            $repo->assertLinked($hostingAccount);
        } catch (\Throwable $e) {
            abort(403, $e->getMessage());
        }

        $hostingAccount->loadMissing('ipAddresses.subnet');
        $availableIps = $hostingAccount->ipAddresses->pluck('ip_address')->map(fn ($ip) => trim((string) $ip))->all();

        $validated = $request->validate([
            'host' => ['nullable', 'string', 'max:45'],
            // Nullable = inherit the product's configured default cadence;
            // the floor matches PollHostBatch::MIN_INTERVAL_SECONDS (the
            // scheduler's own 1-minute tick), the ceiling is 24h.
            'poll_interval' => ['nullable', 'integer', 'min:60', 'max:86400'],
        ]);

        $host = trim((string) ($validated['host'] ?? ''));

        // Empty host means auto — clear the stored host so the next poll
        // auto-resolves from the account's IPAM leases. A non-empty host
        // must be one of the hosting account's assigned IPs.
        if ($host !== '' && ! in_array($host, $availableIps, true)) {
            return back()->withErrors(['host' => "IP {$host} is not assigned to this hosting account. Assigned IPs: " . implode(', ', $availableIps)]);
        }

        $target = SnmpTarget::where('hosting_account_id', $hostingAccount->id)->first();
        if (! $target) {
            $target = app(TargetService::class)->ensureForAccount(
                $hostingAccount,
                TargetService::osFor($hostingAccount, [])
            );
        }

        $pollInterval = isset($validated['poll_interval']) ? (int) $validated['poll_interval'] : null;

        $attributes = [
            'host' => $host !== '' ? $host : null,
            'poll_interval' => $pollInterval,
        ];

        // Re-arm on a cadence change: next_poll_at still holds the moment
        // computed from the OLD interval, so without this a host moved from
        // hourly to every-minute stays silent for up to an hour. NULL makes
        // it due on the next scheduler tick, which then claims it under the
        // new interval.
        if ($target->poll_interval !== $pollInterval) {
            $attributes['next_poll_at'] = null;
        }

        $target->forceFill($attributes)->save();

        $message = $host !== ''
            ? "SNMP host updated to {$host}."
            : 'SNMP host set to Auto — next poll will use the preferred leased IP.';

        return back()->with('success', $message);
    }

    /**
     * Delete an account's SNMP target and every sample collected for it.
     *
     * Used to clear out hosts that no longer exist — a decommissioned VPS
     * keeps failing forever otherwise, inflating the "Down" and "Failing
     * polls" counters on the dashboard.
     *
     * The samples live on the separate monitoring connection and have no
     * foreign key back to snmp_targets, so they are removed explicitly:
     * dropping only the target row would orphan every sample it collected
     * and let a recycled host_id inherit another host's history.
     *
     * NOT permanent on its own. The account's product still carries an
     * enabled snmp-monitor link, and SnmpMonitor::hostingAccountInfo() calls
     * TargetService::ensureForAccount(), so simply opening that hosting
     * account's page recreates the target (with no history). Disabling the
     * module link on the product is what stops monitoring for good — the
     * confirm dialog on the dashboard says so.
     */
    public function destroy(Request $request, HostingAccount $hostingAccount): RedirectResponse
    {
        $repo = app(SnmpMetricRepository::class);

        try {
            $repo->assertLinked($hostingAccount);
        } catch (\Throwable $e) {
            abort(403, $e->getMessage());
        }

        $target = SnmpTarget::where('hosting_account_id', $hostingAccount->id)->first();

        if (! $target) {
            return back()->withErrors(['target' => 'This account has no SNMP target to delete.']);
        }

        $label = trim((string) $target->host) !== '' ? $target->host : $hostingAccount->username;
        $monitoring = DB::connection('monitoring');

        foreach (['snmp_latest', 'snmp_host_samples', 'snmp_if_samples'] as $table) {
            $monitoring->table($table)->where('host_id', $target->id)->delete();
        }

        $target->delete();

        return back()->with('success', "Monitoring deleted for {$label} — target and all collected samples removed.");
    }
}
