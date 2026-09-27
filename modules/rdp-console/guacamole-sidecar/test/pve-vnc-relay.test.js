'use strict';

const assert = require('node:assert/strict');
const net = require('node:net');

const { createRelay } = require('../pve-vnc-relay');

function makeFakeUpstream() {
  const messageCbs = [];
  const closeCbs = [];
  let closed = false;
  const sent = [];
  const instance = {
    send(buffer) {
      sent.push(Buffer.from(buffer));
    },
    onMessage(cb) {
      messageCbs.push(cb);
    },
    onClose(cb) {
      closeCbs.push(cb);
    },
    close() {
      if (closed) {
        return;
      }
      closed = true;
      for (const cb of closeCbs.splice(0)) {
        cb();
      }
    }
  };
  return {
    instance,
    sent,
    emitMessage(buffer) {
      for (const cb of messageCbs.slice()) {
        cb(Buffer.from(buffer));
      }
    },
    fireClose() {
      instance.close();
    },
    get closed() {
      return closed;
    }
  };
}

function connectTcp(port, host = '127.0.0.1') {
  return new Promise((resolve, reject) => {
    const socket = net.createConnection({ port, host }, () => resolve(socket));
    socket.once('error', reject);
  });
}

function onceEvent(emitter, event) {
  return new Promise((resolve) => emitter.once(event, (...args) => resolve(args)));
}

function waitFor(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function testUpstreamToClient() {
  let fake = null;
  const relay = await createRelay({
    connectUpstream: async () => {
      fake = makeFakeUpstream();
      return fake.instance;
    }
  });
  try {
    const client = await connectTcp(relay.port);
    client.on('error', () => {});
    await waitFor(50);
    fake.emitMessage(Buffer.from('rfb-from-pve'));
    const [chunk] = await onceEvent(client, 'data');
    assert.equal(chunk.toString(), 'rfb-from-pve');
    client.destroy();
    await waitFor(50);
  } finally {
    relay.close();
  }
  console.log('ok - bytes sent by the fake upstream reach the TCP client');
}

async function testClientToUpstream() {
  let fake = null;
  const relay = await createRelay({
    connectUpstream: async () => {
      fake = makeFakeUpstream();
      return fake.instance;
    }
  });
  try {
    const client = await connectTcp(relay.port);
    client.on('error', () => {});
    await waitFor(50);
    client.write(Buffer.from('rfb-from-guacd'));
    await waitFor(100);
    assert.equal(fake.sent.length, 1);
    assert.equal(fake.sent[0].toString(), 'rfb-from-guacd');
    client.destroy();
    await waitFor(50);
  } finally {
    relay.close();
  }
  console.log('ok - bytes written by the TCP client reach the upstream');
}

async function testClientCloseTearsDown() {
  let fake = null;
  const relay = await createRelay({
    connectUpstream: async () => {
      fake = makeFakeUpstream();
      return fake.instance;
    }
  });
  const client = await connectTcp(relay.port);
  client.on('error', () => {});
  await waitFor(50);
  assert.equal(fake.closed, false);
  client.destroy();
  await onceEvent(client, 'close');
  await waitFor(100);
  assert.equal(fake.closed, true);
  // Listener must be gone: a fresh connect should fail.
  await assert.rejects(connectTcp(relay.port));
  relay.close();
  console.log('ok - closing the TCP client tears the relay down');
}

async function testUpstreamCloseTearsDown() {
  let fake = null;
  const relay = await createRelay({
    connectUpstream: async () => {
      fake = makeFakeUpstream();
      return fake.instance;
    }
  });
  const client = await connectTcp(relay.port);
  client.on('error', () => {});
  await waitFor(50);
  fake.fireClose();
  await onceEvent(client, 'close');
  await waitFor(50);
  await assert.rejects(connectTcp(relay.port));
  relay.close();
  console.log('ok - closing the upstream tears the relay down');
}

async function testConnectTimeout() {
  const relay = await createRelay({
    connectUpstream: async () => {
      throw new Error('must not be called');
    },
    connectTimeoutMs: 100
  });
  const port = relay.port;
  await waitFor(400);
  await assert.rejects(connectTcp(port));
  relay.close();
  console.log('ok - the connect timeout closes an unclaimed listener');
}

async function testSecondConnectionDestroyed() {
  let calls = 0;
  const relay = await createRelay({
    connectUpstream: async () => {
      calls += 1;
      const fake = makeFakeUpstream();
      return fake.instance;
    }
  });
  try {
    const first = await connectTcp(relay.port);
    first.on('error', () => {});
    await waitFor(50);
    const second = net.createConnection({ port: relay.port, host: '127.0.0.1' });
    second.on('error', () => {});
    const closed = await Promise.race([
      onceEvent(second, 'close').then(() => true),
      waitFor(1000).then(() => false)
    ]);
    assert.equal(closed, true);
    assert.equal(calls, 1);
    assert.equal(first.destroyed, false);
    first.destroy();
    await waitFor(50);
  } finally {
    relay.close();
  }
  console.log('ok - a second TCP connection is refused/destroyed');
}

async function main() {
  await testUpstreamToClient();
  await testClientToUpstream();
  await testClientCloseTearsDown();
  await testUpstreamCloseTearsDown();
  await testConnectTimeout();
  await testSecondConnectionDestroyed();
  console.log('all pve-vnc-relay assertions passed');
}

main().catch((err) => {
  console.error(err);
  process.exitCode = 1;
});
