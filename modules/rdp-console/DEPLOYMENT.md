# rdp-console Module — Deployment

This module provides browser-based RDP access via a Guacamole-lite sidecar
that bridges PHP-minted AES-256-CBC tokens to a local `guacd` daemon. Two
connection modes share the same sidecar and canvas: guest-RDP (the guest's
own RDP service) and Hyper-V VMConnect (the Hyper-V host's `vmrdp` service —
see the VMConnect section below).

```
Browser  --ws-->  sidecar (guacamole-lite)  -->  guacd:4822  -->  guest RDP service (3389)
                                                             \->  Hyper-V vmrdp (2179, VMConnect)
```

For full installation steps (Node, sidecar registration, guacd Docker,
reverse proxy, firewall), see the sidecar README:
[guacamole-sidecar/DEPLOYMENT.md](guacamole-sidecar/DEPLOYMENT.md).

## Version compatibility

| Component | Version | Notes |
|-----------|---------|-------|
| Browser client (`guacamole-common-js`) | **1.5.0** vendored | UMD build at `resources/assets/guacamole-common.min.js`. Negotiates down to guacamole-lite's `VERSION_1_1_0` handshake. |
| Sidecar (`guacamole-lite`) | `^1.2.0` | Locked in `guacamole-sidecar/package-lock.json`. |
| Protocol daemon (`guacd`) | **1.5.5 pinned** (`guacamole/guacd:1.5.5` Docker image) | Do NOT use 1.6.0 — it contains a regression affecting guacamole-lite clients ([upstream issue #72](https://github.com/vadimpronin/guacamole-lite/issues/72)). Upgrade only after that issue is confirmed fixed. |

## Environment variable matrix

### Laravel-side (set in the project root `.env`)

| Variable                | Default              | Required | Description |
|-------------------------|----------------------|----------|-------------|
| `GUACAMOLE_SECRET`      | *(none)*             | yes      | Shared AES-256-CBC key, >= 16 chars. Must be byte-identical to the sidecar's `GUACAMOLE_SECRET`. Generate with `openssl rand -base64 32`. |
| `GUACAMOLE_WS_URL`      | `ws://127.0.0.1:8080/` | no    | Public WebSocket URL baked into the console page. Change if the sidecar runs on a different host/port. |
| `GUACAMOLE_RECORDING_PATH` | *(blank = disabled)* | no   | Directory where guacd writes session recordings. Blank disables recording. |

### Sidecar-side (set via NSSM/WinSW service environment or `.env` next to `server.js`)

| Variable        | Default     | Required | Description |
|-----------------|-------------|----------|-------------|
| `GUACAMOLE_SECRET` | *(none)* | yes      | Same value as the Laravel-side `GUACAMOLE_SECRET`. Startup aborts (exit 1) when missing or shorter than 16 chars. |
| `GUAC_WS_PORT`  | `8080`      | no       | Port the sidecar WebSocket server listens on. |
| `GUACD_HOST`    | `127.0.0.1` | no       | Host running guacd. |
| `GUACD_PORT`    | `4822`      | no       | Port of guacd. |

See [guacamole-sidecar/.env.example.sidecar](guacamole-sidecar/.env.example.sidecar) for a copy-paste template.

### Generating `GUACAMOLE_SECRET`

```powershell
# Option A — OpenSSL (recommended)
openssl rand -base64 32

# Option B — Node.js
node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
```

The resulting 44-character base64 (or 64-character hex) string exceeds the
minimum 16-character requirement. Copy the same value into both the Laravel
`.env` and the sidecar service environment.

### Missing secret behaviour

`GUACAMOLE_SECRET` is **never defaulted, generated or hard-coded**. When it is
absent (or shorter than 16 characters) both console token endpoints answer
**503** with a fixed, actionable message ("The console gateway is not
configured. Set GUACAMOLE_SECRET (16+ characters)…") and both console pages
render a *console gateway is not configured* state with the Connect control
disabled — instead of offering a button that cannot work. The real reason is
logged server-side (`rdp.console.gateway_not_configured`); no exception
message, class, stack trace, file path or config value reaches the browser.
Minting still fails closed.

## Hyper-V VMConnect console (second connection mode)

Besides the guest-RDP console, the module offers a **Hyper-V VMConnect**
console that renders the VM's screen through the Hyper-V host's `vmrdp`
service instead of the guest's RDP service — the same mechanism Windows Admin
Center's console uses. It works without guest networking and at boot / pre-OS
screens.

| | Guest-RDP (default) | Hyper-V VMConnect |
|---|---|---|
| Target | the guest's RDP service | the Hyper-V host's `vmrdp` listener |
| Port | 3389 (per-account config) | **2179** (fixed) |
| Credentials | the guest's (`rdp_console_configs`) | the **Hyper-V host's** (`servers.api_username` / `api_password_encrypted`) |
| guacd security | `nla` (per-account) | `vmconnect` |
| VM selection | n/a | `preconnection-blob` = the VM GUID (`panel_accounts.external_id`; the live host probe's `vmId` is preferred when the host answers) |
| Boot / pre-OS screens | no | yes |
| Guest network needed | yes | no |
| Console page | `GET .../rdp-console/html` (`hosting.view`) | `GET .../rdp-console/vm-console` (`hosting.manage`) |
| Token | `GET .../rdp-console/token` (`hosting.view`) | `GET .../rdp-console/vm-console/token` (`hosting.manage`) |

The VM console is reachable from the Hyper-V action panel on the admin hosting
show page — the **VM Console** button in the utility group next to *Reset
password* and *Credentials* — and only for users holding `hosting.manage`, the
same interactive-control class as the SSH console. Every input to the token
(host, port, GUID, security mode, credentials) is resolved server-side from
the account's `PanelAccount` and `Server` rows; nothing is accepted from the
request, and a missing VM GUID or missing host credentials fails closed (404,
no token).

The guacd settings emitted for this mode are: `hostname` (bare host derived
from `servers.api_url`), `port` 2179, `username`/`password` (the HOST's),
`security` `vmconnect`, `preconnection-blob` (the VM GUID) and `ignore-cert`
`true` — scoped to this connection's settings only, never a global guacd
default. Deliberately omitted for this mode: `preconnection-id` (the manual
says leave it blank for Hyper-V), `domain`, `enable-drive`/`drive-path` (drive
redirection is not meaningful in a Hyper-V basic session, and the path is on
the guacd host) and `resize-method` (guacd 1.5.5 defaults to "none" when
blank; the browser canvas scales the display client-side).

### Operational prerequisite

**TCP 2179 must be reachable from the guacd host to the Hyper-V host.** It is
currently silently dropped (consistent with the Windows Firewall default).
Allow it only from the guacd host's address:

```powershell
New-NetFirewallRule -DisplayName "Hyper-V vmrdp from guacd" -Direction Inbound `
  -Protocol TCP -LocalPort 2179 -RemoteAddress <guacd-host-ip> -Action Allow
```

### Unverified in this environment

`guacd` is not deployed here and 2179 is unreachable, so a **live VMConnect
session could not be established**. The evidence gathered is the decrypted
token payload and the rendered UI only. Specifically unverified:

1. **guacd 1.5.5 preconnection-PDU handshake** — that the VM GUID in
   `preconnection-blob` is accepted end-to-end by Hyper-V's `vmrdp` server.
   (`security=vmconnect` is handled in guacd's `settings.c`, but the live
   handshake is untested.)
2. **`ignore-cert` against Hyper-V's self-signed certificate** — that the
   connection-scoped flag is sufficient for the host's certificate.
3. **Dynamic resize** — `resize-method` is omitted for VMConnect; in-guest
   resolution changes have not been exercised.
4. **Concurrency** — multiple simultaneous VMConnect sessions to the same VM
   or host have not been exercised.

### Token hardening (separate follow-up)

The token format (AES-256-CBC envelope), key derivation (NUL-pad/truncate to
32 bytes) and 90-second TTL are shared with the guest-RDP mode and unchanged.
Single-use nonces and authenticated encryption remain a separate hardening
task that also requires a sidecar (`guacamole-lite`) change — not attempted
here.

