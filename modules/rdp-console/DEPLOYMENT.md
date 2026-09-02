# rdp-console Module — Deployment

This module provides browser-based RDP access via a Guacamole-lite sidecar
that bridges PHP-minted AES-256-CBC tokens to a local `guacd` daemon.

```
Browser  --ws-->  sidecar (guacamole-lite)  -->  guacd:4822  -->  Windows host (RDP)
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
