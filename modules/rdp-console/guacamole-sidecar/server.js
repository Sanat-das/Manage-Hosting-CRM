'use strict';

const GuacamoleLite = require('guacamole-lite');
const WebSocket = require('ws');
const { createRelay } = require('./pve-vnc-relay');

const SECRET = process.env.GUACAMOLE_SECRET;

if (!SECRET || SECRET.length < 16) {
  process.stderr.write(
    'FATAL: GUACAMOLE_SECRET is missing or shorter than 16 characters. ' +
      'Set it to the exact same value configured for the rdp-console Laravel module.\n'
  );
  process.exit(1);
}

const WS_PORT = Number(process.env.GUAC_WS_PORT || 8080);
const GUACD_HOST = process.env.GUACD_HOST || '127.0.0.1';
const GUACD_PORT = Number(process.env.GUACD_PORT || 4822);

function buildPveVncUrl(pve) {
  const port = Number(pve.apiPort || 8006);
  const node = encodeURIComponent(pve.node);
  const vmid = Number(pve.vmid);
  const vncPort = encodeURIComponent(String(pve.vncPort));
  const ticket = encodeURIComponent(pve.vncTicket);
  return `wss://${pve.apiHost}:${port}/api2/json/nodes/${node}/qemu/${vmid}/vncwebsocket?port=${vncPort}&vncticket=${ticket}`;
}

function connectPveUpstream(pve) {
  return () =>
    new Promise((resolve, reject) => {
      let ws;
      try {
        ws = new WebSocket(buildPveVncUrl(pve), {
          headers: { Authorization: `PVEAPIToken=${pve.tokenId}=${pve.tokenSecret}` },
          rejectUnauthorized: pve.verifyTls !== false
        });
      } catch (err) {
        reject(err);
        return;
      }
      let opened = false;
      ws.once('open', () => {
        opened = true;
        resolve({
          send(buffer) {
            if (ws.readyState === WebSocket.OPEN) {
              ws.send(buffer, { binary: true });
            }
          },
          onMessage(cb) {
            ws.on('message', cb);
          },
          onClose(cb) {
            ws.on('close', cb);
          },
          close() {
            try {
              ws.close();
            } catch (err) {
              // ignore — shutting down
            }
          }
        });
      });
      ws.once('error', (err) => {
        if (!opened) {
          reject(err);
        }
      });
      ws.once('close', () => {
        if (!opened) {
          reject(new Error('pve upstream closed before open'));
        }
      });
    });
}

const guacamoleLite = new GuacamoleLite(
  { port: WS_PORT },
  { host: GUACD_HOST, port: GUACD_PORT },
  {
    cypher: 'AES-256-CBC',
    key: SECRET,
    processConnectionSettings: (settings, callback) => {
      const s = settings.connection && settings.connection.settings;
      const exp = s && s.exp;
      if (!exp || Date.now() / 1000 > Number(exp)) {
        callback(new Error('token expired'));
        return;
      }
      const pve = settings.pve;
      if (!pve) {
        callback(undefined, settings);
        return;
      }
      if (!pve.apiHost || !pve.node || !pve.vmid || !pve.vncTicket || !pve.tokenId || !pve.tokenSecret) {
        process.stderr.write('pve-vnc-relay incomplete pve block, rejecting\n');
        callback(new Error('invalid pve connection'));
        return;
      }
      createRelay({ connectUpstream: connectPveUpstream(pve) })
        .then(({ port }) => {
          settings.connection.settings = {
            hostname: '127.0.0.1',
            port,
            password: pve.vncTicket,
            exp: s.exp
          };
          delete settings.pve;
          console.log(`pve-vnc-relay started for node ${pve.node} vmid ${pve.vmid} on 127.0.0.1:${port}`);
          callback(undefined, settings);
        })
        .catch((err) => {
          process.stderr.write('pve-vnc-relay failed to start, rejecting\n');
          callback(err instanceof Error ? err : new Error('pve relay failed'));
        });
    }
  }
);

console.log(
  `guacamole-sidecar listening on ws://0.0.0.0:${WS_PORT}/ (guacd ${GUACD_HOST}:${GUACD_PORT}, AES-256-CBC)`
);

module.exports = guacamoleLite;
