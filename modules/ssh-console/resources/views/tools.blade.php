{{-- Linux VPS account tools (SSH terminal launcher, settings modal,
     password reveal). Owned by the ssh-console module; rendered on the
     core hosting show page via HostingAccountToolsProvider. --}}

@php
                    $sshEffectiveHost = $sshConfig?->host ?? ($assignedIps->firstWhere('type', 'public')?->ip_address ?? $assignedIps->first()?->ip_address);
                    $sshEffectivePort = $sshConfig?->port ?? 22;
                    $sshHasHost = $sshEffectiveHost !== null && trim((string) $sshEffectiveHost) !== '';
                    $sshHasPassword = filled(trim((string) ($sshConfig?->password_encrypted ?? '')));
                    $sshHasKey = filled(trim((string) ($sshConfig?->private_key_encrypted ?? '')));
@endphp

                        <div class="border rounded p-3">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="text-primary mb-0">
                                    <i class="bi bi-terminal me-1"></i>
                                    SSH Terminal
                                </h6>
                                <div class="d-flex gap-2">
                                    @if ($sshHasHost)
                                        @can('hosting.view')
                                            <a href="{{ route('admin.ssh-console.html', $hostingAccount) }}" class="btn btn-sm btn-outline-secondary" title="Open web SSH terminal">
                                                <i class="bi bi-terminal me-1"></i> Open Terminal
                                            </a>
                                        @endcan
                                    @endif
                                    @can('hosting.manage')
                                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#ssh-edit-modal">
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
                                            @if ($sshHasHost)
                                                <code>{{ $sshEffectiveHost }}:{{ $sshEffectivePort }}</code>
                                                @if ($sshConfig?->host === null && $assignedIps->isNotEmpty())
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
                                        <td>{{ $sshConfig?->username ? $sshConfig->username : '—' }}</td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Port</th>
                                        <td>{{ $sshEffectivePort }}</td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Authentication</th>
                                        <td>
                                            @if ($sshHasPassword || $sshHasKey)
                                                <span class="badge bg-success-subtle text-success-emphasis">{{ $sshHasPassword ? 'password' : '' }}{{ $sshHasPassword && $sshHasKey ? ' + ' : '' }}{{ $sshHasKey ? 'SSH key' : '' }}</span>
                                            @else
                                                <span class="badge text-bg-secondary">none stored</span>
                                            @endif
                                            @if ($sshHasPassword)
                                                <span class="d-inline-flex align-items-center gap-2 ms-2" id="ssh-password-group">
                                                    <code id="ssh-password-display" style="letter-spacing: 0.15em;">••••••••</code>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" id="ssh-password-toggle" title="View password" aria-label="View password">
                                                        <i class="bi bi-eye" id="ssh-password-toggle-icon"></i>
                                                        <span id="ssh-password-toggle-text" class="ms-1 small">Show</span>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" id="ssh-password-copy" title="Copy password" aria-label="Copy password">
                                                        <i class="bi bi-clipboard"></i>
                                                        <span class="ms-1 small">Copy</span>
                                                    </button>
                                                    <span id="ssh-password-feedback" class="text-success small d-none">Copied!</span>
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <div class="text-muted small mt-2">
                                @if (! $sshHasPassword && ! $sshHasKey)
                                    No credentials stored — configure a password or private key via <em>Edit</em> before opening a terminal.
                                @else
                                    Sessions are streamed from the browser over an authenticated, per-admin terminal; every session is audit-logged.
                                @endif
                            </div>
                        </div>

                @if ($sshHasPassword)
                @can('hosting.view')
                @push('js')
                <script>
                (() => {
                    const display = document.getElementById('ssh-password-display');
                    const toggle = document.getElementById('ssh-password-toggle');
                    const toggleIcon = document.getElementById('ssh-password-toggle-icon');
                    const toggleText = document.getElementById('ssh-password-toggle-text');
                    const copyBtn = document.getElementById('ssh-password-copy');
                    const feedback = document.getElementById('ssh-password-feedback');
                    if (!display || !toggle || !copyBtn) return;
                    let cached = null;
                    let visible = false;
                    const masked = '••••••••';
                    const endpoint = @json(route('admin.ssh-console.password', $hostingAccount));
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

                {{-- SSH Edit Modal --}}
                @can('hosting.manage')
                    <div class="modal fade" id="ssh-edit-modal" tabindex="-1" aria-labelledby="ssh-edit-modal-label" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form method="POST" action="{{ route('admin.ssh-console.update', $hostingAccount) }}">
                                    @csrf
                                    @method('PUT')
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="ssh-edit-modal-label">Edit SSH settings</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-3">
                                            <label for="ssh-host" class="form-label">Host</label>
                                            @php
                                                $sshHostCurrent = old('host', $sshConfig?->host);
                                                $sshHostCustom = ($sshHostCurrent !== null && trim((string) $sshHostCurrent) !== '' && ! $assignedIps->contains(fn ($ip) => $ip->ip_address === $sshHostCurrent))
                                                    ? trim((string) $sshHostCurrent)
                                                    : null;
                                            @endphp
                                            <select class="form-select @error('host') is-invalid @enderror" id="ssh-host" name="host" aria-label="SSH host">
                                                <option value="">Use assigned IP</option>
                                                @foreach ($assignedIps->sortByDesc(fn ($ip) => $ip->subnet?->network_type === 'public') as $assignedIp)
                                                    <option value="{{ $assignedIp->ip_address }}" @selected($sshHostCurrent === $assignedIp->ip_address)>
                                                        {{ $assignedIp->ip_address }}@if ($assignedIp->type) · {{ ucfirst($assignedIp->type) }}@endif — {{ $assignedIp->subnet?->name ?? $assignedIp->subnet?->subnet_cidr ?? '' }}@if ($assignedIp->subnet?->vlan) · VLAN {{ $assignedIp->subnet->vlan->name }} ({{ $assignedIp->subnet->vlan->vlan_id }})@endif
                                                    </option>
                                                @endforeach
                                                @if ($sshHostCustom !== null)
                                                    <option value="{{ $sshHostCustom }}" selected>{{ $sshHostCustom }} (custom)</option>
                                                @endif
                                            </select>
                                            <div class="form-text">Pick one of the IPs assigned to this service, or leave on “Use assigned IP”.</div>
                                            @error('host')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        </div>
                                        <div class="mb-3">
                                            <label for="ssh-port" class="form-label">Port</label>
                                            <input type="number" class="form-control @error('port') is-invalid @enderror" id="ssh-port" name="port" min="1" max="65535" value="{{ old('port', $sshConfig?->port ?? 22) }}">
                                            @error('port')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        </div>
                                        <div class="mb-3">
                                            <label for="ssh-username" class="form-label">Username</label>
                                            <input type="text" class="form-control @error('username') is-invalid @enderror" id="ssh-username" name="username" maxlength="255" value="{{ old('username', $sshConfig?->username ?? '') }}" placeholder="root">
                                            @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        </div>
                                        <div class="mb-3">
                                            <label for="ssh-password" class="form-label">Password</label>
                                            <input type="password" class="form-control @error('password') is-invalid @enderror" id="ssh-password" name="password" maxlength="255" placeholder="•••••••• (leave blank to keep existing)" autocomplete="new-password">
                                            <div class="form-text">Stored encrypted. Leave blank to keep the current password.</div>
                                            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        </div>
                                        <div class="mb-3">
                                            <label for="ssh-private-key" class="form-label">Private key (optional)</label>
                                            <textarea class="form-control font-monospace @error('private_key') is-invalid @enderror" id="ssh-private-key" name="private_key" rows="4" placeholder="-----BEGIN OPENSSH PRIVATE KEY----- (leave blank to keep existing)">{{ old('private_key', '') }}</textarea>
                                            <div class="form-text">OpenSSH/PEM private key, used when no password is set. Stored encrypted.</div>
                                            @error('private_key')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        </div>
                                        <div class="mb-3">
                                            <label for="ssh-passphrase" class="form-label">Key passphrase (optional)</label>
                                            <input type="password" class="form-control @error('passphrase') is-invalid @enderror" id="ssh-passphrase" name="passphrase" maxlength="255" placeholder="leave blank to keep existing" autocomplete="new-password">
                                            <div class="form-text">Passphrase for the encrypted private key, if any.</div>
                                            @error('passphrase')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
