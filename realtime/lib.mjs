import crypto from 'node:crypto';

export function base64url(value) {
  return Buffer.from(value).toString('base64url');
}

export function verifyConnectionToken(token, signingKey, now = Math.floor(Date.now() / 1000)) {
  if (typeof token !== 'string' || typeof signingKey !== 'string' || signingKey.length < 32) return null;
  const parts = token.split('.');
  if (parts.length !== 3) return null;
  let header; let payload;
  try {
    header = JSON.parse(Buffer.from(parts[0], 'base64url').toString('utf8'));
    payload = JSON.parse(Buffer.from(parts[1], 'base64url').toString('utf8'));
  } catch (_) { return null; }
  if (header.alg !== 'HS256' || header.typ !== 'JWT') return null;
  const expected = crypto.createHmac('sha256', signingKey).update(`${parts[0]}.${parts[1]}`).digest('base64url');
  const actualBuffer = Buffer.from(parts[2]);
  const expectedBuffer = Buffer.from(expected);
  if (actualBuffer.length !== expectedBuffer.length || !crypto.timingSafeEqual(actualBuffer, expectedBuffer)) return null;
  if (!/^[1-9][0-9]*$/.test(String(payload.sub)) || !['customer', 'staff', 'admin'].includes(payload.role)) return null;
  if (!Number.isInteger(payload.iat) || !Number.isInteger(payload.exp) || !payload.jti || payload.exp <= now || payload.iat > now + 30 || payload.exp - payload.iat > 300) return null;
  return payload;
}

export function canSubscribe(claims, channel) {
  if (!claims || typeof channel !== 'string') return false;
  return claims.role === 'customer' ? channel === `customer:${claims.sub}` : channel === 'admin';
}

export function removeSocketFromChannels(channels, socket) {
  for (const [channel, members] of channels.entries()) {
    members.delete(socket);
    if (members.size === 0) channels.delete(channel);
  }
}

export function rememberEvent(seen, eventId, max = 256) {
  if (!eventId || seen.has(eventId)) return false;
  seen.add(eventId);
  while (seen.size > max) seen.delete(seen.values().next().value);
  return true;
}

export function reconnectDelay(attempt, random = 0) {
  return Math.min(30000, 500 * (2 ** Math.min(Math.max(0, attempt), 6))) + Math.max(0, Math.min(250, random));
}
