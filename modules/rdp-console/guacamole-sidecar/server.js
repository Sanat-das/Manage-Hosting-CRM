'use strict';

const GuacamoleLite = require('guacamole-lite');

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
      callback(undefined, settings);
    }
  }
);

console.log(
  `guacamole-sidecar listening on ws://0.0.0.0:${WS_PORT}/ (guacd ${GUACD_HOST}:${GUACD_PORT}, AES-256-CBC)`
);

module.exports = guacamoleLite;
