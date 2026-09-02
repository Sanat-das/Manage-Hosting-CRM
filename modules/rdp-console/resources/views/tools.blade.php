{{-- Windows Server account tools (Remote Desktop console, settings modal,
     password reveal). Owned by the rdp-console module; rendered on the
     core hosting show page via HostingAccountToolsProvider. --}}

@php
                    $rdpEffectiveHost = $rdpConfig?->host ?? ($assignedIps->firstWhere('type', 'public')?->ip_address ?? $assignedIps->first()?->ip_address);
                    $rdpEffectivePort = $rdpConfig?->port ?? 3389;
                    $rdpHasHost = $rdpEffectiveHost !== null && trim((string) $rdpEffectiveHost) !== '';
@endphp

                        <div class="border rounded p-3">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="text-primary mb-0">
                                    <i class="bi bi-display me-1"></i>
                                    Remote Desktop (RDP)
                                </h6>
                                <div class="d-flex gap-2">
                                    @if ($rdpHasHost)
                                        @can('hosting.view')
                                            <a href="{{ route('admin.rdp-console.html', $hostingAccount) }}" class="btn btn-sm btn-outline-secondary" title="Open HTML console">
                                                <i class="bi bi-window me-1"></i> HTML
                                            </a>
                                            <a href="{{ route('admin.rdp-console.download', $hostingAccount) }}" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-download me-1"></i> Download .rdp
                                            </a>
                                        @endcan
                                    @endif
                                    @can('hosting.manage')
                                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#rdp-edit-modal">
                                            <i class="bi bi-pencil me-1"></i> Edit
                                        </button>
                                    @endcan
                                </div>
                            </div>
                            <table class="table table-sm table-borderless mb-0">
                                <tbody>
                                    <tr>
                                        <th class="w-25 text-muted">Host</th>
                                        <td>
                                            @if ($rdpHasHost)
                                                <code>{{ $rdpEffectiveHost }}:{{ $rdpEffectivePort }}</code>
                                                @if ($rdpConfig?->host === null && $assignedIps->isNotEmpty())
                                                    <span class="text-muted small ms-1">(from assigned IP)</span>
                                                @endif
                                            @else
                                                <span class="text-muted">—</span>
                                                <span class="text-muted small ms-1">No host configured and no IP assigned</span>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Username</th>
                                        <td>{{ $rdpConfig?->username ? $rdpConfig->username : '—' }}</td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Domain</th>
                                        <td>{{ $rdpConfig?->domain ? $rdpConfig->domain : '—' }}</td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Port</th>
                                        <td>{{ $rdpEffectivePort }}</td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Password</th>
                                        <td>
                                            @if ($rdpConfig?->password_encrypted)
                                                <div class="d-flex align-items-center gap-2" id="rdp-password-group">
                                                    <code id="rdp-password-display" class="flex-grow-0" style="letter-spacing: 0.15em;">••••••••</code>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" id="rdp-password-toggle" title="View password" aria-label="View password">
                                                        <i class="bi bi-eye" id="rdp-password-toggle-icon"></i>
                                                        <span id="rdp-password-toggle-text" class="ms-1 small">Show</span>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" id="rdp-password-copy" title="Copy password" aria-label="Copy password">
                                                        <i class="bi bi-clipboard"></i>
                                                        <span class="ms-1 small">Copy</span>
                                                    </button>
                                                    <span id="rdp-password-feedback" class="text-success small d-none">Copied!</span>
                                                </div>
                                            @else
                                                <span class="text-muted">—</span>
                                                <span class="text-muted small ms-1">No password stored</span>
                                            @endif
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <div class="text-muted small mt-2">
                                @if ($rdpConfig?->password_encrypted)
                                    .rdp now includes an encrypted <code>password 51:b:</code> (Windows DPAPI, bound to the server user). On your own machine it can auto-login; otherwise the client will still prompt — use <em>Copy</em> next to the password.
                                @else
                                    No password stored — the .rdp will prompt. Set a password via <em>Edit</em> to embed it.
                                @endif
                            </div>
                        </div>

                @if ($rdpConfig?->password_encrypted)
                @can('hosting.view')
                @push('js')
                <script>
                (() => {
                    const display = document.getElementById('rdp-password-display');
                    const toggle = document.getElementById('rdp-password-toggle');
                    const toggleIcon = document.getElementById('rdp-password-toggle-icon');
                    const toggleText = document.getElementById('rdp-password-toggle-text');
                    const copyBtn = document.getElementById('rdp-password-copy');
                    const feedback = document.getElementById('rdp-password-feedback');
                    if (!display || !toggle || !copyBtn) return;
                    let cached = null;
                    let visible = false;
                    const masked = '••••••••';
                    const endpoint = @json(route('admin.rdp-console.password', $hostingAccount));
                    async function fetchPassword() {
                        if (cached !== null) return cached;
                        const res = await fetch(endpoint, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                        if (!res.ok) throw new Error('Failed to fetch password');
                        const data = await res.json();
                        cached = data.password ?? '';
                        return cached;
                    }
                    function showFeedback(msg) {
                        if (!feedback) return;
                        feedback.textContent = msg;
                        feedback.classList.remove('d-none');
                        setTimeout(() => feedback.classList.add('d-none'), 1800);
                    }
                    toggle.addEventListener('click', async () => {
                        try {
                            const pwd = await fetchPassword();
                            if (!pwd) { showFeedback('No password'); return; }
                            visible = !visible;
                            if (visible) {
                                display.textContent = pwd;
                                display.style.letterSpacing = 'normal';
                                toggleIcon.className = 'bi bi-eye-slash';
                                toggleText.textContent = 'Hide';
                                toggle.setAttribute('title', 'Hide password');
                            } else {
                                display.textContent = masked;
                                display.style.letterSpacing = '0.15em';
                                toggleIcon.className = 'bi bi-eye';
                                toggleText.textContent = 'Show';
                                toggle.setAttribute('title', 'View password');
                            }
                        } catch (e) { showFeedback('Failed to load'); }
                    });
                    copyBtn.addEventListener('click', async () => {
                        try {
                            const pwd = await fetchPassword();
                            if (!pwd) { showFeedback('No password'); return; }
                            if (navigator.clipboard && window.isSecureContext) {
                                await navigator.clipboard.writeText(pwd);
                            } else {
                                const ta = document.createElement('textarea');
                                ta.value = pwd; ta.style.position = 'fixed'; ta.style.opacity = '0';
                                document.body.appendChild(ta); ta.select();
                                document.execCommand('copy'); ta.remove();
                            }
                            showFeedback('Copied!');
                        } catch (e) { showFeedback('Copy failed'); }
                    });
                })();
                </script>
                @endpush
                @endcan
                @endif

                {{-- RDP Edit Modal --}}
                @can('hosting.manage')
                    <div class="modal fade" id="rdp-edit-modal" tabindex="-1" aria-labelledby="rdp-edit-modal-label" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form method="POST" action="{{ route('admin.rdp-console.update', $hostingAccount) }}">
                                    @csrf
                                    @method('PUT')
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="rdp-edit-modal-label">Edit RDP settings</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-3">
                                            <label for="rdp-host" class="form-label">Host</label>
                                            @php
                                                $rdpHostCurrent = old('host', $rdpConfig?->host);
                                                $rdpHostCustom = ($rdpHostCurrent !== null && trim((string) $rdpHostCurrent) !== '' && ! $assignedIps->contains(fn ($ip) => $ip->ip_address === $rdpHostCurrent))
                                                    ? trim((string) $rdpHostCurrent)
                                                    : null;
                                            @endphp
                                            <select class="form-select @error('host') is-invalid @enderror" id="rdp-host" name="host" aria-label="RDP host">
                                                <option value="">Use assigned IP</option>
                                                @foreach ($assignedIps->sortByDesc(fn ($ip) => $ip->subnet?->network_type === 'public') as $assignedIp)
                                                    <option value="{{ $assignedIp->ip_address }}" @selected($rdpHostCurrent === $assignedIp->ip_address)>
                                                        {{ $assignedIp->ip_address }}@if ($assignedIp->type) · {{ ucfirst($assignedIp->type) }}@endif — {{ $assignedIp->subnet?->name ?? $assignedIp->subnet?->subnet_cidr ?? '' }}@if ($assignedIp->subnet?->vlan) · VLAN {{ $assignedIp->subnet->vlan->name }} ({{ $assignedIp->subnet->vlan->vlan_id }})@endif
                                                    </option>
                                                @endforeach
                                                @if ($rdpHostCustom !== null)
                                                    <option value="{{ $rdpHostCustom }}" selected>{{ $rdpHostCustom }} (custom)</option>
                                                @endif
                                            </select>
                                            <div class="form-text">Pick one of the IPs assigned to this service, or leave on “Use assigned IP”.</div>
                                            @error('host')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        </div>
                                        <div class="mb-3">
                                            <label for="rdp-port" class="form-label">Port</label>
                                            <input type="number" class="form-control @error('port') is-invalid @enderror" id="rdp-port" name="port" min="1" max="65535" value="{{ old('port', $rdpConfig?->port ?? 3389) }}">
                                            @error('port')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        </div>
                                        <div class="mb-3">
                                            <label for="rdp-username" class="form-label">Username</label>
                                            <input type="text" class="form-control @error('username') is-invalid @enderror" id="rdp-username" name="username" maxlength="255" value="{{ old('username', $rdpConfig?->username ?? '') }}" placeholder="Administrator">
                                            @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        </div>
                                        <div class="mb-3">
                                            <label for="rdp-password" class="form-label">Password</label>
                                            <input type="password" class="form-control @error('password') is-invalid @enderror" id="rdp-password" name="password" maxlength="255" placeholder="•••••••• (leave blank to keep existing)" autocomplete="new-password">
                                            <div class="form-text">Stored encrypted. Leave blank to keep the current password.</div>
                                            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        </div>
                                        <div class="mb-3">
                                            <label for="rdp-domain" class="form-label">Domain</label>
                                            <input type="text" class="form-control @error('domain') is-invalid @enderror" id="rdp-domain" name="domain" maxlength="255" value="{{ old('domain', $rdpConfig?->domain ?? '') }}" placeholder="Optional domain">
                                            @error('domain')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-primary">Save</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                @endcan
