@extends('adminlte::page')

{{-- host_name, matching the hosting account page and the SNMP listing.
     $account is an Eloquent model here, so the accessor supplies the
     HOST-000NN fallback for accounts predating the column. --}}
@section('title', 'SNMP Monitor · '.$account->host_name)

@section('content_header')
    <div class="row align-items-center">
        <div class="col-sm-6">
            <h1 class="m-0">SNMP Monitor <small class="text-muted fw-normal">/ {{ $account->host_name }}</small></h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/admin') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ $dashboardUrl }}">SNMP Monitor</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $account->host_name }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @php
        use Illuminate\Support\Str;
        use Illuminate\Support\Carbon;

        /*
        |----------------------------------------------------------------
        | Single-source-of-truth rule for this page
        |----------------------------------------------------------------
        | Every fact renders EXACTLY once. Identity (hostname, address, OS,
        | uptime, cores, poll status, last poll) lives only in the header
        | card; each resource percentage lives only in its stat card; every
        | collection count lives only in the header of the table that lists
        | it. Anything that would be a second rendering of a fact already on
        | screen belongs in a title="" tooltip, not another box.
        |
        | Styling is stock AdminLTE/Bootstrap — the same card, badge, table
        | and progress classes the dashboard and the rest of the admin use.
        | No bespoke stylesheet: contextual colours come from Bootstrap's
        | success/warning/danger/secondary scale, and the usage thresholds
        | mirror partials/gauge.blade.php exactly so a host reads the same
        | on this page as it does in the listing.
        |
        | $target is a stdClass from DB::table() (SnmpMetricRepository::
        | summary()), NOT an Eloquent model — its datetime columns are raw
        | strings with no Carbon methods. panel.blade.php receives a real
        | SnmpTarget model and can call ->diffForHumans() directly; code
        | must not be copied between the two views.
        */

        $payload = $latest['payload'] ?? [];

        // ——— Identity ———
        $hostname = $payload['hostname'] ?? null;
        $osString = $payload['os'] ?? null;
        $uptimeHuman = $payload['uptime_human'] ?? null;
        $address = ($target?->host ?? '—').':'.($target?->port ?? 161);

        // ——— Poll freshness ———
        $lastPollRaw = ! empty($latest['collected_at'])
            ? (string) $latest['collected_at']
            : (($target?->last_polled_at ?? null) !== null ? (string) $target->last_polled_at : null);

        $lastPollAt = null;
        if ($lastPollRaw !== null) {
            // Guarded: a malformed timestamp degrades to "never polled"
            // rather than 500-ing the whole inspection page.
            try { $lastPollAt = Carbon::parse($lastPollRaw); } catch (\Throwable) { $lastPollAt = null; }
        }

        // A cached verdict is only current for a few poll cycles. Past that
        // the host is not "Up", it is UNOBSERVED — snmp_targets.status only
        // moves when a poll succeeds or fails, so when collection stops the
        // value simply freezes and would otherwise read green forever.
        // poll_interval is the per-host override; the product default is not
        // exposed to this view, so fall back to the same 300s the job uses.
        $pollInterval = (int) ($target?->poll_interval ?: 300);
        $staleAfter = $pollInterval * 3;
        $isStale = $lastPollAt !== null
            && $lastPollAt->diffInSeconds(Carbon::now(), absolute: true) > $staleAfter;

        $pollStatus = $target?->status ?? $latest['status'] ?? 'unknown';

        // Staleness only ever masks a GREEN verdict. "Down" is an observed
        // result with a failure count behind it — the actionable fact — and
        // relabelling it "Stale" would hide that. The alert below still
        // reports the collection gap for a stale down host.
        if ($lastPollAt === null) {
            $statusLabel = 'No data';
            $statusColor = 'secondary';
            $statusHint = 'Awaiting the first successful poll';
        } elseif ($isStale && $pollStatus === 'up') {
            $statusLabel = 'Stale';
            $statusColor = 'warning';
            $statusHint = 'Last poll '.$lastPollAt->diffForHumans().' — older than '
                .round($staleAfter / 60).' min. Readings below are a snapshot, not live.';
        } else {
            $statusLabel = ucfirst($pollStatus);
            $statusColor = match ($pollStatus) { 'up' => 'success', 'down' => 'danger', default => 'secondary' };
            $statusHint = match ($pollStatus) {
                'up' => 'Agent responded to the last poll',
                'down' => 'No SNMP response on the last poll',
                default => 'Awaiting first successful poll',
            };
        }

        $failures = (int) ($target?->consecutive_failures ?? 0);

        // ——— Resource aggregates ———
        $cpu = isset($payload['cpu_load']) && is_numeric($payload['cpu_load']) ? round((float) $payload['cpu_load'], 1) : null;
        $cpuCores = isset($payload['cpu_cores']) && is_numeric($payload['cpu_cores']) ? (int) $payload['cpu_cores'] : null;
        $cpuSourceLabel = match ($payload['cpu_source'] ?? null) {
            'hrProcessorLoad' => 'hrProcessorLoad',
            'ucd-laLoad' => 'UCD laLoad · 1-min avg',
            default => $payload['cpu_source'] ?? 'hrProcessorLoad',
        };

        $memUsedMb = isset($payload['memory_used_mb']) && is_numeric($payload['memory_used_mb']) ? (int) round((float) $payload['memory_used_mb']) : null;
        $memTotalMb = isset($payload['memory_total_mb']) && is_numeric($payload['memory_total_mb']) ? (int) round((float) $payload['memory_total_mb']) : null;
        $memPct = ($memUsedMb !== null && $memTotalMb !== null && $memTotalMb > 0)
            ? round($memUsedMb / $memTotalMb * 100, 1) : null;
        $memDetail = ($memUsedMb !== null && $memTotalMb !== null)
            ? number_format($memUsedMb).' / '.number_format($memTotalMb).' MB' : null;

        $disksList = (isset($payload['disks']) && is_array($payload['disks'])) ? $payload['disks'] : [];
        $diskPct = null; $diskDetail = null;
        if ($disksList !== []) {
            $diskTotalGb = round(array_sum(array_column($disksList, 'total_gb')), 1);
            $diskUsedGb = round(array_sum(array_column($disksList, 'used_gb')), 1);
            if ($diskTotalGb > 0) {
                $diskPct = round($diskUsedGb / $diskTotalGb * 100, 1);
                $diskDetail = $diskUsedGb.' / '.$diskTotalGb.' GB';
            }
        }

        $interfacesList = (isset($payload['interfaces']) && is_array($payload['interfaces'])) ? $payload['interfaces'] : [];
        $processesList = (isset($payload['processes']) && is_array($payload['processes'])) ? $payload['processes'] : [];
        $procCount = isset($payload['processes']) && is_array($payload['processes']) ? count($processesList) : null;
        $ifCount = count($interfacesList);

        // ——— Interface totals (rendered once, in the Network card) ———
        $ifInBps = null; $ifOutBps = null; $ifInOctets = null; $ifOutOctets = null;
        if ($interfacesList !== []) {
            $hasBps = false; $hasOctets = false;
            $sumInBps = 0.0; $sumOutBps = 0.0; $sumInOct = 0.0; $sumOutOct = 0.0;
            foreach ($interfacesList as $iface) {
                $ib = $iface['in_bps'] ?? $iface['inBps'] ?? null;
                $ob = $iface['out_bps'] ?? $iface['outBps'] ?? null;
                if (is_numeric($ib) || is_numeric($ob)) { $hasBps = true; $sumInBps += (float) ($ib ?? 0); $sumOutBps += (float) ($ob ?? 0); }
                $io = $iface['inOctets'] ?? $iface['in_octets'] ?? null;
                $oo = $iface['outOctets'] ?? $iface['out_octets'] ?? null;
                if (is_numeric($io) || is_numeric($oo)) { $hasOctets = true; $sumInOct += (float) ($io ?? 0); $sumOutOct += (float) ($oo ?? 0); }
            }
            if ($hasBps) { $ifInBps = $sumInBps; $ifOutBps = $sumOutBps; }
            if ($hasOctets) { $ifInOctets = $sumInOct; $ifOutOctets = $sumOutOct; }
        }

        // ——— Account bandwidth quota (billing, NOT SNMP) ———
        // bandwidth_quota/bandwidth_used are documented legacy columns on
        // HostingAccount and are integer-cast, so they read 0 (never null) on
        // every account created since they stopped being collected. Gate on
        // > 0 so the card vanishes entirely instead of rendering "0 / 0 GB".
        $bwUsed = (int) ($account->bandwidth_used ?? 0);
        $bwQuota = (int) ($account->bandwidth_quota ?? 0);
        $showBandwidth = $bwQuota > 0 || $bwUsed > 0;
        $bwPct = $bwQuota > 0 ? $account->bandwidthUsagePercent() : null;

        // ——— Shared helpers ———
        // Thresholds identical to partials/gauge.blade.php so a host reads
        // the same here as in the listing.
        $level = function (?float $pct): string {
            if ($pct === null) return 'secondary';
            if ($pct < 70) return 'success';
            if ($pct <= 85) return 'warning';
            return 'danger';
        };
        $levelLabel = function (?float $pct): string {
            if ($pct === null) return 'No data';
            if ($pct < 70) return 'Healthy';
            if ($pct <= 85) return 'Elevated';
            return 'Critical';
        };
        $fmtOctets = function ($v): string {
            if ($v === null || ! is_numeric($v)) return '—';
            $v = (float) $v;
            if ($v >= 1099511627776) return round($v / 1099511627776, 2).' TB';
            if ($v >= 1073741824) return round($v / 1073741824, 2).' GB';
            if ($v >= 1048576) return round($v / 1048576, 2).' MB';
            if ($v >= 1024) return round($v / 1024, 1).' KB';
            return number_format((int) $v).' B';
        };
        $fmtBps = function ($v): string {
            if ($v === null || ! is_numeric($v)) return '—';
            $v = (float) $v;
            if ($v >= 1000000000) return round($v / 1000000000, 2).' Gbps';
            if ($v >= 1000000) return round($v / 1000000, 1).' Mbps';
            if ($v >= 1000) return round($v / 1000, 1).' Kbps';
            return number_format((int) $v).' bps';
        };
        $operLabel = function ($v): array {
            $map = [1 => ['Up', 'success'], 2 => ['Down', 'danger'], 3 => ['Testing', 'warning'],
                    4 => ['Unknown', 'secondary'], 5 => ['Dormant', 'warning'],
                    6 => ['Not present', 'secondary'], 7 => ['Lower layer down', 'danger']];
            $n = is_numeric($v) ? (int) $v : null;
            if ($n !== null && isset($map[$n])) return $map[$n];
            if (is_string($v) && $v !== '') return [ucfirst($v), 'secondary'];
            return ['—', 'secondary'];
        };

        $cpuLevel = $level($cpu !== null ? (float) $cpu : null);
        $memLevel = $level($memPct);
        $diskLevel = $level($diskPct);

        /*
        | One chart per resource instead of a multi-select drawing every
        | chosen metric onto a single axis — mixing "% used" with "MB" and
        | "bps" on one scale made all but the largest series unreadable.
        |
        | Metrics are listed in panel order and flattened into ONE request:
        | SnmpMetricRepository::series() returns datasets in the same order
        | as the metrics it was given, so each panel slices its datasets out
        | by index. Six panels, one round trip, one DB pass.
        */
        $chartPanels = [
            ['id' => 'chart-cpu', 'title' => 'CPU', 'icon' => 'bi-cpu', 'unit' => '% · load',
                'series' => [['metric' => 'cpu_pct', 'axis' => 'y'], ['metric' => 'cpu_load1', 'axis' => 'y1']],
                'percent' => true],
            ['id' => 'chart-memory', 'title' => 'Memory', 'icon' => 'bi-memory', 'unit' => 'MB',
                'series' => [['metric' => 'mem_used_mb', 'axis' => 'y']]],
            ['id' => 'chart-disk', 'title' => 'Disk', 'icon' => 'bi-hdd-stack', 'unit' => '%',
                'series' => [['metric' => 'storage_pct', 'axis' => 'y']], 'percent' => true],
            ['id' => 'chart-network', 'title' => 'Network', 'icon' => 'bi-diagram-3', 'unit' => 'bps',
                'series' => [['metric' => 'in_bps', 'axis' => 'y'], ['metric' => 'out_bps', 'axis' => 'y']]],
            ['id' => 'chart-response', 'title' => 'Response time', 'icon' => 'bi-stopwatch', 'unit' => 'ms',
                'series' => [['metric' => 'response_ms', 'axis' => 'y']]],
            ['id' => 'chart-procs', 'title' => 'Processes', 'icon' => 'bi-list-task', 'unit' => 'count',
                'series' => [['metric' => 'proc_count', 'axis' => 'y']]],
        ];

        $chartMetrics = [];
        foreach ($chartPanels as $panel) {
            foreach ($panel['series'] as $series) {
                $chartMetrics[] = $series['metric'];
            }
        }
    @endphp

    {{-- ═══ Identity. Hostname, address, uptime, cores, OS, status and last
         poll appear ONLY here. ═══ --}}
    <div class="card mb-3">
        <div class="card-body">
            <div class="row align-items-center g-3">
                <div class="col-md-8">
                    <h5 class="mb-1">
                        {{-- The AGENT's own name (sysName), not the account's host_name in
                             the page header. Keeping both is deliberate: a mismatch between
                             what the CRM calls a host and what the host calls itself is
                             worth seeing. --}}
                        <span title="sysName as reported by the SNMP agent">{{ $hostname ?: ($target?->host ?? '—') }}</span>
                        <span class="badge text-bg-{{ $statusColor }} ms-1" title="{{ $statusHint }}">
                            {{ $statusLabel }}@if($failures > 0) · {{ $failures }} {{ Str::plural('fail', $failures) }}@endif
                        </span>
                    </h5>
                    <div class="text-muted small">
                        <span class="font-monospace" title="SNMP poll target">{{ $address }}</span>
                        @if($uptimeHuman)<span class="mx-1">·</span><span title="sysUpTime">up {{ $uptimeHuman }}</span>@endif
                        @if($cpuCores !== null)<span class="mx-1">·</span>{{ $cpuCores }} {{ Str::plural('core', $cpuCores) }}@endif
                        @if($osString)
                            <span class="mx-1">·</span>
                            <span title="{{ $osString }}">{{ Str::limit($osString, 64) }}</span>
                        @endif
                    </div>
                </div>
                <div class="col-md-4 text-md-end">
                    <div class="fw-semibold">{{ $lastPollAt ? $lastPollAt->diffForHumans() : 'Never polled' }}</div>
                    <div class="text-muted small font-monospace">
                        {{ $lastPollAt ? $lastPollAt->format('Y-m-d H:i:s') : 'awaiting first poll' }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Requires $latest: the alert qualifies the readings below it, so on a
         host that has never returned a sample there is nothing to qualify and
         the "No SNMP data yet" notice says it better. --}}
    @if($isStale && $latest !== null)
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Readings below are from {{ $lastPollAt->diffForHumans() }} and are not live —
            this host has not been polled within {{ round($staleAfter / 60) }} minutes.
        </div>
    @endif

    @if ($latest === null)
        <div class="alert alert-info">
            <i class="bi bi-broadcast me-1"></i>
            <strong>No SNMP data yet.</strong>
            This host hasn't returned a poll. CPU, memory, disk, adapters and processes
            appear after the first successful poll.
        </div>
    @endif

    {{-- ═══ Resources. The old Processes/Interfaces count boxes are gone
         (counts belong to their table headers); Network takes a freed slot
         because throughput is reported nowhere else. ═══ --}}
    <div class="row mb-4">
        <div class="col-6 col-lg-3 mb-3">
            <div class="card text-center h-100 border-{{ $cpuLevel }}">
                <div class="card-body py-3">
                    <div class="h4 mb-0 text-{{ $cpuLevel }}">{{ $cpu === null ? '—' : $cpu.'%' }}</div>
                    <div class="text-muted small">CPU</div>
                    @if($cpu !== null)
                        <div class="progress mt-2" style="height:6px;">
                            <div class="progress-bar text-bg-{{ $cpuLevel }}" style="width:{{ min(100, max(0, (float) $cpu)) }}%;"></div>
                        </div>
                    @endif
                    <div class="text-muted small mt-1" title="{{ $cpuSourceLabel }}">
                        {{ $cpu === null ? 'Enable Collect CPU' : ($cpuCores !== null ? $cpuCores.'-core average' : 'Host average') }}
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3 mb-3">
            <div class="card text-center h-100 border-{{ $memLevel }}">
                <div class="card-body py-3">
                    <div class="h4 mb-0 text-{{ $memLevel }}">{{ $memPct === null ? '—' : $memPct.'%' }}</div>
                    <div class="text-muted small">Memory</div>
                    @if($memPct !== null)
                        <div class="progress mt-2" style="height:6px;">
                            <div class="progress-bar text-bg-{{ $memLevel }}" style="width:{{ min(100, max(0, (float) $memPct)) }}%;"></div>
                        </div>
                    @endif
                    <div class="text-muted small mt-1">{{ $memDetail ?? 'Awaiting poll' }}</div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3 mb-3">
            <div class="card text-center h-100 border-{{ $diskLevel }}">
                <div class="card-body py-3">
                    <div class="h4 mb-0 text-{{ $diskLevel }}">{{ $diskPct === null ? '—' : $diskPct.'%' }}</div>
                    <div class="text-muted small">Disk</div>
                    @if($diskPct !== null)
                        <div class="progress mt-2" style="height:6px;">
                            <div class="progress-bar text-bg-{{ $diskLevel }}" style="width:{{ min(100, max(0, (float) $diskPct)) }}%;"></div>
                        </div>
                    @endif
                    <div class="text-muted small mt-1">{{ $diskDetail ?? 'Enable Collect Disk Usage' }}</div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3 mb-3">
            <div class="card text-center h-100">
                <div class="card-body py-3">
                    @if($ifInBps !== null || $ifOutBps !== null)
                        <div class="h5 mb-0">↓ {{ $fmtBps($ifInBps) }}</div>
                        <div class="h5 mb-0">↑ {{ $fmtBps($ifOutBps) }}</div>
                    @else
                        <div class="h4 mb-0 text-{{ $ifInOctets === null ? 'secondary' : 'body' }}">
                            {{ $ifInOctets === null ? '—' : $fmtOctets($ifInOctets + $ifOutOctets) }}
                        </div>
                    @endif
                    <div class="text-muted small">Network</div>
                    <div class="text-muted small mt-1">
                        @if($ifInBps !== null || $ifOutBps !== null)
                            throughput
                        @elseif($ifInOctets !== null)
                            transferred · rates need 2 polls
                        @else
                            Enable Collect Network
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══ Storage — the volume count lives here only ═══ --}}
    <div class="card card-outline card-primary mb-4">
        <div class="card-header d-flex align-items-center">
            <h3 class="card-title mb-0"><i class="bi bi-hdd-stack me-1"></i>Storage</h3>
            <span class="badge text-bg-light ms-auto">{{ count($disksList) }} {{ Str::plural('volume', count($disksList)) }}</span>
        </div>
        @if($disksList === [])
            <div class="card-body text-muted">
                The last poll returned no fixed-disk rows. Enable <strong>Collect Disk Usage</strong>
                in the product config. Loop and snap mounts are filtered automatically.
            </div>
        @else
            <div class="card-body table-responsive p-0">
                <table class="table table-striped table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Volume</th>
                            <th>Used / Total</th>
                            <th>Usage</th>
                            <th class="text-end">%</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($disksList as $disk)
                            @php
                                $used = isset($disk['used_gb']) && is_numeric($disk['used_gb']) ? (float) $disk['used_gb'] : null;
                                $total = isset($disk['total_gb']) && is_numeric($disk['total_gb']) ? (float) $disk['total_gb'] : null;
                                $pct = ($used !== null && $total !== null && $total > 0) ? round($used / $total * 100, 1) : null;
                            @endphp
                            <tr>
                                <td class="text-break">{{ $disk['label'] ?? '—' }}</td>
                                <td class="text-nowrap">
                                    @if($used !== null && $total !== null)
                                        {{ $used }} / {{ $total }} GB
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>@include('snmp-monitor::partials.gauge', ['value' => $pct])</td>
                                <td class="text-end text-nowrap text-{{ $level($pct) }} fw-semibold">
                                    {{ $pct === null ? '—' : $pct.'%' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- ═══ Network adapters — Σ totals are in the card above, not repeated ═══ --}}
    <div class="card card-outline card-primary mb-4">
        <div class="card-header d-flex align-items-center">
            <h3 class="card-title mb-0"><i class="bi bi-ethernet me-1"></i>Network adapters</h3>
            <span class="badge text-bg-light ms-auto">{{ $ifCount }} {{ Str::plural('adapter', $ifCount) }}</span>
        </div>
        @if($interfacesList === [])
            <div class="card-body text-muted">
                The last poll returned no ifTable rows. Enable
                <strong>Collect Network Interfaces</strong> in the product config.
            </div>
        @else
            <div class="card-body table-responsive p-0">
                <table class="table table-striped table-hover align-middle mb-0">
                    <thead>
                        <tr class="text-nowrap">
                            <th>Adapter</th>
                            <th>Status</th>
                            <th>In</th>
                            <th>Out</th>
                            <th>Throughput</th>
                            <th>MAC</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($interfacesList as $iface)
                            @php
                                $name = $iface['name'] ?? $iface['descr'] ?? '';
                                if (trim((string) $name) === '') $name = 'if#'.($iface['index'] ?? '—');
                                [$operText, $operColor] = $operLabel($iface['operStatus'] ?? $iface['oper_status'] ?? null);
                                $inBps = $iface['in_bps'] ?? $iface['inBps'] ?? null;
                                $outBps = $iface['out_bps'] ?? $iface['outBps'] ?? null;
                                $phys = $iface['physAddress'] ?? $iface['phys_address'] ?? null;
                            @endphp
                            <tr>
                                <td class="text-nowrap">
                                    <span class="font-monospace">{{ Str::limit($name, 24) }}</span>
                                    <span class="text-muted small">#{{ $iface['index'] ?? '—' }}</span>
                                </td>
                                <td><span class="badge text-bg-{{ $operColor }}">{{ $operText }}</span></td>
                                <td class="text-nowrap">{{ $fmtOctets($iface['inOctets'] ?? $iface['in_octets'] ?? null) }}</td>
                                <td class="text-nowrap">{{ $fmtOctets($iface['outOctets'] ?? $iface['out_octets'] ?? null) }}</td>
                                <td class="text-nowrap small">
                                    @if(is_numeric($inBps) || is_numeric($outBps))
                                        ↓ {{ $fmtBps($inBps) }} · ↑ {{ $fmtBps($outBps) }}
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="font-monospace small text-nowrap">{{ $phys ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- ═══ Processes — the count lives here only ═══ --}}
    <div class="card card-outline card-primary mb-4">
        <div class="card-header d-flex align-items-center">
            <h3 class="card-title mb-0"><i class="bi bi-list-task me-1"></i>Processes</h3>
            <span class="badge text-bg-light ms-auto">
                {{ $procCount === null ? '—' : number_format($procCount) }} running
            </span>
        </div>
        @if($processesList === [])
            <div class="card-body text-muted">
                @if($procCount === 0)
                    The last poll returned an empty process list.
                @else
                    Enable <strong>Collect Processes</strong> in the product config to list
                    running processes here.
                @endif
            </div>
        @else
            <div class="card-body table-responsive p-0" style="max-height:320px;">
                <table class="table table-striped table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Path</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(array_slice($processesList, 0, 20) as $proc)
                            <tr>
                                <td class="text-muted font-monospace small">{{ $proc['index'] ?? '—' }}</td>
                                <td class="text-break">{{ $proc['name'] ?? '—' }}</td>
                                <td class="text-muted small text-break" title="{{ $proc['path'] ?? '' }}">
                                    {{ Str::limit($proc['path'] ?? '—', 72) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- ═══ Bandwidth quota — billing allowance, NOT SNMP traffic. Gated on
         > 0 because the columns are legacy and integer-cast: a null check
         would render a permanent "0 / 0 GB" card on every modern account. ═══ --}}
    @if($showBandwidth)
        <div class="card card-outline card-primary mb-4">
            <div class="card-header d-flex align-items-center">
                <h3 class="card-title mb-0"><i class="bi bi-speedometer2 me-1"></i>Bandwidth quota</h3>
                @if($bwPct !== null)
                    <span class="badge text-bg-{{ $level($bwPct) }} ms-auto">{{ $bwPct }}% used</span>
                @endif
            </div>
            <div class="card-body">
                <div class="h4 mb-1 text-{{ $level($bwPct) }}">
                    @if($bwQuota > 0)
                        {{ number_format($bwUsed) }} <small class="text-muted">/ {{ number_format($bwQuota) }} GB</small>
                    @else
                        {{ number_format($bwUsed) }} GB <small class="text-muted">used · no quota set</small>
                    @endif
                </div>
                <div class="text-muted small">
                    Hosting account allowance — authoritative for billing, not measured by SNMP.
                </div>
                @if($bwPct !== null)
                    <div class="progress mt-2" style="height:6px;">
                        <div class="progress-bar text-bg-{{ $level($bwPct) }}" style="width:{{ min(100, max(0, (float) $bwPct)) }}%;"></div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- ═══ Metric history ═══ --}}
    <div class="card card-outline card-primary mb-4">
        <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <h3 class="card-title mb-0"><i class="bi bi-graph-up me-1"></i>Metric history</h3>
            {{-- Range applies to every panel; changing it reloads the page. --}}
            <form method="GET" action="{{ url()->current() }}" id="snmp-chart-form"
                  class="ms-auto d-flex align-items-center gap-2">
                <label class="text-muted small mb-0" for="snmp-range">Range</label>
                <select id="snmp-range" name="range" class="form-select form-select-sm w-auto">
                    @foreach ($ranges as $rangeLabel => $seconds)
                        <option value="{{ $rangeLabel }}" @selected($selectedRange === $rangeLabel)>Last {{ $rangeLabel }}</option>
                    @endforeach
                </select>
                <a href="{{ $dashboardUrl }}" class="btn btn-sm btn-outline-secondary">All hosts</a>
            </form>
        </div>
        <div class="card-body">
            <div class="row g-3">
                @foreach ($chartPanels as $panel)
                    <div class="col-12 col-lg-6">
                        <div class="card h-100 mb-0">
                            <div class="card-header d-flex align-items-center py-2">
                                <h3 class="card-title mb-0"><i class="bi {{ $panel['icon'] }} me-1"></i>{{ $panel['title'] }}</h3>
                                <span class="text-muted small ms-auto">{{ $panel['unit'] }}</span>
                            </div>
                            <div class="card-body">
                                <div style="position:relative;height:190px;">
                                    <canvas id="{{ $panel['id'] }}" aria-label="{{ $panel['title'] }} history" role="img"></canvas>
                                    <div class="d-none position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center text-muted small"
                                         id="{{ $panel['id'] }}-empty">No samples in this window</div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="text-muted small mt-3">
                Bucketed from snmp_host_samples / snmp_if_samples · gaps mean no samples.
            </div>
        </div>
    </div>

    @php
        $chartConfig = [
            'endpoint' => $seriesEndpoint,
            'metrics' => $chartMetrics,
            'range' => $selectedRange,
            'panels' => $chartPanels,
        ];
    @endphp
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        (function () {
            var cfg = @json($chartConfig);
            var palette = ['#3b82f6', '#f59e0b', '#22c55e', '#ef4444', '#8b5cf6', '#06b6d4'];

            // One Chart instance per panel, built empty and filled from the
            // single series request below.
            var charts = cfg.panels.map(function (panel, panelIndex) {
                var usesRightAxis = panel.series.some(function (s) { return s.axis === 'y1'; });

                var scales = {
                    x: { ticks: { maxTicksLimit: 6, autoSkip: true, font: { size: 10 } }, grid: { display: false } },
                    y: {
                        beginAtZero: true,
                        ticks: { maxTicksLimit: 5, font: { size: 10 } },
                        // Percentages get a fixed ceiling so a flat 23% line is
                        // not stretched to fill the panel and read as a
                        // dramatic swing.
                        suggestedMax: panel.percent ? 100 : undefined,
                        max: panel.percent ? 100 : undefined
                    }
                };

                if (usesRightAxis) {
                    scales.y1 = {
                        beginAtZero: true,
                        position: 'right',
                        ticks: { maxTicksLimit: 5, font: { size: 10 } },
                        grid: { drawOnChartArea: false }
                    };
                }

                return new Chart(document.getElementById(panel.id), {
                    type: 'line',
                    data: { labels: [], datasets: [] },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: false,
                        spanGaps: true,
                        interaction: { mode: 'index', intersect: false },
                        scales: scales,
                        plugins: {
                            legend: {
                                // A single-series panel is already labelled by
                                // its own heading; a legend would just repeat it.
                                display: panel.series.length > 1,
                                position: 'bottom',
                                labels: { boxWidth: 10, font: { size: 10 }, padding: 8 }
                            }
                        }
                    }
                });
            });

            var query = new URLSearchParams();
            (cfg.metrics || []).forEach(function (metric) { query.append('metrics[]', metric); });
            query.set('range', cfg.range);

            function markEmpty(panelId, empty) {
                var note = document.getElementById(panelId + '-empty');
                if (note) { note.classList.toggle('d-none', !empty); }
            }

            fetch(cfg.endpoint + '?' + query.toString(), {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            })
                .then(function (response) {
                    if (!response.ok) { throw new Error('HTTP ' + response.status); }
                    return response.json();
                })
                .then(function (payload) {
                    var labels = payload.labels || [];
                    var datasets = payload.datasets || [];

                    // series() returns datasets in the exact order of the
                    // metrics[] it was handed, so walking the panels in the
                    // same order keeps a running cursor aligned.
                    var cursor = 0;

                    cfg.panels.forEach(function (panel, panelIndex) {
                        var chart = charts[panelIndex];
                        var colour = 0;

                        chart.data.labels = labels;
                        chart.data.datasets = panel.series.map(function (spec) {
                            var dataset = datasets[cursor++] || { label: spec.metric, data: [] };
                            var hex = palette[(panelIndex + colour++) % palette.length];

                            return {
                                label: dataset.label,
                                data: dataset.data || [],
                                yAxisID: spec.axis || 'y',
                                borderColor: hex,
                                backgroundColor: hex + '22',
                                borderWidth: 2,
                                pointRadius: 0,
                                tension: 0.25,
                                fill: panel.series.length === 1
                            };
                        });
                        chart.update();

                        var hasData = chart.data.datasets.some(function (dataset) {
                            return (dataset.data || []).some(function (value) { return value !== null; });
                        });
                        markEmpty(panel.id, !hasData);
                    });
                })
                .catch(function () {
                    cfg.panels.forEach(function (panel) { markEmpty(panel.id, true); });
                });

            document.getElementById('snmp-chart-form').addEventListener('change', function () {
                this.submit();
            });
        })();
    </script>
@endsection
