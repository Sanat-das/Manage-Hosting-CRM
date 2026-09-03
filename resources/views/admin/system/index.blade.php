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

            <x-adminlte-card icon="bi bi-cloud-arrow-down" title="Software Updates">
                {{-- Status banner --}}
                @if ($status === 'up_to_date')
                    <div class="d-flex align-items-center gap-3 mb-4 p-3 bg-success bg-opacity-10 rounded border border-success border-opacity-25">
                        <i class="bi bi-check-circle-fill text-success fs-3 flex-shrink-0"></i>
                        <div>
                            <div class="fw-semibold">Your application is up to date</div>
                            <div class="small text-muted">No updates available right now.</div>
                        </div>
                    </div>
                @elseif (in_array($status, ['behind', 'no_git'], true) && $behind > 0)
                    <div class="d-flex align-items-center gap-3 mb-4 p-3 bg-warning bg-opacity-10 rounded border border-warning border-opacity-25">
                        <i class="bi bi-cloud-arrow-up-fill text-warning fs-3 flex-shrink-0"></i>
                        <div>
                            <div class="fw-semibold">An update is available</div>
                            <div class="small text-muted">{{ $behind }} improvement{{ $behind > 1 ? 's are' : ' is' }} ready to install.</div>
                        </div>
                    </div>
                @elseif ($status === 'fetch_failed')
                    <div class="d-flex align-items-center gap-3 mb-4 p-3 bg-danger bg-opacity-10 rounded border border-danger border-opacity-25">
                        <i class="bi bi-exclamation-circle-fill text-danger fs-3 flex-shrink-0"></i>
                        <div>
                            <div class="fw-semibold">Could not check for updates</div>
                            <div class="small text-muted">Network error — please try again in a moment.</div>
                        </div>
                    </div>
                @else
                    <div class="d-flex align-items-center gap-3 mb-4 p-3 bg-secondary bg-opacity-10 rounded border border-secondary border-opacity-25">
                        <i class="bi bi-arrow-repeat text-secondary fs-3 flex-shrink-0"></i>
                        <div>
                            <div class="fw-semibold">Check for updates</div>
                            <div class="small text-muted">Click the button below to see if a newer version is available.</div>
                        </div>
                    </div>
                @endif

                {{-- What's new --}}
                @if ($behind > 0 && ! empty($commits))
                    <div class="mb-4">
                        <div class="small fw-semibold text-uppercase text-muted letter-spacing-1 mb-2">What's new</div>
                        <ul class="list-unstyled mb-0">
                            @foreach ($commits as $c)
                                @php
                                    $msg = preg_replace('/^(feat|fix|chore|test|docs|refactor|style|perf|ci|build|revert)(\([^)]+\))?\!?:\s*/i', '', $c['message'] ?? '');
                                    $msg = Str::ucfirst(Str::limit(trim($msg), 120));
                                @endphp
                                <li class="d-flex align-items-start gap-2 mb-2 small">
                                    <i class="bi bi-check2-circle text-success mt-1 flex-shrink-0"></i>
                                    <span>{{ $msg }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Action buttons --}}
                <div class="d-flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('admin.system.check') }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-repeat me-1"></i> Check for updates
                        </button>
                    </form>
                    @if ($behind > 0 && in_array($status, ['behind', 'no_git'], true))
                        <button type="button" class="btn btn-warning" id="start-update-btn"
                            data-bs-toggle="modal" data-bs-target="#update-progress-modal"
                            data-behind="{{ $behind }}"
                            data-from="{{ Str::limit($effectiveCheck['localHash'] ?? '', 7, '') }}"
                            data-to="{{ Str::limit($effectiveCheck['remoteHash'] ?? '', 7, '') }}">
                            <i class="bi bi-cloud-arrow-up me-1"></i> Install Update
                        </button>
                    @endif
                </div>

                @if ($status === 'no_remote')
                    <div class="alert alert-secondary mt-3 mb-0 small">
                        <i class="bi bi-info-circle me-1"></i>
                        Automatic updates are not available for this installation. Please contact your system administrator.
                    </div>
                @endif
            </x-adminlte-card>

            {{-- Update progress modal --}}
            @php
                $fromShort = isset($effectiveCheck['localHash']) && $effectiveCheck['localHash'] ? Str::limit($effectiveCheck['localHash'], 7, '') : ($appInfo['git']['short'] ?? 'unknown');
                $toShort   = isset($effectiveCheck['remoteHash']) && $effectiveCheck['remoteHash'] ? Str::limit($effectiveCheck['remoteHash'], 7, '') : 'latest';
            @endphp
            <div class="modal fade" id="update-progress-modal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="update-progress-modal-label" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="update-progress-modal-label"><i class="bi bi-cloud-arrow-up me-2"></i>Apply Update</h5>
                        </div>
                        <div class="modal-body">
                            {{-- Confirm area (shown before update starts) --}}
                            <div id="update-confirm-area">
                                <p class="mb-1">Apply <strong>{{ $behind }} commit(s)</strong>? <span class="text-muted small">({{ $fromShort }} → {{ $toShort }})</span></p>
                                <ul class="small text-muted mb-0">
                                    <li>Site will be in maintenance for ~1–2 minutes.</li>
                                    <li>Your existing data will <strong>not</strong> be modified or deleted.</li>
                                    <li>You can roll back immediately after if needed.</li>
                                </ul>
                            </div>

                            {{-- Progress area (shown while running) --}}
                            <div id="update-progress-area" class="d-none">
                                <div class="progress mb-3" style="height:8px;" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                                    <div class="progress-bar progress-bar-striped progress-bar-animated" id="update-progress-bar" style="width:0%"></div>
                                </div>
                                <div class="text-center small text-muted mb-3" id="update-status-text">Preparing...</div>
                                <div class="list-group list-group-flush small">
                                    <div class="list-group-item d-flex align-items-center gap-2 py-2" id="upstep-fetch">
                                        <span class="step-icon"><i class="bi bi-circle text-muted"></i></span>
                                        <span>Fetch latest changes</span>
                                    </div>
                                    <div class="list-group-item d-flex align-items-center gap-2 py-2" id="upstep-pull">
                                        <span class="step-icon"><i class="bi bi-circle text-muted"></i></span>
                                        <span>Apply update</span>
                                    </div>
                                    <div class="list-group-item d-flex align-items-center gap-2 py-2" id="upstep-composer">
                                        <span class="step-icon"><i class="bi bi-circle text-muted"></i></span>
                                        <span>Install dependencies</span>
                                    </div>
                                    <div class="list-group-item d-flex align-items-center gap-2 py-2" id="upstep-migrate">
                                        <span class="step-icon"><i class="bi bi-circle text-muted"></i></span>
                                        <span>Update database schema <span class="text-muted">(existing data preserved)</span></span>
                                    </div>
                                    <div class="list-group-item d-flex align-items-center gap-2 py-2" id="upstep-cache">
                                        <span class="step-icon"><i class="bi bi-circle text-muted"></i></span>
                                        <span>Clear cache &amp; bring site back online</span>
                                    </div>
                                </div>
                            </div>

                            {{-- Result area (shown after completion) --}}
                            <div id="update-result-area" class="d-none mt-3">
                                <div id="update-result-message" class="alert mb-0"></div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" id="update-cancel-btn" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-warning" id="begin-update-btn">
                                <i class="bi bi-cloud-arrow-up me-1"></i> Start Update
                            </button>
                            <button type="button" class="btn btn-secondary d-none" id="update-close-btn" data-bs-dismiss="modal">Close</button>
                            <div id="update-rollback-area" class="d-none">
                                <form method="POST" action="{{ route('admin.system.rollback') }}" id="rollback-after-update-form">
                                    @csrf
                                    <input type="hidden" name="from_hash" id="rollback-target-hash">
                                    <button type="submit" class="btn btn-outline-warning btn-sm">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i> Rollback
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- History --}}
            <x-adminlte-card icon="bi bi-clock-history" title="Update History">
                @if ($history->isEmpty())
                    <div class="text-muted small py-3 text-center">No updates have been applied yet.</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="small text-muted">Date</th>
                                    <th class="small text-muted">Result</th>
                                    <th class="small text-muted">Details</th>
                                    <th class="small text-muted">Actions</th>
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
                                        $statusHist = $merged['status'] ?? ($row->event ?? 'unknown');
                                        $outputExcerpt = $merged['output_excerpt'] ?? $merged['output'] ?? '';
                                        $collapseId = 'history-output-' . ($row->id ?? $loop->index);
                                    @endphp
                                    <tr>
                                        <td class="small text-muted" style="white-space: nowrap;">
                                            {{ $row->created_at ? \Illuminate\Support\Carbon::parse($row->created_at)->format('M j, Y g:i A') : '—' }}
                                        </td>
                                        <td>
                                            @if ($statusHist === 'success')
                                                <span class="badge text-bg-success"><i class="bi bi-check-lg me-1"></i>Success</span>
                                            @elseif (in_array($statusHist, ['failed', 'fetch_failed'], true))
                                                <span class="badge text-bg-danger"><i class="bi bi-x-lg me-1"></i>Failed</span>
                                            @else
                                                <span class="badge text-bg-secondary">{{ $statusHist }}</span>
                                            @endif
                                        </td>
                                        <td class="small text-muted" style="max-width: 260px;">
                                            @if (trim((string) $outputExcerpt) !== '')
                                                <a class="text-decoration-none" data-bs-toggle="collapse" href="#{{ $collapseId }}" role="button" aria-expanded="false">
                                                    <i class="bi bi-terminal me-1"></i>View log
                                                </a>
                                                <div class="collapse mt-1" id="{{ $collapseId }}">
                                                    <pre class="mb-0 bg-dark text-light p-2 rounded overflow-auto" style="max-height: 200px; font-size: 0.72rem; white-space: pre-wrap; word-break: break-word;"><code>{{ Str::limit((string) $outputExcerpt, 20000) }}</code></pre>
                                                </div>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($statusHist === 'success' && ! empty($from))
                                                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#rollback-confirm-modal" data-hash="{{ $from }}" data-short="{{ $from ? Str::limit($from, 7, '') : '?' }}">
                                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Rollback
                                                </button>
                                            @else
                                                <span class="text-muted">—</span>
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

            {{-- Rollback confirm modal --}}
            <div class="modal fade" id="rollback-confirm-modal" tabindex="-1" aria-labelledby="rollback-confirm-label" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="rollback-confirm-label"><i class="bi bi-arrow-counterclockwise me-2"></i>Confirm Rollback</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p>Roll back code to <strong id="rollback-hash-display">—</strong>?</p>
                            <div class="alert alert-warning small mb-0">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                Code will revert. <strong>Database schema changes are not reversed.</strong> If the rollback causes database errors, run <code>php artisan migrate:rollback</code> via SSH.
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <form method="POST" action="{{ route('admin.system.rollback') }}" id="rollback-history-form">
                                @csrf
                                <input type="hidden" name="from_hash" id="rollback-history-hash">
                                <button type="submit" class="btn btn-warning">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i> Rollback
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
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
    // Toast for success
    var toastEl = document.getElementById('system-save-toast');
    if (toastEl && window.bootstrap && window.bootstrap.Toast) {
        bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 4000 }).show();
    }

    // Tab persistence
    var tabsNav = document.getElementById('system-tabs');
    if (tabsNav) {
        tabsNav.querySelectorAll('[data-bs-toggle="tab"]').forEach(function (tab) {
            tab.addEventListener('shown.bs.tab', function () {
                var name = (tab.getAttribute('data-bs-target') || '').replace('#pane-', '');
                if (!name) return;
                var url = new URL(window.location.href);
                url.searchParams.set('tab', name);
                history.replaceState(null, '', url);
            });
        });
    }

    // Copy buttons
    document.querySelectorAll('.copy-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var text = btn.getAttribute('data-copy') || '';
            if (!text) return;
            var orig = btn.innerHTML;
            var done = function () { btn.innerHTML = '<i class="bi bi-check-lg"></i>'; setTimeout(function () { btn.innerHTML = orig; }, 1200); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done);
            } else {
                var ta = document.createElement('textarea');
                ta.value = text; ta.style.cssText = 'position:fixed;opacity:0';
                document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy'); } catch (e) {}
                document.body.removeChild(ta); done();
            }
        });
    });

    // ---------------------------------------------------------------
    // Update progress modal
    // ---------------------------------------------------------------
    var startBtn = document.getElementById('start-update-btn');
    var progressModal = document.getElementById('update-progress-modal');
    if (startBtn && progressModal) {
        // modal opened via data-bs-toggle on the button
        var updateUrl = '{{ route('admin.system.update') }}';
        var csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

        document.getElementById('begin-update-btn').addEventListener('click', function () {
            // Switch from confirm area to progress area
            document.getElementById('update-confirm-area').classList.add('d-none');
            document.getElementById('update-progress-area').classList.remove('d-none');
            document.getElementById('begin-update-btn').classList.add('d-none');
            document.getElementById('update-cancel-btn').classList.add('d-none');
            runUpdate();
        });

        var STEPS = ['fetch', 'pull', 'composer', 'migrate', 'cache'];
        var STEP_MAP = { fetch: 'fetch', maintenance: null, pull: 'pull', composer: 'composer', migrate: 'migrate', cache: 'cache' };
        var lastStep = null;

        function stepEl(id) { return document.getElementById('upstep-' + id); }
        function setStep(id, state) {
            var el = stepEl(id); if (!el) return;
            var icon = el.querySelector('.step-icon');
            el.classList.remove('list-group-item-success', 'list-group-item-danger');
            if (state === 'running') {
                icon.innerHTML = '<span class="spinner-border spinner-border-sm text-primary" role="status"></span>';
            } else if (state === 'done') {
                icon.innerHTML = '<i class="bi bi-check-circle-fill text-success"></i>';
                el.classList.add('list-group-item-success');
            } else if (state === 'error') {
                icon.innerHTML = '<i class="bi bi-x-circle-fill text-danger"></i>';
                el.classList.add('list-group-item-danger');
            } else {
                icon.innerHTML = '<i class="bi bi-circle text-muted"></i>';
            }
        }

        function setProgress(pct) {
            var bar = document.getElementById('update-progress-bar');
            if (bar) { bar.style.width = pct + '%'; bar.setAttribute('aria-valuenow', pct); }
        }
        function setStatus(msg) {
            var el = document.getElementById('update-status-text'); if (el) el.textContent = msg;
        }

        function handleEvent(ev) {
            if (ev.progress !== undefined) setProgress(ev.progress);
            if (ev.message) setStatus(ev.message);

            var stepId = STEP_MAP[ev.step];
            var isError = ev.step === 'error';
            var isDone = ev.done;

            if (isError) {
                if (lastStep) setStep(lastStep, 'error');
                onFinished(ev, false); return;
            }

            if (isDone) {
                if (lastStep) setStep(lastStep, 'done');
                STEPS.forEach(function (id) { if (stepEl(id) && stepEl(id).querySelector('.bi-circle')) setStep(id, 'done'); });
                onFinished(ev, true); return;
            }

            if (stepId) {
                if (lastStep && lastStep !== stepId) setStep(lastStep, 'done');
                setStep(stepId, 'running');
                lastStep = stepId;
            }
        }

        function onFinished(ev, success) {
            var bar = document.getElementById('update-progress-bar');
            if (bar) {
                bar.classList.remove('progress-bar-animated', 'bg-warning');
                bar.classList.add(success ? 'bg-success' : 'bg-danger');
                bar.style.width = (success ? 100 : (ev.progress || 50)) + '%';
            }
            var resultArea = document.getElementById('update-result-area');
            var resultMsg = document.getElementById('update-result-message');
            if (resultArea) resultArea.classList.remove('d-none');
            if (resultMsg) {
                resultMsg.className = 'alert alert-' + (success ? 'success' : 'danger') + ' mb-0';
                resultMsg.textContent = (ev && ev.message) ? ev.message : (success ? 'Update complete.' : 'Update failed.');
            }
            document.getElementById('update-close-btn').classList.remove('d-none');
            if (success && ev && ev.from) {
                var hashInput = document.getElementById('rollback-target-hash');
                var rollbackArea = document.getElementById('update-rollback-area');
                if (hashInput) hashInput.value = ev.from;
                if (rollbackArea) rollbackArea.classList.remove('d-none');
            }
        }

        function runUpdate() {
            STEPS.forEach(function (id) { setStep(id, 'pending'); });
            lastStep = null; setProgress(5); setStatus('Preparing update...');

            if (typeof ReadableStream !== 'undefined') {
                fetch(updateUrl, {
                    method: 'POST',
                    headers: { 'Accept': 'text/event-stream', 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: '_token=' + encodeURIComponent(csrfToken)
                }).then(function (resp) {
                    var reader = resp.body.getReader();
                    var dec = new TextDecoder();
                    var buf = '';
                    function pump() {
                        reader.read().then(function (chunk) {
                            if (chunk.done) { return; }
                            buf += dec.decode(chunk.value, { stream: true });
                            var parts = buf.split('\n'); buf = parts.pop();
                            parts.forEach(function (line) {
                                if (line.startsWith('data: ')) { try { handleEvent(JSON.parse(line.slice(6))); } catch (e) {} }
                            });
                            pump();
                        }).catch(function () {
                            handleEvent({ step: 'error', message: 'Connection lost. Check Update History for status.', progress: 0, done: true, status: 'unknown' });
                        });
                    }
                    pump();
                }).catch(function () {
                    handleEvent({ step: 'error', message: 'Could not connect. Please refresh and check Update History.', progress: 0, done: true, status: 'unknown' });
                });
            } else {
                // Fallback: JSON POST for browsers without ReadableStream
                setStatus('Updating — please wait, this may take 1–2 minutes...');
                fetch(updateUrl, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: '_token=' + encodeURIComponent(csrfToken)
                }).then(function (r) { return r.json(); }).then(function (data) {
                    STEPS.forEach(function (id) { setStep(id, 'done'); });
                    handleEvent(Object.assign({ step: data.status === 'success' ? 'done' : 'error', progress: 100, done: true }, data));
                }).catch(function () { window.location.reload(); });
            }
        }
    }

    // ---------------------------------------------------------------
    // Rollback confirm modal (from history table)
    // ---------------------------------------------------------------
    var rollbackModal = document.getElementById('rollback-confirm-modal');
    if (rollbackModal) {
        rollbackModal.addEventListener('show.bs.modal', function (ev) {
            var btn = ev.relatedTarget;
            if (!btn) return;
            var hash = btn.getAttribute('data-hash') || '';
            var short = btn.getAttribute('data-short') || hash.slice(0, 7);
            var display = document.getElementById('rollback-hash-display');
            var input = document.getElementById('rollback-history-hash');
            if (display) display.textContent = short;
            if (input) input.value = hash;
        });
    }
});
</script>
@endpush
