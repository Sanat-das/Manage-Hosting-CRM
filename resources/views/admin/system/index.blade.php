@extends('adminlte::page')

@section('title', 'About & Updates')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">About & Updates</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active">About & Updates</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @php
        $appInfo = $appInfo ?? [];
        $check = $check ?? ['status' => 'unknown', 'message' => '', 'behind' => 0, 'commits' => [], 'diffStat' => null, 'localHash' => null, 'remoteHash' => null, 'branch' => null, 'remoteSanitized' => null, 'dirty' => null];
        $history = $history ?? collect();
        $allowedTabs = ['about', 'updates', 'changelog'];
        $requestTab = request()->query('tab');
        $activeTab = $activeTab ?? (is_string($requestTab) && in_array($requestTab, $allowedTabs, true) ? $requestTab : 'about');
        if (! in_array($activeTab, $allowedTabs, true)) {
            $activeTab = 'about';
        }
        $checkResult = session('check_result') ?? $check;
        $updateResult = session('update_result');
        // Normalize history to collection
        if (! $history instanceof \Illuminate\Support\Collection) {
            $history = collect($history);
        }
    @endphp

    {{-- Flash: success via x-adminlte-alert + fixed-bottom toast (mirrors settings) --}}
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible id="system-success-alert">{{ session('success') }}</x-adminlte-alert>
    @endif
    @if (session('success'))
        <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1080">
            <div id="system-save-toast" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body" id="system-save-toast-body">{{ session('success') }}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        </div>
    @endif

    @if (session('error'))
        <x-adminlte-alert theme="danger" dismissible id="system-error-alert">{{ session('error') }}</x-adminlte-alert>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if (session('check_result'))
        <x-adminlte-alert theme="info" dismissible id="system-check-alert">{{ is_array(session('check_result')) ? (session('check_result')['message'] ?? 'Check completed.') : session('check_result') }}</x-adminlte-alert>
    @endif

    @if (session('update_result'))
        @php $ur = session('update_result'); @endphp
        @if (is_array($ur))
            <div class="alert alert-{{ ($ur['status'] ?? '') === 'success' ? 'success' : 'warning' }} alert-dismissible fade show">
                {{ $ur['message'] ?? 'Update finished.' }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            @if (! empty($ur['output']))
                <div class="alert alert-secondary">
                    <strong>Output excerpt</strong>
                    <pre class="mb-0 mt-2 bg-dark text-light p-2 rounded overflow-auto" style="max-height: 260px; white-space: pre-wrap; word-break: break-word;"><code>{{ Str::limit((string) $ur['output'], 20000) }}</code></pre>
                </div>
            @endif
        @endif
    @endif

    {{-- Tabs: Bootstrap nav-pills --}}
    <ul class="nav nav-pills mb-3 g-3" id="system-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link @if ($activeTab === 'about') active @endif" id="tab-about" data-bs-toggle="tab" data-bs-target="#pane-about" type="button" role="tab" aria-controls="pane-about" aria-selected="{{ $activeTab === 'about' ? 'true' : 'false' }}">
                <i class="bi bi-info-circle me-1"></i> About
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link @if ($activeTab === 'updates') active @endif" id="tab-updates" data-bs-toggle="tab" data-bs-target="#pane-updates" type="button" role="tab" aria-controls="pane-updates" aria-selected="{{ $activeTab === 'updates' ? 'true' : 'false' }}">
                <i class="bi bi-cloud-arrow-down me-1"></i> Updates
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link @if ($activeTab === 'changelog') active @endif" id="tab-changelog" data-bs-toggle="tab" data-bs-target="#pane-changelog" type="button" role="tab" aria-controls="pane-changelog" aria-selected="{{ $activeTab === 'changelog' ? 'true' : 'false' }}">
                <i class="bi bi-journal-text me-1"></i> Changelog
            </button>
        </li>
    </ul>

    <div class="tab-content">
        {{-- Pane: About --}}
        <div class="tab-pane fade @if ($activeTab === 'about') show active @endif" id="pane-about" role="tabpanel" aria-labelledby="tab-about">
            <div class="row g-3 mb-3">
                {{-- Application --}}
                <div class="col-lg-6">
                    <x-adminlte-card icon="bi bi-app-indicator" title="Application">
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless align-middle mb-0">
                                <tbody>
                                    <tr>
                                        <th class="text-muted small" style="width: 40%">Name</th>
                                        <td>{{ $appInfo['app']['name'] ?? config('app.name') }}</td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted small">Version</th>
                                        <td>
                                            <code>{{ $appInfo['version'] ?? 'dev' }}</code>
                                            @if (! empty($appInfo['git']['short']))
                                                <span class="text-muted small ms-1">({{ $appInfo['git']['short'] }})</span>
                                                <button type="button" class="btn btn-sm btn-outline-secondary ms-2 py-0 px-1 copy-btn" data-copy="{{ $appInfo['git']['commit'] ?? $appInfo['git']['short'] }}" aria-label="Copy commit hash"><i class="bi bi-clipboard"></i></button>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted small">Environment</th>
                                        <td>
                                            <span class="badge text-bg-{{ ($appInfo['app']['env'] ?? 'production') === 'production' ? 'success' : 'warning' }}">{{ $appInfo['app']['env'] ?? config('app.env') }}</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted small">Debug</th>
                                        <td>
                                            @if (! empty($appInfo['app']['debug']))
                                                <span class="badge text-bg-warning">ON</span>
                                            @else
                                                <span class="badge text-bg-success">OFF</span>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted small">URL</th>
                                        <td class="text-break">{{ $appInfo['app']['url'] ?? config('app.url') }}</td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted small">Timezone</th>
                                        <td>{{ $appInfo['app']['timezone'] ?? config('app.timezone') }}</td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted small">Installed</th>
                                        <td>
                                            @if (! empty($appInfo['app']['installed']))
                                                <span class="badge text-bg-success">Installed</span>
                                                @if (! empty($appInfo['app']['installedAt']))
                                                    <span class="small text-muted ms-1">{{ \Illuminate\Support\Carbon::parse($appInfo['app']['installedAt'])->format('M j, Y H:i') }}</span>
                                                @endif
                                            @else
                                                <span class="badge text-bg-secondary">Not installed</span>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted small">Maintenance</th>
                                        <td>
                                            @if (! empty($appInfo['app']['maintenance']))
                                                <span class="badge text-bg-danger">Maintenance ON</span>
                                            @else
                                                <span class="badge text-bg-success">Live</span>
                                            @endif
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </x-adminlte-card>
                </div>

                {{-- Framework --}}
                <div class="col-lg-6">
                    <x-adminlte-card icon="bi bi-braces" title="Framework">
                        @php
                            $fw = $appInfo['framework'] ?? [];
                            $packages = $fw['packages'] ?? [];
                            $composerHash = $fw['composerHash'] ?? null;
                        @endphp
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless align-middle mb-0">
                                <tbody>
                                    <tr>
                                        <th class="text-muted small" style="width: 40%">Laravel</th>
                                        <td><code>{{ $fw['laravel'] ?? 'unknown' }}</code></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted small">PHP</th>
                                        <td><code>{{ $fw['php'] ?? PHP_VERSION }}</code></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted small">Composer lock</th>
                                        <td>
                                            @if ($composerHash)
                                                <code>{{ Str::limit($composerHash, 12, '') }}</code>
                                                <button type="button" class="btn btn-sm btn-outline-secondary ms-2 py-0 px-1 copy-btn" data-copy="{{ $composerHash }}" aria-label="Copy composer hash"><i class="bi bi-clipboard"></i></button>
                                            @else
                                                <span class="text-muted small">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        @if (! empty($packages))
                            <hr class="my-3">
                            <div class="small text-muted mb-1">Key packages</div>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th class="small text-muted">Package</th>
                                            <th class="small text-muted">Version</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($packages as $name => $ver)
                                            <tr>
                                                <td><code class="small">{{ $name }}</code></td>
                                                <td class="small">{{ $ver }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </x-adminlte-card>
                </div>
            </div>

            <div class="row g-3 mb-3">
                {{-- Server Health (preflight) --}}
                <div class="col-lg-6">
                    <x-adminlte-card icon="bi bi-heart-pulse" title="Server Health">
                        @php $preflight = $appInfo['health']['preflight'] ?? []; @endphp
                        @if (empty($preflight))
                            <div class="text-muted small py-3 text-center">No preflight data available.</div>
                        @else
                            <div class="table-responsive">
                                <table class="table table-sm table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th class="small text-muted">Check</th>
                                            <th class="small text-muted">Status</th>
                                            <th class="small text-muted">Detail</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($preflight as $row)
                                            <tr>
                                                <td class="small">{{ $row['name'] ?? '—' }}</td>
                                                <td>
                                                    @if (! empty($row['passed']))
                                                        <span class="badge text-bg-success">Pass</span>
                                                    @else
                                                        <span class="badge text-bg-danger">Fail</span>
                                                    @endif
                                                </td>
                                                <td class="small text-muted text-break">{{ $row['detail'] ?? '' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </x-adminlte-card>
                </div>

                {{-- Scheduler --}}
                <div class="col-lg-6">
                    <x-adminlte-card icon="bi bi-alarm" title="Scheduler">
                        @php
                            $sched = $appInfo['health']['scheduler'] ?? [];
                            $lastTick = $sched['lastTickAt'] ?? null;
                            $healthy = $sched['schedulerIsHealthy'] ?? false;
                            $staleAfter = $sched['staleAfter'] ?? 600;
                            $paused = $sched['paused'] ?? false;
                        @endphp
                        <div class="d-flex align-items-center gap-2 mb-3">
                            @if ($healthy)
                                <span class="badge text-bg-success"><i class="bi bi-check-circle me-1"></i> Healthy</span>
                            @else
                                <span class="badge text-bg-danger"><i class="bi bi-exclamation-octagon me-1"></i> Not healthy</span>
                            @endif
                            @if ($paused)
                                <span class="badge text-bg-warning"><i class="bi bi-pause-circle me-1"></i> Paused</span>
                            @endif
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless align-middle mb-0">
                                <tbody>
                                    <tr>
                                        <th class="text-muted small" style="width: 40%">Last tick</th>
                                        <td class="small">
                                            @if ($lastTick)
                                                {{ \Illuminate\Support\Carbon::parse($lastTick)->format('M j, Y H:i:s') }}
                                                <span class="text-muted">({{ \Illuminate\Support\Carbon::parse($lastTick)->diffForHumans() }})</span>
                                            @else
                                                <span class="text-muted">Never</span>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted small">Stale after</th>
                                        <td class="small">{{ (int) $staleAfter }} seconds ({{ (int) ($staleAfter / 60) }} min)</td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted small">Paused</th>
                                        <td class="small">{{ $paused ? 'Yes' : 'No' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        @if (! $healthy)
                            <div class="alert alert-warning mt-3 mb-0 small">
                                Every minute, a scheduler tick must run: <code>php artisan schedule:run</code>
                            </div>
                        @endif
                    </x-adminlte-card>
                </div>
            </div>

            {{-- Git --}}
            <x-adminlte-card icon="bi bi-git" title="Git">
                @php $git = $appInfo['git'] ?? []; @endphp
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless align-middle mb-0">
                                <tbody>
                                    <tr>
                                        <th class="text-muted small" style="width: 40%">Branch</th>
                                        <td>
                                            @if (! empty($git['branch']))
                                                <code>{{ $git['branch'] }}</code>
                                            @else
                                                <span class="text-muted small">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted small">Commit</th>
                                        <td>
                                            @if (! empty($git['commit']))
                                                <code>{{ Str::limit($git['commit'], 12, '') }}</code>
                                                <span class="text-muted small">({{ $git['short'] ?? Str::limit($git['commit'], 7, '') }})</span>
                                                <button type="button" class="btn btn-sm btn-outline-secondary ms-2 py-0 px-1 copy-btn" data-copy="{{ $git['commit'] }}" aria-label="Copy full commit"><i class="bi bi-clipboard"></i></button>
                                            @else
                                                <span class="text-muted small">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted small">Date</th>
                                        <td class="small">{{ $git['date'] ?? '—' }}</td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted small">Dirty</th>
                                        <td>
                                            @if ($git['dirty'] === true)
                                                <span class="badge text-bg-warning">Dirty</span>
                                            @elseif ($git['dirty'] === false)
                                                <span class="badge text-bg-success">Clean</span>
                                            @else
                                                <span class="badge text-bg-secondary">Unknown</span>
                                            @endif
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless align-middle mb-0">
                                <tbody>
                                    <tr>
                                        <th class="text-muted small" style="width: 40%">Remote</th>
                                        <td class="small text-break">
                                            @if (! empty($git['remote']))
                                                <code>{{ $git['remote'] }}</code>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted small">Ahead / Behind</th>
                                        <td class="small">
                                            @if (isset($git['ahead']) || isset($git['behind']))
                                                <span class="badge text-bg-secondary">Ahead {{ $git['ahead'] ?? 0 }}</span>
                                                <span class="badge text-bg-{{ ($git['behind'] ?? 0) > 0 ? 'warning' : 'success' }} ms-1">Behind {{ $git['behind'] ?? 0 }}</span>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted small">Status</th>
                                        <td>
                                            @if (($git['behind'] ?? 0) > 0)
                                                <span class="badge text-bg-warning">{{ $git['behind'] }} behind</span>
                                            @elseif (($git['behind'] ?? null) === 0)
                                                <span class="badge text-bg-success">Up to date</span>
                                            @else
                                                <span class="badge text-bg-secondary">Unknown</span>
                                            @endif
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </x-adminlte-card>
        </div>

        {{-- Pane: Updates --}}
        <div class="tab-pane fade @if ($activeTab === 'updates') show active @endif" id="pane-updates" role="tabpanel" aria-labelledby="tab-updates">
            @php
                $effectiveCheck = $checkResult ?? $check;
                $status = $effectiveCheck['status'] ?? 'unknown';
                $behind = (int) ($effectiveCheck['behind'] ?? 0);
                $branch = $effectiveCheck['branch'] ?? ($appInfo['git']['branch'] ?? null);
                $remoteSanitized = $effectiveCheck['remoteSanitized'] ?? ($appInfo['git']['remote'] ?? null);
                $dirty = $effectiveCheck['dirty'] ?? ($appInfo['git']['dirty'] ?? null);
                $commits = $effectiveCheck['commits'] ?? [];
                $diffStat = $effectiveCheck['diffStat'] ?? null;
                $statusBadge = match($status) {
                    'up_to_date' => 'success',
                    'behind' => 'warning',
                    'dirty' => 'warning',
                    'no_git' => 'secondary',
                    'no_remote' => 'secondary',
                    'fetch_failed' => 'danger',
                    default => 'secondary',
                };
            @endphp

            <x-adminlte-card icon="bi bi-cloud-arrow-down" title="Update Status">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <span class="badge text-bg-{{ $statusBadge }}">{{ $status }}</span>
                    @if ($behind > 0)
                        <span class="badge text-bg-warning">{{ $behind }} commit(s) behind</span>
                    @endif
                    @if ($branch)
                        <span class="small text-muted">Branch: <code>{{ $branch }}</code></span>
                    @endif
                    @if ($remoteSanitized)
                        <span class="small text-muted text-break">Remote: <code>{{ $remoteSanitized }}</code></span>
                    @endif
                    @if ($dirty === true)
                        <span class="badge text-bg-warning">Dirty working tree</span>
                    @elseif ($dirty === false)
                        <span class="badge text-bg-success">Clean</span>
                    @endif
                </div>

                @if (! empty($effectiveCheck['message']))
                    <div class="alert alert-{{ $statusBadge === 'danger' ? 'danger' : ($statusBadge === 'warning' ? 'warning' : 'info') }} mb-3">
                        {{ $effectiveCheck['message'] }}
                    </div>
                @endif

                @if ($behind > 0 && ! empty($commits))
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="small text-muted">Commit</th>
                                    <th class="small text-muted">Message</th>
                                    <th class="small text-muted">Author</th>
                                    <th class="small text-muted">Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($commits as $c)
                                    <tr>
                                        <td><code class="small">{{ $c['short'] ?? Str::limit($c['hash'] ?? '', 7, '') }}</code> <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 copy-btn" data-copy="{{ $c['hash'] ?? '' }}" aria-label="Copy hash"><i class="bi bi-clipboard"></i></button></td>
                                        <td class="small text-break">{{ $c['message'] ?? '' }}</td>
                                        <td class="small text-muted">{{ $c['author'] ?? '' }}</td>
                                        <td class="small text-muted">{{ $c['date'] ?? '' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($diffStat)
                        <div class="mb-3">
                            <div class="small text-muted mb-1">Diff stat</div>
                            <pre class="mb-0 bg-dark text-light p-2 rounded overflow-auto" style="max-height: 200px; white-space: pre-wrap; word-break: break-word;"><code>{{ $diffStat }}</code></pre>
                        </div>
                    @endif
                @endif

                <div class="d-flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('admin.system.check') }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="bi bi-arrow-repeat me-1"></i> Check for updates
                        </button>
                    </form>
                    @if ($behind > 0 && $status === 'behind')
                        <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#confirm-update-modal">
                            <i class="bi bi-cloud-arrow-up me-1"></i> Update now
                        </button>
                    @endif
                </div>

                @if (in_array($status, ['no_git', 'no_remote'], true))
                    <div class="alert alert-secondary mt-3 mb-0">
                        <h6 class="alert-heading small"><i class="bi bi-info-circle me-1"></i> Update instructions (non-git)</h6>
                        <p class="small mb-2">This installation was not deployed via git. To update:</p>
                        <ol class="small mb-2">
                            <li>Download the latest ZIP from GitHub.</li>
                            <li>Replace files (keep <code>.env</code>, <code>storage/</code>, <code>install.lock</code>).</li>
                            <li>Run <code>composer install --no-dev --optimize-autoloader &amp;&amp; php artisan migrate --force &amp;&amp; php artisan optimize:clear</code>.</li>
                        </ol>
                        @if ($status === 'no_remote')
                            <div class="small text-muted">Add remote: <code>git remote add origin https://github.com/Sanat-das/Manage-Hosting-CRM.git</code></div>
                        @endif
                    </div>
                @endif
            </x-adminlte-card>

            {{-- Update confirm modal --}}
            @if ($behind > 0 && $status === 'behind')
                @php
                    $fromShort = isset($effectiveCheck['localHash']) && $effectiveCheck['localHash'] ? Str::limit($effectiveCheck['localHash'], 7, '') : ($appInfo['git']['short'] ?? 'unknown');
                    $toShort = isset($effectiveCheck['remoteHash']) && $effectiveCheck['remoteHash'] ? Str::limit($effectiveCheck['remoteHash'], 7, '') : 'origin/main';
                    $confirmMsg = "Update " . $behind . " commit(s) " . $fromShort . " → " . $toShort . "? Site will enter maintenance for ~1–2 minutes.";
                @endphp
                <x-adminlte.partials.confirm-modal
                    id="confirm-update-modal"
                    title="Confirm update"
                    :message="$confirmMsg"
                    method="POST"
                    :action="route('admin.system.update')"
                    confirm-label="Update now"
                    confirm-theme="warning"
                />
            @else
                {{-- Render hidden modal placeholder to keep DOM stable when no update available --}}
                <x-adminlte.partials.confirm-modal
                    id="confirm-update-modal"
                    title="Confirm update"
                    message="No updates available."
                    method="POST"
                    :action="route('admin.system.update')"
                    confirm-label="Update now"
                    confirm-theme="warning"
                />
            @endif

            {{-- History --}}
            <x-adminlte-card icon="bi bi-clock-history" title="Update History">
                @if ($history->isEmpty())
                    <div class="text-muted small py-3 text-center">No update history yet.</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="small text-muted">Started</th>
                                    <th class="small text-muted">By</th>
                                    <th class="small text-muted">From → To</th>
                                    <th class="small text-muted">Commits</th>
                                    <th class="small text-muted">Status</th>
                                    <th class="small text-muted">Duration</th>
                                    <th class="small text-muted">Output</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($history as $row)
                                    @php
                                        $meta = is_string($row->metadata ?? null) ? json_decode($row->metadata, true) : ($row->metadata ?? []);
                                        if (! is_array($meta)) $meta = [];
                                        $props = is_string($row->properties ?? null) ? json_decode($row->properties, true) : ($row->properties ?? []);
                                        if (! is_array($props)) $props = [];
                                        $merged = array_merge($props, $meta);
                                        $from = $merged['from'] ?? null;
                                        $to = $merged['to'] ?? null;
                                        $behindHist = $merged['behind'] ?? null;
                                        $statusHist = $merged['status'] ?? ($row->event ?? 'unknown');
                                        $duration = $merged['duration_ms'] ?? null;
                                        $outputExcerpt = $merged['output_excerpt'] ?? $merged['output'] ?? '';
                                        $triggeredBy = $merged['triggered_by'] ?? $row->user_id ?? '—';
                                        $collapseId = 'history-output-' . ($row->id ?? $loop->index);
                                    @endphp
                                    <tr>
                                        <td class="small text-muted" style="white-space: nowrap;">{{ $row->created_at ? \Illuminate\Support\Carbon::parse($row->created_at)->format('M j, H:i:s') : '—' }}</td>
                                        <td class="small">{{ $triggeredBy }}</td>
                                        <td class="small">
                                            @if ($from || $to)
                                                <code>{{ $from ? Str::limit($from, 7, '') : '?' }}</code> → <code>{{ $to ? Str::limit($to, 7, '') : '?' }}</code>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="small">{{ $behindHist !== null ? $behindHist : '—' }}</td>
                                        <td>
                                            @if ($statusHist === 'success')
                                                <span class="badge text-bg-success">Success</span>
                                            @elseif (in_array($statusHist, ['failed', 'fetch_failed'], true))
                                                <span class="badge text-bg-danger">{{ $statusHist }}</span>
                                            @else
                                                <span class="badge text-bg-secondary">{{ $statusHist }}</span>
                                            @endif
                                        </td>
                                        <td class="small">{{ $duration !== null ? number_format((int) $duration) . ' ms' : '—' }}</td>
                                        <td class="small text-muted">
                                            @if (trim((string) $outputExcerpt) !== '')
                                                {{ Str::limit((string) $outputExcerpt, 500) }}
                                                @if (mb_strlen((string) $outputExcerpt) > 500)
                                                    <a class="ms-1" data-bs-toggle="collapse" href="#{{ $collapseId }}" role="button" aria-expanded="false" aria-controls="{{ $collapseId }}">Show</a>
                                                    <div class="collapse mt-1" id="{{ $collapseId }}">
                                                        <pre class="mb-0 bg-dark text-light p-2 rounded overflow-auto" style="max-height: 200px; white-space: pre-wrap; word-break: break-word;"><code>{{ Str::limit((string) $outputExcerpt, 20000) }}</code></pre>
                                                    </div>
                                                @endif
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-adminlte-card>
        </div>

        {{-- Pane: Changelog --}}
        <div class="tab-pane fade @if ($activeTab === 'changelog') show active @endif" id="pane-changelog" role="tabpanel" aria-labelledby="tab-changelog">
            <x-adminlte-card icon="bi bi-journal-text" title="Changelog">
                @php $changelog = $appInfo['changelog'] ?? ''; @endphp
                @if (trim((string) $changelog) === '')
                    <div class="text-muted small py-3 text-center">No CHANGELOG.md found.</div>
                @else
                    <div class="overflow-auto bg-body-tertiary p-3 rounded" style="max-height: 400px;">
                        <pre class="mb-0" style="white-space: pre-wrap; word-break: break-word; font-size: 0.875rem;"><code>{{ $changelog }}</code></pre>
                    </div>
                    <div class="small text-muted mt-2">
                        Showing first 80 lines. Full file: <code>CHANGELOG.md</code>
                    </div>
                @endif
            </x-adminlte-card>
        </div>
    </div>
@stop

@push('js')
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Toast for success (mirrors settings)
    var toastEl = document.getElementById('system-save-toast');
    if (toastEl && window.bootstrap && window.bootstrap.Toast) {
        var toast = window.bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 4000 });
        toast.show();
    }

    // Tab persistence: push ?tab= to URL on shown (mirrors settings tab JS)
    var tabsNav = document.getElementById('system-tabs');
    if (tabsNav) {
        tabsNav.querySelectorAll('[data-bs-toggle="tab"]').forEach(function (tab) {
            tab.addEventListener('shown.bs.tab', function () {
                var target = tab.getAttribute('data-bs-target') || '';
                var name = target.replace('#pane-', '');
                if (!name) return;
                var url = new URL(window.location.href);
                url.searchParams.set('tab', name);
                history.replaceState(null, '', url);
            });
        });
    }

    // Copy buttons for commit hashes / composer hash
    document.querySelectorAll('.copy-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var text = btn.getAttribute('data-copy') || '';
            if (!text) return;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    var orig = btn.innerHTML;
                    btn.innerHTML = '<i class="bi bi-check-lg"></i>';
                    setTimeout(function () { btn.innerHTML = orig; }, 1200);
                });
            } else {
                // Fallback
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); } catch (e) {}
                document.body.removeChild(ta);
                var orig2 = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-check-lg"></i>';
                setTimeout(function () { btn.innerHTML = orig2; }, 1200);
            }
        });
    });
});
</script>
@endpush
