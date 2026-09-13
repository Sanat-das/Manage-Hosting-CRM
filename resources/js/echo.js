/**
 * Laravel Echo bootstrap for the Slack-like chat.
 *
 * Loaded on every panel page. It must never throw: if Reverb is not configured
 * (a fresh install, or an install that has chosen not to run the websocket
 * server) the rest of the admin JS still has to work, and the chat falls back
 * to polling. So every failure here is recorded on `realtime` and swallowed —
 * an uncaught error in this module would take the whole bundle down with it.
 */
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { getReverbConfig } from './reverb-config.js';

// laravel-echo's reverb/pusher connector looks for this global.
window.Pusher = Pusher;

/**
 * The state the chat UI reads to decide between websockets and polling, and to
 * decide whether to show the "Realtime disconnected" banner.
 */
export const realtime = {
    /** True once an Echo instance exists. Not the same as "connected". */
    enabled: false,
    /** The Echo instance, or null. */
    echo: null,
    /** Why realtime is unavailable, for the banner and the console. */
    reason: '',
};

const cfg = getReverbConfig();

if (!cfg) {
    // Not an error: a panel with no Reverb configured is a supported setup.
    // The null comes from missing `window.__REVERB__` (most installs) or from
    // malformed runtime/build-time values — all degrade to polling.
    realtime.reason = 'Reverb is not configured — real-time chat is off, polling instead.';
} else {
    try {
        const forceTLS = cfg.scheme === 'https';

        realtime.echo = new Echo({
            broadcaster: 'reverb',
            key: cfg.key,
            wsHost: cfg.host,
            wsPort: cfg.port,
            wssPort: cfg.port,
            forceTLS,
            enabledTransports: ['ws', 'wss'],
            // The channel authorisation endpoint is session-authenticated, so
            // the CSRF token has to travel with it.
            auth: {
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
            },
        });

        realtime.enabled = true;
        window.Echo = realtime.echo;
    } catch (error) {
        realtime.reason = `Echo failed to initialise: ${error?.message || error}`;
        realtime.enabled = false;
        realtime.echo = null;
        // Deliberately not rethrown — see the file docblock.
        console.warn('[chat]', realtime.reason);
    }
}

/**
 * Subscribe to a callback for connection state changes.
 *
 * Returns a no-op unsubscribe when realtime is unavailable, so callers never
 * need to branch on it.
 *
 * @param {(state: string) => void} onChange receives 'connected',
 *        'connecting', 'disconnected', 'unavailable' or 'failed'.
 * @returns {() => void}
 */
export function onConnectionStateChange(onChange) {
    const connector = realtime.echo?.connector?.pusher?.connection;

    if (!connector) {
        onChange('unavailable');

        return () => {};
    }

    const handler = (states) => onChange(states.current);

    connector.bind('state_change', handler);
    onChange(connector.state);

    return () => connector.unbind('state_change', handler);
}

export default realtime;
