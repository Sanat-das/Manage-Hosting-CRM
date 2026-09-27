'use strict';

const net = require('net');

function createRelay({ connectUpstream, listenHost = '127.0.0.1', connectTimeoutMs = 15000 } = {}) {
  return new Promise((resolve, reject) => {
    if (typeof connectUpstream !== 'function') {
      reject(new TypeError('createRelay requires connectUpstream() factory'));
      return;
    }

    const server = net.createServer();
    let claimed = false;
    let clientSocket = null;
    let upstream = null;
    let closed = false;
    let timer = null;

    function close() {
      if (closed) {
        return;
      }
      closed = true;
      if (timer) {
        clearTimeout(timer);
        timer = null;
      }
      if (clientSocket && !clientSocket.destroyed) {
        try {
          clientSocket.destroy();
        } catch (err) {
          // ignore — shutting down
        }
      }
      if (upstream) {
        try {
          upstream.close();
        } catch (err) {
          // ignore — shutting down
        }
      }
      try {
        server.close();
      } catch (err) {
        // ignore — already closed
      }
      server.removeAllListeners('connection');
      server.removeAllListeners('error');
      console.log('pve-vnc-relay closed');
    }

    timer = setTimeout(() => {
      console.log('pve-vnc-relay connect timeout, closing listener');
      close();
    }, connectTimeoutMs);
    if (timer && typeof timer.unref === 'function') {
      timer.unref();
    }

    server.on('connection', (socket) => {
      if (claimed) {
        socket.destroy();
        return;
      }
      claimed = true;
      if (timer) {
        clearTimeout(timer);
        timer = null;
      }
      clientSocket = socket;
      socket.on('error', () => {});

      Promise.resolve()
        .then(() => connectUpstream())
        .then((u) => {
          if (closed) {
            try {
              socket.destroy();
            } catch (err) {
              // ignore
            }
            try {
              u.close();
            } catch (err) {
              // ignore
            }
            return;
          }
          upstream = u;
          console.log('pve-vnc-relay upstream connected, bridging');

          socket.on('data', (chunk) => {
            try {
              upstream.send(Buffer.from(chunk));
            } catch (err) {
              process.stderr.write('pve-vnc-relay upstream send failed, closing\n');
              close();
            }
          });

          upstream.onMessage((msg) => {
            if (socket.destroyed) {
              return;
            }
            try {
              socket.write(Buffer.from(msg));
            } catch (err) {
              process.stderr.write('pve-vnc-relay client write failed, closing\n');
              close();
            }
          });

          const tearDown = () => close();
          socket.on('close', tearDown);
          socket.on('error', tearDown);
          upstream.onClose(tearDown);
        })
        .catch((err) => {
          process.stderr.write('pve-vnc-relay upstream connect failed, closing\n');
          try {
            socket.destroy();
          } catch (destroyErr) {
            // ignore
          }
          close();
        });
    });

    server.on('error', (err) => {
      if (!server.listening) {
        if (timer) {
          clearTimeout(timer);
          timer = null;
        }
        reject(err);
        return;
      }
      process.stderr.write('pve-vnc-relay server error, closing\n');
      close();
    });

    server.listen(0, listenHost, () => {
      const address = server.address();
      const port = address && address.port;
      console.log(`pve-vnc-relay listening on ${listenHost}:${port} (awaiting guacd)`);
      resolve({ port, close });
    });
  });
}

module.exports = { createRelay };
