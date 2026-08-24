import http from 'node:http';
import { WebSocketServer } from 'ws';
import { createClient } from 'redis';
import mysql from 'mysql2/promise';
import { canSubscribe, rememberEvent, removeSocketFromChannels, verifyConnectionToken } from './lib.mjs';

const port = Number(process.env.REALTIME_WS_PORT || 8787);
const signingKey = process.env.REALTIME_SIGNING_KEY || '';
const redisUrl = process.env.REALTIME_REDIS_URL || 'redis://127.0.0.1:6379';
const allowedOrigins = (process.env.REALTIME_ALLOWED_ORIGINS || '').split(',').map(value => value.trim()).filter(Boolean);
if (!Number.isInteger(port) || port < 1024 || port > 65535) throw new Error('REALTIME_WS_PORT must be between 1024 and 65535.');
if (signingKey.length < 32) throw new Error('REALTIME_SIGNING_KEY must contain at least 32 characters.');
if (!allowedOrigins.length) throw new Error('REALTIME_ALLOWED_ORIGINS must contain at least one exact origin.');

const pool = mysql.createPool({
  host: process.env.DB_HOST || '127.0.0.1', user: process.env.DB_USER || 'root',
  password: process.env.DB_PASS || '', database: process.env.DB_NAME || 'sevilla360',
  waitForConnections: true, connectionLimit: 4,
});
const publisher = createClient({ url: redisUrl });
const subscriber = publisher.duplicate();
publisher.on('error', error => console.error('Realtime Redis publisher error:', error.message));
subscriber.on('error', error => console.error('Realtime Redis subscriber error:', error.message));
await publisher.connect();
await subscriber.connect();

const clients = new Map();
const seenByClient = new WeakMap();
function addClient(channel, socket) { if (!clients.has(channel)) clients.set(channel, new Set()); clients.get(channel).add(socket); }
function removeClient(socket) { removeSocketFromChannels(clients, socket); }

await subscriber.pSubscribe('sevilla360:channel:*', (raw) => {
  let event; try { event = JSON.parse(raw); } catch (_) { return; }
  const members = clients.get(event.channel) || [];
  for (const socket of members) {
    if (socket.readyState !== 1) continue;
    const seen = seenByClient.get(socket);
    if (!rememberEvent(seen, event.event_id)) continue;
    socket.send(JSON.stringify(event));
  }
});

async function publishOutbox() {
  const [rows] = await pool.query(
    `SELECT id, event_id, channel, event_type, payload_json
       FROM notification_outbox
      WHERE published_at IS NULL
        AND (claimed_at IS NULL OR claimed_at < DATE_SUB(NOW(), INTERVAL 30 SECOND))
      ORDER BY id ASC LIMIT 50`,
  );
  for (const row of rows) {
    const [claimed] = await pool.query('UPDATE notification_outbox SET claimed_at = NOW(), attempts = attempts + 1 WHERE id = ? AND published_at IS NULL AND (claimed_at IS NULL OR claimed_at < DATE_SUB(NOW(), INTERVAL 30 SECOND))', [row.id]);
    if (!claimed.affectedRows) continue;
    let payload;
    try { payload = typeof row.payload_json === 'string' ? JSON.parse(row.payload_json) : row.payload_json; } catch (_) { console.error(`Invalid outbox JSON for row ${row.id}`); continue; }
    const event = { event_id: row.event_id, channel: row.channel, event_type: row.event_type, payload };
    try {
      await publisher.publish(`sevilla360:channel:${row.channel}`, JSON.stringify(event));
      await pool.query('UPDATE notification_outbox SET published_at = NOW() WHERE id = ? AND published_at IS NULL', [row.id]);
    } catch (error) {
      console.error('Realtime publish failed:', error.message);
    }
  }
}
setInterval(() => publishOutbox().catch(error => console.error('Outbox poll failed:', error.message)), 1000);
publishOutbox().catch(error => console.error('Initial outbox poll failed:', error.message));

const server = http.createServer((request, response) => { response.writeHead(404); response.end(); });
const wss = new WebSocketServer({ server, maxPayload: 4096, perMessageDeflate: false });
wss.on('connection', (socket, request) => {
  const origin = request.headers.origin || '';
  if (!allowedOrigins.includes(origin)) { socket.close(1008, 'Origin not allowed'); return; }
  let claims = null;
  const authTimer = setTimeout(() => socket.close(1008, 'Authentication timeout'), 5000);
  socket.on('message', raw => {
    let command; try { command = JSON.parse(raw.toString()); } catch (_) { return; }
    if (!claims) {
      if (command.type !== 'auth' || typeof command.token !== 'string') { socket.close(1008, 'Authentication required'); return; }
      claims = verifyConnectionToken(command.token, signingKey);
      if (!claims) { socket.close(1008, 'Authentication failed'); return; }
      clearTimeout(authTimer);
      seenByClient.set(socket, new Set());
      socket.send(JSON.stringify({ type: 'authenticated' }));
      const remaining = Math.max(1000, (claims.exp * 1000) - Date.now());
      setTimeout(() => socket.close(1000, 'Token expired'), remaining).unref();
      return;
    }
    if (command.type !== 'subscribe' || !canSubscribe(claims, command.channel)) { socket.close(1008, 'Channel not authorized'); return; }
    addClient(command.channel, socket);
    socket.send(JSON.stringify({ type: 'subscribed', channel: command.channel }));
  });
  socket.on('close', () => { clearTimeout(authTimer); removeClient(socket); });
});
server.listen(port, () => console.log(`Sevilla360 realtime gateway listening on ${port}`));
