import crypto from 'node:crypto';

const loopbackAddresses = new Set(['127.0.0.1', '::1', '::ffff:127.0.0.1']);
const readPaths = new Set(['/auth/me', '/tickets']);

export function isLoopbackSocket(request) {
  return loopbackAddresses.has(request.socket?.remoteAddress);
}

export function hasPlausibleSessionToken(request) {
  const token = request.cookies?.silver_session;
  return typeof token === 'string' && token.length >= 40 && token.length <= 100
    && !/[^A-Za-z0-9_-]/.test(token);
}

function hasProxyHeaders(request) {
  // Apache's public /api proxy adds forwarding headers even though its TCP
  // connection is local. PHP's direct loopback cURL call does not send them.
  // Treat presence as untrusted, including empty or claimed-loopback values.
  return Object.keys(request.headers || {}).some((name) => {
    const header = name.toLowerCase();
    return header === 'forwarded' || header.startsWith('x-forwarded-') || header === 'x-real-ip';
  });
}

/** Called by the generic limiter while /api is mounted, so path is /auth/me or /tickets. */
export function isLoopbackSessionRead(request) {
  return request.method === 'GET' && readPaths.has(request.path)
    && isLoopbackSocket(request) && !hasProxyHeaders(request) && hasPlausibleSessionToken(request);
}

export function loopbackSessionKey(request) {
  return crypto.createHash('sha256').update(request.cookies.silver_session).digest('hex');
}
