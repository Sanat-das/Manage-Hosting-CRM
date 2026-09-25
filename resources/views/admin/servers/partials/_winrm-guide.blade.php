@php
  $isHyperV = ($serverType ?? $typeSlug ?? '') === 'hyperv';
  $host = isset($server) ? ($server->ip_address ?? 'HOST') : 'HOST';
  // Source IP the Hyper-V host will actually see on the WinRM connection. Not
  // the browser's IP: this app is the client. Probe the host we are targeting
  // so a separate management route answers correctly; falls back to a
  // <HOSTVEXA_IP> placeholder rather than guessing.
  $panelIp = \App\Support\EgressIp::forDisplay(isset($server) ? ($server->ip_address ?? null) : null);
@endphp
<div class="card border mt-3" id="winrmGuideCard">
  <div class="card-header bg-light d-flex align-items-center justify-content-between" style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#winrmGuideBody" aria-expanded="false">
    <div class="d-flex align-items-center gap-2">
      <i class="bi bi-life-preserver text-primary"></i>
      <strong style="font-size: var(--text-sm)">Hyper-V WinRM setup guide</strong>
      <span class="badge text-bg-info fw-normal" style="font-size: var(--text-2xs)">Windows host + Linux app host</span>
    </div>
    <span class="text-muted small"><i class="bi bi-chevron-down"></i> Click to expand</span>
  </div>
  <div id="winrmGuideBody" class="collapse">
    <div class="card-body" style="font-size: var(--text-sm)">
      <div class="alert alert-info py-2 mb-3" style="font-size: var(--text-xs)">
        <i class="bi bi-info-circle me-1"></i> Host <code>{{ $host }}</code> — this client talks to <code>http(s)://{{ $host }}:5985|5986/wsman</code> via Basic auth. No SCVMM needed.
        <div class="mt-1">WinRM accepts connections from <strong>any</strong> source until you scope it, so the host firewall must be limited to <code>{{ $panelIp }}</code> — see tab A.</div>
      </div>

      <ul class="nav nav-pills mb-3" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#winrm-win" type="button" role="tab">A) On Hyper-V host (Windows)</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" data-bs-toggle="pill" data-bs-target="#winrm-linux" type="button" role="tab">B) On Linux app host</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" data-bs-toggle="pill" data-bs-target="#winrm-verify" type="button" role="tab">C) Verify & Troubleshoot</button>
        </li>
      </ul>

      <div class="tab-content border rounded p-3 bg-white">
        {{-- Windows host tab --}}
        <div class="tab-pane fade show active" id="winrm-win" role="tabpanel">
          <p class="fw-semibold mb-2">Run PowerShell <em>as Administrator</em> on <code>{{ $host }}</code>:</p>
<pre class="bg-dark text-light p-3 rounded" style="font-size: var(--text-xs); overflow-x:auto"><code>Enable-PSRemoting -Force
winrm quickconfig -q
Set-Service WinRM -StartupType Automatic; Restart-Service WinRM

# allow Basic + unencrypted for lab on 5985 (skip for prod HTTPS)
winrm set winrm/config/service '@{AllowUnencrypted="true"}'
winrm set winrm/config/service/auth '@{Basic="true"}'
winrm set winrm/config/winrs '@{MaxShellsPerUser="30"; MaxConcurrentUsers="10"}'

# make your user a Hyper-V admin
Add-LocalGroupMember -Group "Hyper-V Administrators" -Member "{{ isset($server) ? ($server->api_username ?? 'administrator') : 'administrator' }}" -ErrorAction SilentlyContinue

# verify locally
Test-WSMan -Auth Default
Get-VMHost | FL ComputerName,LogicalProcessorCount,MemoryCapacity</code></pre>
          <p class="mt-3 mb-1 fw-semibold">For HTTPS <code>5986</code> (prod):</p>
<pre class="bg-dark text-light p-3 rounded" style="font-size: var(--text-xs); overflow-x:auto"><code>$cert = New-SelfSignedCertificate -DnsName "{{ $host }}" -CertStoreLocation Cert:\LocalMachine\My
# Address=* binds every LOCAL interface. It is not a client filter — the
# WINRM* firewall scope below is what keeps other hosts out.
winrm create winrm/config/Listener?Address=*+Transport=HTTPS "@{Hostname=`"{{ $host }}`"; CertificateThumbprint=`"$($cert.Thumbprint)`"}"
# In this form: Use SSL = on, Verify TLS = off for self-signed (on for CA)</code></pre>
          <p class="mt-3 mb-1 fw-semibold">Scope WinRM to the HostVexa panel only:</p>
<pre class="bg-dark text-light p-3 rounded" style="font-size: var(--text-xs); overflow-x:auto"><code># Enable-PSRemoting binds the listener to every local address and opens the
# WINRM* firewall rules to ANY remote source. Nothing else limits who may
# reach 5985/5986: the listener's Address=* is only the local bind address,
# and the WinRM IPv4Filter/IPv6Filter settings choose local bind addresses
# too — neither is a client allow-list. The firewall scope below is the
# control that actually restricts the remote management server.
$panelIp = "{{ $panelIp }}"

# 1) Enumerate the rules present on THIS host. Names differ across Windows
#    versions, so never assume them.
Get-NetFirewallRule -Name "WINRM*" |
  Select-Object Name, DisplayName, Profile, Enabled, Direction, Action

# 2) Re-scope every inbound WinRM allow rule to the panel alone.
#    Safe to re-run: -RemoteAddress replaces the scope, it does not append.
Get-NetFirewallRule -Name "WINRM*" |
  Where-Object { $_.Direction -eq "Inbound" -and $_.Action -eq "Allow" } |
  ForEach-Object { Set-NetFirewallRule -Name $_.Name -RemoteAddress $panelIp }

# 3) Verify — RemoteAddress must show $panelIp for every rule, never "Any".
#    Read this output: a rule still showing "Any" was not scoped by step 2.
Get-NetFirewallRule -Name "WINRM*" |
  Get-NetFirewallAddressFilter |
  Select-Object InstanceID, RemoteAddress |
  Format-Table -AutoSize</code></pre>
          <div class="form-text">Firewall: <code>5985</code> HTTP / <code>5986</code> HTTPS inbound. <code>Enable-PSRemoting</code> opens the <code>WINRM*</code> rules to <strong>any</strong> source on a private profile (public profile allows only the local subnet), so scope them to <code>{{ $panelIp }}</code> as above. If HostVexa sits behind NAT or a proxy, scope to the public address the host sees on <code>5985</code> instead of the value shown here.</div>
        </div>

        {{-- Linux app host tab --}}
        <div class="tab-pane fade" id="winrm-linux" role="tabpanel">
          <p class="mb-2">No WinRM service on Linux — PHP uses <code>curl → /wsman</code> Basic auth. Only the Hyper-V host needs the steps above.</p>
          <p class="fw-semibold mb-2">Check from Linux terminal:</p>
<pre class="bg-dark text-light p-3 rounded" style="font-size: var(--text-xs); overflow-x:auto"><code># network
nc -vz {{ $host }} 5985
curl -v http://{{ $host }}:5985/wsman --basic -u '{{ isset($server) ? ($server->api_username ?? 'administrator') : 'administrator' }}:YOUR_PASSWORD'   # expect 405, not timeout = reachable

# full check via pywinrm
pip install pywinrm
python3 -c "
import winrm
s=winrm.Session('http://{{ $host }}:5985/wsman', auth=('{{ isset($server) ? ($server->api_username ?? 'administrator') : 'administrator' }}','YOUR_PASSWORD'), transport='basic', server_cert_validation='ignore')
print(s.run_ps('Get-VMHost | FL *').std_out.decode())
"</code></pre>
          <p class="mb-1">If <code>{{ $host }}</code> is private (10.x) and Linux is off-site, you need VPN / site-to-site / public NAT + host firewall whitelist <code>remoteip={{ $panelIp }}</code>.</p>
          <p class="mb-0 text-muted" style="font-size: var(--text-xs)">HostVexa form on Linux: keep <code>Use SSL off / 5985</code> for lab; for <code>5986</code> set <code>Use SSL on, Verify TLS off</code> for self-signed.</p>
        </div>

        {{-- Verify tab --}}
        <div class="tab-pane fade" id="winrm-verify" role="tabpanel">
          <p class="fw-semibold mb-2">In HostVexa:</p>
          <ol class="mb-3">
            <li>Host: <code>{{ $host }}</code> · Port <code>5985</code> (or <code>5986</code>) · User <code>{{ isset($server) ? ($server->api_username ?? 'administrator') : 'administrator' }}</code> (domain → <code>DOMAIN\{{ isset($server) ? ($server->api_username ?? 'administrator') : 'administrator' }}</code>)</li>
            <li><strong>Test Connection</strong> → green <code>Connected ~80ms</code> fills <em>Essential Information</em> (Get-VMHost/VM/Switch).</li>
          </ol>
          <table class="table table-sm mb-0" style="font-size: var(--text-xs)">
            <thead><tr><th>Error you see</th><th>Fix</th></tr></thead>
            <tbody>
              <tr><td><code>WinRM cannot process… TrustedHosts</code></td><td>Only on <strong>Windows</strong> app host: <code>Set-Item WSMan:\localhost\Client\TrustedHosts -Value "{{ $host }}" -Force -Concatenate</code></td></tr>
              <tr><td><code>401 / Access is denied</code></td><td>Use <code>.\{{ isset($server) ? ($server->api_username ?? 'administrator') : 'administrator' }}</code> or <code>{{ $host }}\{{ isset($server) ? ($server->api_username ?? 'administrator') : 'administrator' }}</code> for workgroup</td></tr>
              <tr><td><code>Connection timed out 5985</code></td><td>Host firewall / <code>Enable-PSRemoting</code> not run, or the <code>WINRM*</code> rules are scoped to an address other than <code>{{ $panelIp }}</code></td></tr>
              <tr><td><code>Connection timed out</code> only <em>after</em> scoping the firewall</td><td>The panel's egress IP moved (new host or new NAT). Re-scope: <code>Set-NetFirewallRule -Name "WINRM-HTTP-In-TCP" -RemoteAddress &lt;PANEL_IP&gt;</code>, then confirm with <code>Get-NetFirewallRule -Name "WINRM*" | Get-NetFirewallAddressFilter</code></td></tr>
              <tr><td><code>SSL/TLS</code></td><td>Toggle <em>Verify TLS</em> off or fix cert on 5986</td></tr>
            </tbody>
          </table>
          <div class="form-text mt-2">This panel is also on Edit/Show. Copy/paste these commands — update IP/user for your estate.</div>
        </div>
      </div>

      <div class="mt-3">
        <span class="text-muted" style="font-size: var(--text-xs)">Full guide lives in this card — no external login.</span>
      </div>
    </div>
  </div>
</div>
