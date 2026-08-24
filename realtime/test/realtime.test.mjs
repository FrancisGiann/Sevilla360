import test from 'node:test';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import { canSubscribe, rememberEvent, reconnectDelay, removeSocketFromChannels, verifyConnectionToken } from '../lib.mjs';

function token(key, claims = {}) {
  const head = Buffer.from(JSON.stringify({ alg: 'HS256', typ: 'JWT' })).toString('base64url');
  const body = Buffer.from(JSON.stringify({ sub: '42', role: 'customer', iat: 100, exp: 160, jti: 'j1', ...claims })).toString('base64url');
  const sig = crypto.createHmac('sha256', key).update(`${head}.${body}`).digest('base64url');
  return `${head}.${body}.${sig}`;
}

test('connection tokens require valid signature and time bounds', () => {
  const key = 'x'.repeat(32);
  assert.equal(verifyConnectionToken(token(key), key, 120).sub, '42');
  assert.equal(verifyConnectionToken(token(key), 'y'.repeat(32), 120), null);
  assert.equal(verifyConnectionToken(token(key, { exp: 100 }), key, 120), null);
});
test('channel authorization is tenant-scoped', () => {
  assert.equal(canSubscribe({ sub: '42', role: 'customer' }, 'customer:42'), true);
  assert.equal(canSubscribe({ sub: '42', role: 'customer' }, 'customer:43'), false);
  assert.equal(canSubscribe({ sub: '9', role: 'staff' }, 'admin'), true);
});
test('socket cleanup removes empty channel sets', () => {
  const channels = new Map([
    ['admin', new Set(['closed'])],
    ['customer:42', new Set(['closed', 'active'])],
  ]);
  removeSocketFromChannels(channels, 'closed');
  assert.equal(channels.has('admin'), false);
  assert.deepEqual([...channels.get('customer:42')], ['active']);
});
test('event dedupe is bounded and reconnect backoff grows', () => {
  const seen = new Set();
  assert.equal(rememberEvent(seen, 'a'), true);
  assert.equal(rememberEvent(seen, 'a'), false);
  for (let i = 0; i < 300; i += 1) rememberEvent(seen, String(i), 16);
  assert.ok(seen.size <= 16);
  assert.ok(reconnectDelay(3, 10) > reconnectDelay(0, 10));
});
test('transactional outbox fixture publishes only committed events', () => {
  const outbox = [];
  const transaction = { staged: [], commit() { outbox.push(...this.staged); this.staged = []; }, rollback() { this.staged = []; } };
  transaction.staged.push({ event_id: 'committed-1', channel: 'admin' });
  transaction.rollback();
  assert.equal(outbox.length, 0);
  transaction.staged.push({ event_id: 'committed-2', channel: 'customer:42' });
  transaction.commit();
  assert.deepEqual(outbox.map(event => event.event_id), ['committed-2']);
  assert.equal(canSubscribe({ sub: '42', role: 'customer' }, outbox[0].channel), true);
});
