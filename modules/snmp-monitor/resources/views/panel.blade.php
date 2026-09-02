{{-- Hosting-page SNMP card. Two modes:
     • No poll data yet → header + one compact notice + poll-IP selector + Refresh.
     • Data present     → metric tiles + only the detail tables that have rows.
 Receives $account, $target, $latest (array|null), $assignedIps (Collection).
 Refresh QUEUES a PollHostBatch; nothing collects inline. --}}
@php
    $payload = $latest['payload'] ?? [];
    $hasData = $latest !== null;

    $cpu = isset($payload['cpu_load']) && is_numeric($payload['cpu_load'])
        ? round((float) $payload['cpu_load'], 1) : null;
    $cpuCores = isset($payload['cpu_cores']) && is_numeric($payload['cpu_cores']) ? (int) $payload['cpu_cores'] : null;
    $cpuSource = $payload['cpu_source'] ?? null;

    $memPct = (isset($payload['memory_used_mb'], $payload['memory_total_mb'])
        && is_numeric($payload['memory_used_mb']) && is_numeric($payload['memory_total_mb'])
        && (float) $payload['memory_total_mb'] > 0)
        ? round((float) $payload['memory_used_mb'] / (float) $payload['memory_total_mb'] * 100, 1) : null;
    $memDetail = (isset($payload['memory_used_mb'], $payload['memory_total_mb']))
        ? round((float) $payload['memory_used_mb']).' / '.round((float) $payload['memory_total_mb']).' MB' : null;

    $disks = (isset($payload['disks']) && is_array($payload['disks'])) ? $payload['disks'] : [];
    $diskPct = null; $diskDetail = null;
    if ($disks !== []) {
        $totalGb = array_sum(array_column($disks, 'total_gb'));
        $usedGb = array_sum(array_column($disks, 'used_gb'));
        if ($totalGb > 0) {
            $diskPct = round($usedGb / $totalGb * 100, 1);
            $diskDetail = round($usedGb, 1).' / '.round($totalGb, 1).' GB';
        }
    }

    $interfaces = (isset($payload['interfaces']) && is_array($payload['interfaces'])) ? $payload['interfaces'] : [];
    $ifCount = count($interfaces);
    $inOctets = 0.0; $outOctets = 0.0;
    foreach ($interfaces as $if) {
        $inOctets += (float) ($if['inOctets'] ?? $if['in_octets'] ?? 0);
        $outOctets += (float) ($if['outOctets'] ?? $if['out_octets'] ?? 0);
    }

    $processes = (isset($payload['processes']) && is_array($payload['processes'])) ? $payload['processes'] : [];
    $procCount = count($processes);

    $hostname = $payload['hostname'] ?? null;
    $os = $payload['os'] ?? null;
    $uptimeHuman = $payload['uptime_human'] ?? null;

    $effectiveStatus = $latest['status'] ?? $target->status;
    $badgeColor = match ($effectiveStatus) {
        \Modules\SnmpMonitor\Models\SnmpTarget::STATUS_UP => 'success',
        \Modules\SnmpMonitor\Models\SnmpTarget::STATUS_DOWN => 'danger',
        default => 'secondary',
    };
    $statusHint = match ($effectiveStatus) {
        \Modules\SnmpMonitor\Models\SnmpTarget::STATUS_UP => 'Agent responded to the last poll',
        \Modules\SnmpMonitor\Models\SnmpTarget::STATUS_DOWN => 'No SNMP response on the last poll',
        default => 'Awaiting the first successful poll',
    };

    $lastPoll = null; $lastPollExact = null;
    if (! empty($latest['collected_at'])) {
        $lastPollExact = (string) $latest['collected_at'];
        $lastPoll = \Illuminate\Support\Carbon::parse($lastPollExact)->diffForHumans();
    } elseif ($target->last_polled_at !== null) {
        $lastPoll = $target->last_polled_at->diffForHumans();
    }

    // Usage level: green < 70, amber 70-85, red > 85.
    $level = fn (?float $pct) => $pct === null ? 'secondary' : ($pct < 70 ? 'success' : ($pct <= 85 ? 'warning' : 'danger'));

    $assignedIps = $assignedIps ?? collect();
    $isClient = $isClient ?? false;
@endphp

<div class="card shadow-sm">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-2 px-3">
        <div class="d-flex align-items-center gap-2 min-w-0">
            <i class="bi bi-activity text-primary"></i>
            <h6 class="mb-0 fw-semibold">SNMP Monitor</h6>
            <span class="badge text-bg-{{ $badgeColor }}" title="{{ $statusHint }}">{{ ucfirst($effectiveStatus) }}</span>
            @if ($target->host)
                <span class="badge text-bg-light border text-muted" style="font-family:ui-monospace,monospace;"
                      title="Polling {{ $target->host }}:{{ $target->port ?? 161 }}">
                    {{ $target->host }}:{{ $target->port ?? 161 }}
                </span>
            @endif
        </div>
        @unless ($isClient)
            <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.snmp-monitor.host.show', $account) }}">
                <i class="bi bi-graph-up me-1"></i> Open dashboard
            </a>
        @endunless
    </div>

    <div class="card-body p-3">
        {{-- ── No data yet: short and actionable ── --}}
        @if (! $hasData)
            <div class="text-muted small mb-3">
                <i class="bi bi-info-circle me-1"></i>
                <strong class="text-body">No data yet.</strong>
                @if ($isClient)
                    CPU, memory, disks, adapters and bandwidth will appear after the first successful poll.
                @else
                    Pick the poll target below and hit Refresh — CPU, memory, disks, adapters and bandwidth appear after the first successful poll.
                @endif
            </div>
        @endif

        {{-- ── Metrics: one compact row, only when data exists ── --}}
        @if ($hasData)
            <div class="row g-2 text-center mb-1">
                <div class="col-4 col-md-2">
                    <div class="border rounded p-2 h-100">
                        <div class="fs-5 fw-semibold text-{{ $level($cpu) }}">{{ $cpu === null ? '—' : $cpu.'%' }}</div>
                        <div class="text-muted small">CPU</div>
                        <div class="text-muted" style="font-size:.68rem;">
                            {{ $cpuCores !== null ? $cpuCores.' core'.($cpuCores == 1 ? '' : 's') : ($cpuSource === 'ucd-laLoad' ? '1-min load' : '—') }}
                        </div>
                    </div>
                </div>
                <div class="col-4 col-md-2">
                    <div class="border rounded p-2 h-100">
                        <div class="fs-5 fw-semibold text-{{ $level($memPct) }}">{{ $memPct === null ? '—' : $memPct.'%' }}</div>
                        <div class="text-muted small">Memory</div>
                        <div class="text-muted" style="font-size:.68rem;">{{ $memDetail ?? '—' }}</div>
                    </div>
                </div>
                <div class="col-4 col-md-2">
                    <div class="border rounded p-2 h-100">
                        <div class="fs-5 fw-semibold text-{{ $level($diskPct) }}">{{ $diskPct === null ? '—' : $diskPct.'%' }}</div>
                        <div class="text-muted small">Disk</div>
                        <div class="text-muted" style="font-size:.68rem;">{{ $diskDetail ?? ($disks !== [] ? count($disks).' volumes' : '—') }}</div>
                    </div>
                </div>
                <div class="col-4 col-md-2">
                    <div class="border rounded p-2 h-100">
                        <div class="fs-5 fw-semibold">{{ $procCount > 0 ? number_format($procCount) : '—' }}</div>
                        <div class="text-muted small">Processes</div>
                        <div class="text-muted" style="font-size:.68rem;">running</div>
                    </div>
                </div>
                <div class="col-4 col-md-2">
                    <div class="border rounded p-2 h-100">
                        <div class="fs-5 fw-semibold">{{ $ifCount > 0 ? $ifCount : '—' }}</div>
                        <div class="text-muted small">Adapters</div>
                        <div class="text-muted" style="font-size:.68rem;">network</div>
                    </div>
                </div>
                <div class="col-4 col-md-2">
                    <div class="border rounded p-2 h-100">
                        <div class="fs-6 fw-semibold pt-1" title="{{ $lastPollExact }}">{{ $lastPoll ?? '—' }}</div>
                        <div class="text-muted small">Last poll</div>
                        <div class="text-muted" style="font-size:.68rem;">{{ $lastPollExact ?? 'no poll yet' }}</div>
                    </div>
                </div>
            </div>

            {{-- Identity line --}}
            @if ($hostname || $os || $uptimeHuman)
                <div class="small text-muted d-flex flex-wrap gap-3 mb-1">
                    @if ($hostname)<span><i class="bi bi-hdd me-1"></i>{{ $hostname }}</span>@endif
                    @if ($os)<span class="text-truncate" style="max-width:40ch;" title="{{ $os }}"><i class="bi bi-window-stack me-1"></i>{{ \Illuminate\Support\Str::limit($os, 60) }}</span>@endif
                    @if ($uptimeHuman)<span><i class="bi bi-clock me-1"></i>Up {{ $uptimeHuman }}</span>@endif
                </div>
            @endif

            {{-- Per-disk breakdown --}}
            @if ($disks !== [])
                <div class="mt-2">
                    <div class="small fw-semibold text-muted text-uppercase mb-1" style="font-size:.68rem;letter-spacing:.05em;">Disks</div>
                    <div class="table-responsive">
                        <table class="table table-sm table-borderless align-middle mb-0">
                            <tbody>
                            @foreach ($disks as $disk)
                                @php
                                    $dp = ($disk['total_gb'] ?? 0) > 0 ? round(($disk['used_gb'] ?? 0) / $disk['total_gb'] * 100, 1) : null;
                                @endphp
                                <tr>
                                    <td style="width:28%;" class="text-truncate">{{ $disk['label'] ?? '—' }}</td>
                                    <td style="width:22%;" class="text-muted small">{{ round(($disk['used_gb'] ?? 0), 1) }} / {{ round(($disk['total_gb'] ?? 0), 1) }} GB</td>
                                    <td>
                                        <div class="progress" style="height:5px;">
                                            <div class="progress-bar text-bg-{{ $level($dp) }}" style="width:{{ min(100, max(0, (float) ($dp ?? 0))) }}%;"></div>
                                        </div>
                                    </td>
                                    <td class="text-end small {{ $dp === null ? 'text-muted' : 'text-'.$level($dp) }}" style="width:14%;">{{ $dp === null ? '—' : $dp.'%' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- Network adapters --}}
            @if ($interfaces !== [])
                <div class="mt-3">
                    <div class="small fw-semibold text-muted text-uppercase mb-1" style="font-size:.68rem;letter-spacing:.05em;">
                        Network adapters
                        @if ($inOctets > 0 || $outOctets > 0)
                            <span class="fw-normal">— total in {{ number_format($inOctets / 1e6, 1) }} MB / out {{ number_format($outOctets / 1e6, 1) }} MB</span>
                        @endif
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-borderless align-middle mb-0">
                            <thead>
                                <tr class="small text-muted">
                                    <th>Adapter</th><th>Status</th><th>Speed</th><th class="text-end">In</th><th class="text-end">Out</th><th>MAC</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach (array_slice($interfaces, 0, 8) as $if)
                                @php
                                    $up = in_array(($if['operStatus'] ?? $if['oper_status'] ?? null), [1, '1', 'up'], true);
                                    $spd = ($if['speed'] ?? null);
                                    $spdH = $spd ? ($spd >= 1e9 ? round($spd / 1e9, 1).' Gbps' : round($spd / 1e6).' Mbps') : '—';
                                @endphp
                                <tr>
                                    <td class="text-truncate" style="max-width:18ch;">{{ $if['name'] ?? ('#'.$if['index']) }}</td>
                                    <td><span class="badge text-bg-{{ $up ? 'success' : 'secondary' }}">{{ $up ? 'Up' : 'Down' }}</span></td>
                                    <td class="small">{{ $spdH }}</td>
                                    <td class="text-end small">{{ (isset($if['inOctets']) || isset($if['in_octets'])) ? number_format(($if['inOctets'] ?? $if['in_octets'] ?? 0) / 1e6, 1).' MB' : '—' }}</td>
                                    <td class="text-end small">{{ (isset($if['outOctets']) || isset($if['out_octets'])) ? number_format(($if['outOctets'] ?? $if['out_octets'] ?? 0) / 1e6, 1).' MB' : '—' }}</td>
                                    <td class="small text-muted" style="font-family:ui-monospace,monospace;">{{ ($if['physAddress'] ?? '') !== '' && ($if['physAddress'] ?? null) !== null ? $if['physAddress'] : '—' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                        @if ($ifCount > 8)
                            <div class="small text-muted">{{ $ifCount - 8 }} more on the dashboard.</div>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Bandwidth: only when the account has a quota or recorded traffic --}}
            @if ((float) ($account->bandwidth_quota ?? 0) > 0 || $inOctets > 0 || $outOctets > 0)
                @php
                    $bwQuotaGb = (float) ($account->bandwidth_quota ?? 0);
                    $bwUsedGb = (float) ($account->bandwidth_used ?? 0);
                    $bwPct = $bwQuotaGb > 0 ? round($bwUsedGb / $bwQuotaGb * 100, 1) : null;
                @endphp
                <div class="mt-3">
                    <div class="small fw-semibold text-muted text-uppercase mb-1" style="font-size:.68rem;letter-spacing:.05em;">Bandwidth</div>
                    <div class="d-flex align-items-center gap-2">
                        <div class="flex-grow-1 progress" style="height:6px;">
                            <div class="progress-bar text-bg-{{ $level($bwPct) }}" style="width:{{ min(100, max(0, (float) ($bwPct ?? 0))) }}%;"></div>
                        </div>
                        <span class="small text-{{ $level($bwPct) }} fw-semibold" style="width:12ch;text-align:right;">
                            {{ number_format($bwUsedGb, 1) }} / {{ number_format($bwQuotaGb, 0) }} GB
                        </span>
                    </div>
                    @if ($inOctets > 0 || $outOctets > 0)
                        <div class="small text-muted mt-1">SNMP counters: in {{ number_format($inOctets / 1e9, 2) }} GB · out {{ number_format($outOctets / 1e9, 2) }} GB (since last counter reset)</div>
                    @endif
                </div>
            @endif
        @endif

        {{-- ── Poll target IP + interval — per-hosting-account selection (admin only) ── --}}
        @unless ($isClient)
        <div class="border rounded p-2 mt-3 bg-light">
            <form method="POST" action="{{ route('admin.snmp-monitor.host.update', $account) }}" class="row g-2 align-items-center mb-0">
                @csrf
                <label class="col-auto form-label small fw-semibold mb-0" for="snmp-host-{{ $account->id }}">
                    <i class="bi bi-crosshair me-1 text-muted"></i>Poll target IP
                </label>
                @if ($assignedIps->isEmpty())
                    <div class="col">
                        <span class="small text-muted">No IPs assigned to this account — assign one on the Edit page first.</span>
                    </div>
                @else
                    <div class="col-md-5">
                        <select name="host" id="snmp-host-{{ $account->id }}" class="form-select form-select-sm" aria-label="Poll target IP">
                            <option value="">Auto — prefer public subnet</option>
                            @foreach ($assignedIps as $ip)
                                <option value="{{ $ip->ip_address }}" @selected($target->host === $ip->ip_address)>
                                    {{ $ip->ip_address }} · {{ $ip->subnet?->name ?? $ip->subnet?->subnet_cidr }} ({{ $ip->subnet?->network_type }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <label class="col-auto form-label small fw-semibold mb-0" for="snmp-interval-{{ $account->id }}">
                        <i class="bi bi-stopwatch me-1 text-muted"></i>Interval
                    </label>
                    <div class="col-md-3">
                        @php
                            $intervalOptions = [
                                60 => 'Every 1 minute', 120 => 'Every 2 minutes', 300 => 'Every 5 minutes',
                                600 => 'Every 10 minutes', 900 => 'Every 15 minutes', 1800 => 'Every 30 minutes',
                                3600 => 'Every 1 hour',
                            ];
                        @endphp
                        <select name="poll_interval" id="snmp-interval-{{ $account->id }}" class="form-select form-select-sm" aria-label="Poll interval">
                            <option value="">Use product default</option>
                            @foreach ($intervalOptions as $seconds => $label)
                                <option value="{{ $seconds }}" @selected((int) $target->poll_interval === $seconds)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg me-1"></i>Save</button>
                    </div>
                @endif
            </form>
            @error('host')
                <div class="small text-danger mt-1"><i class="bi bi-exclamation-triangle me-1"></i>{{ $message }}</div>
            @enderror
            @error('poll_interval')
                <div class="small text-danger mt-1"><i class="bi bi-exclamation-triangle me-1"></i>{{ $message }}</div>
            @enderror
        </div>
        @endunless

        {{-- ── Refresh (admin only) ── --}}
        @unless ($isClient)
        <form method="POST" action="{{ route('admin.snmp-monitor.poll', $account) }}" class="mt-2 mb-0 text-end">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-clockwise me-1"></i> Refresh
            </button>
        </form>
        @endunless
    </div>
</div>
