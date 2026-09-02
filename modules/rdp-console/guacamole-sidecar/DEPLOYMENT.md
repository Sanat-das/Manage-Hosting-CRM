# Deploying the rdp-console guacamole sidecar

This directory ships the Node sidecar that terminates browser WebSockets for
the `rdp-console` module's RDP HTML5 console. It wraps
[guacamole-lite](https://github.com/vadimpronin/guacamole-lite), validates the
PHP-minted AES-256-CBC tokens (rejecting expired ones via
`processConnectionSettings`), and proxies the Guacamole protocol to a local
`guacd`.

```
Browser  --ws-->  server.js (guacamole-lite)  -->  guacd:4822  -->  target Windows host (RDP)
```

## Prerequisites

- Windows Server 2019+ (or any host able to run Node and Docker/WSL2).
- The Laravel app's `GUACAMOLE_SECRET` value (must match exactly — tokens are
  encrypted with this shared key, see `config/rdp-console.php`).

## 1. Install Node LTS

Install the current **Node.js LTS** release (18.x or newer; `guacamole-lite`
requires `>=10`, this sidecar targets `>=18`) on the service host:

```powershell
# Using winget (or download the MSI from https://nodejs.org)
winget install OpenJS.NodeJS.LTS
node --version   # verify >= v18
```

## 2. Install dependencies and register the service

Copy this `guacamole-sidecar/` directory to e.g. `C:\apps\guacamole-sidecar`,
then install dependencies from the committed lockfile:

```powershell
cd C:\apps\guacamole-sidecar
npm ci
```

Register as an auto-start Windows service with either NSSM or WinSW.
**These commands are documentation only — do not run them during QA.**

### Option A: NSSM

```powershell
choco install nssm   # or download from https://nssm.cc
nssm install guacamole-sidecar "C:\Program Files\nodejs\node.exe" "C:\apps\guacamole-sidecar\server.js"
nssm set guacamole-sidecar AppDirectory C:\apps\guacamole-sidecar
nssm set guacamole-sidecar AppEnvironmentExtra GUACAMOLE_SECRET=<secret> GUAC_WS_PORT=8080 GUACD_HOST=127.0.0.1 GUACD_PORT=4822
nssm start guacamole-sidecar
```

### Option B: WinSW

Drop `WinSW-x64.exe` next to a config file (`guacamole-sidecar.xml`):

```xml
<service>
  <id>guacamole-sidecar</id>
  <name>guacamole-sidecar</name>
  <executable>C:\Program Files\nodejs\node.exe</executable>
  <arguments>C:\apps\guacamole-sidecar\server.js</arguments>
  <workingdirectory>C:\apps\guacamole-sidecar</workingdirectory>
  <env name="GUACAMOLE_SECRET" value="&lt;secret&gt;" />
  <env name="GUAC_WS_PORT" value="8080" />
  <env name="GUACD_HOST" value="127.0.0.1" />
  <env name="GUACD_PORT" value="4822" />
  <onfailure action="restart" />
</service>
```

```powershell
.\guacamole-sidecar.exe install
.\guacamole-sidecar.exe start
```

## 3. Run guacd (MANUAL operator step)

`guacd` is the Apache Guacamole protocol daemon. On Windows run it in a
container via Docker Desktop or WSL2:

> ⚠️ **Pin the image to `1.5.5`.** Do **not** use `1.6.0`: it contains a
> regression affecting guacamole-lite clients (see upstream
> [guacamole-lite issue #72](https://github.com/vadimpronin/guacamole-lite/issues/72)).
> Upgrade only after that issue is confirmed fixed.

**This is a MANUAL operation step — never executed by automated QA.**

```powershell
docker run -d --name guacd -p 127.0.0.1:4822:4822 guacamole/guacd:1.5.5
```

The `-p 127.0.0.1:4822:4822` binding keeps guacd reachable only on loopback.

Verify:

```powershell
docker ps --filter name=guacd
Test-NetConnection 127.0.0.1 -Port 4822
```

## 4. Reverse proxy `/ws` (WebSocket upgrade)

The Laravel frontend connects to `ws://<sidecar-host>:8080/?token=...`.
Terminate TLS / route through your web server by forwarding the `/ws` path
with proper WebSocket upgrade headers.

### nginx

```nginx
location /ws {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_read_timeout 3600s;   # keep RDP sessions alive
    proxy_send_timeout 3600s;
}
```

### IIS with Application Request Routing (ARR)

1. Install **URL Rewrite** and **Application Request Routing** modules;
   enable *proxy mode* at the server level
   (`ARR -> Server Proxy Settings -> Enable proxy`).
2. Add an inbound rewrite rule on the site matching `^ws(/.*)?$`:
   - Match URL: `^ws(/.*)?$`
   - Action: Rewrite to `http://127.0.0.1:8080/{R:1}`
3. Ensure upgrade headers pass through (ARR sets them when proxying
   WebSocket requests); if needed add server variables
   `HTTP_UPGRADE` / `HTTP_CONNECTION` with allowed server variables
   `WEBSOCKET_ENABLED=true` (IIS 8+ handles WS natively once ARR proxy is on).
4. Raise idle timeout (site → Advanced Settings → *Connection Limits* →
   idle Time-out, e.g. 1200s) so long RDP sessions are not dropped.

## 5. Firewall

Keep every port of this stack **localhost-only** unless you deliberately
front it with the reverse proxy above:

- `GUAC_WS_PORT` (default 8080): bind/restrict to loopback when nginx/IIS runs
  on the same host; otherwise allow only the reverse-proxy subnet.
- guacd `4822`: must stay loopback-only (the docker command in step 3 already
  binds `127.0.0.1`). Never expose guacd publicly — its protocol is unauthenticated.

```powershell
# Example: block off-box access to the sidecar port (documentation only)
New-NetFirewallRule -DisplayName "guacamole-sidecar localhost only" `
  -Direction Inbound -LocalPort 8080 -Protocol TCP -RemoteAddress 127.0.0.1 `
  -Action Allow | Out-Null
New-NetFirewallRule -DisplayName "guacamole-sidecar deny external" `
  -Direction Inbound -LocalPort 8080 -Protocol TCP -Action Block | Out-Null
```

## 6. Environment matrix

| Variable           | Default     | Required | Purpose                                                        |
|--------------------|-------------|----------|----------------------------------------------------------------|
| `GUACAMOLE_SECRET` | *(none)*    | yes      | Shared AES-256-CBC key; ≥16 chars; must match Laravel's `config('rdp-console.secret')`. Startup aborts (exit 1) when missing/too short. |
| `GUAC_WS_PORT`     | `8080`      | no       | Port of the client-facing WebSocket listener.                  |
| `GUACD_HOST`       | `127.0.0.1` | no       | Host running guacd.                                            |
| `GUACD_PORT`       | `4822`      | no       | Port of guacd.                                                 |

Related Laravel-side variables (set in the app, not here):
`GUACAMOLE_WS_URL` (public ws:// URL baked into the console page) and
`GUACAMOLE_RECORDING_PATH` (session recordings, written by guacd on the
Windows target).

See `.env.example.sidecar` for a copy-paste template.

## 7. Version compatibility

| Component                          | Version        | Notes                                                                                     |
|------------------------------------|----------------|-------------------------------------------------------------------------------------------|
| Browser client (`guacamole-common-js`, vendored at `modules/rdp-console/resources/assets/guacamole-common.min.js`) | **1.5.0** | UMD build from `npm pack guacamole-common-js`; negotiates down to guacamole-lite's `VERSION_1_1_0` handshake — safe with the sidecar below. |
| Sidecar (`guacamole-lite`)         | `^1.2.0`       | Locked in this directory's `package-lock.json`.                                            |
| Protocol daemon (`guacd`)          | `1.5.5` pinned | Do NOT use 1.6.0 (regression affecting guacamole-lite clients, upstream issue #72) — see step 3. |
