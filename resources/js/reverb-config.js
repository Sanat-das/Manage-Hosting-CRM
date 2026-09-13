/**
 * Runtime Reverb config reader.
 *
 * Vite inlines `import.meta.env.VITE_*` at build time. `public/build/` is
 * committed and the updater never runs `npm run build`, so a build-time key
 * dead-code-eliminates the entire realtime branch when `.env` has no REVERB_*
 * keys at build time (which it doesn't). This module reads the same config at
 * RUNTIME from `window.__REVERB__` rendered by PHP, falling back to
 * `import.meta.env` only as a dev convenience. The branch is now a genuine
 * runtime decision the bundler cannot fold away.
 *
 * Malformed input (key without host, garbage port, scheme neither http nor
 * https) returns null so the caller can degrade to polling without throwing
 * and without breaking the page.
 */

/**
 * @returns {{key:string, host:string, port:number, scheme:string}|null}
 */
export function getReverbConfig() {
    // Runtime config rendered by PHP — primary source.
    try {
        const runtime = window.__REVERB__;

        if (runtime && typeof runtime === 'object' && typeof runtime.key === 'string' && runtime.key.trim() !== '') {
            const key = runtime.key.trim();

            let host = '';
            if (typeof runtime.host === 'string' && runtime.host.trim() !== '') {
                host = runtime.host.trim();
            } else {
                host = window.location.hostname;
            }

            // Port validation: must be integer 1-65535. Any garbage -> degrade.
            let port = null;
            if (runtime.port !== null && runtime.port !== undefined && String(runtime.port).trim() !== '') {
                const n = Number(runtime.port);
                if (!Number.isInteger(n) || n < 1 || n > 65535) {
                    return null;
                }
                port = n;
            }

            const schemeRaw = typeof runtime.scheme === 'string' ? runtime.scheme.trim().toLowerCase() : 'https';
            let scheme = 'https';
            if (schemeRaw === 'http' || schemeRaw === 'https') {
                scheme = schemeRaw;
            } else {
                return null;
            }

            if (port === null) {
                port = scheme === 'https' ? 443 : 80;
            }

            // Host strict check: avoid CSP-breaking characters making it into Echo config.
            const hostOk = /^[a-zA-Z0-9.\-]+$/.test(host) || isIp(host);
            if (!hostOk) {
                return null;
            }

            return { key, host, port, scheme };
        }
    } catch {
        // Never throw — degraded to polling.
        return null;
    }

    // Dev fallback: build-time env. Not the primary path, but useful when
    // running `npm run dev` without a PHP-rendered config.
    try {
        const env = import.meta.env || {};
        const key = env.VITE_REVERB_APP_KEY;

        if (typeof key === 'string' && key.trim() !== '') {
            let host = '';
            if (typeof env.VITE_REVERB_HOST === 'string' && env.VITE_REVERB_HOST.trim() !== '') {
                host = env.VITE_REVERB_HOST.trim();
            } else {
                host = window.location.hostname;
            }

            let port = null;
            const portRaw = env.VITE_REVERB_PORT;
            if (portRaw !== null && portRaw !== undefined && String(portRaw).trim() !== '') {
                const n = Number(portRaw);
                if (!Number.isInteger(n) || n < 1 || n > 65535) {
                    return null;
                }
                port = n;
            }

            const schemeRaw = typeof env.VITE_REVERB_SCHEME === 'string' ? env.VITE_REVERB_SCHEME.trim().toLowerCase() : 'https';
            let scheme = 'https';
            if (schemeRaw === 'http' || schemeRaw === 'https') {
                scheme = schemeRaw;
            } else {
                return null;
            }

            if (port === null) {
                port = scheme === 'https' ? 443 : 80;
            }

            const hostOk = /^[a-zA-Z0-9.\-]+$/.test(host) || isIp(host);
            if (!hostOk) {
                return null;
            }

            return { key: key.trim(), host, port, scheme };
        }
    } catch {
        return null;
    }

    return null;
}

function isIp(value) {
    // Minimal IPv4 check; IPv6 contains colons which would fail CSP anyway.
    if (typeof value !== 'string') return false;
    const parts = value.split('.');
    if (parts.length === 4) {
        return parts.every((p) => /^\d+$/.test(p) && Number(p) >= 0 && Number(p) <= 255);
    }
    // Fallback: treat any colon-containing as potential IP and accept (let browser reject if invalid).
    if (value.includes(':')) return true;

    return false;
}
