import crypto from 'node:crypto';
import { pool } from './db.js';

export const SESSION_COOKIE = 'silver_session';
export const CSRF_COOKIE = 'silver_csrf';
const configuredTtl = Number(process.env.SESSION_TTL_MINUTES || 60);
const ttlMinutes = Number.isInteger(configuredTtl) && configuredTtl > 0 && configuredTtl <= 1440 ? configuredTtl : 60;

function hashToken(token) {
  return crypto.createHash('sha256').update(token).digest();
}

export async function createSession(userId, request) {
  const token = crypto.randomBytes(32).toString('base64url');
  const expiresAt = new Date(Date.now() + ttlMinutes * 60_000);
  await pool.execute(
    'INSERT INTO sessions (token_hash, user_id, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)',
    [hashToken(token), userId, request.ip, request.get('user-agent')?.slice(0, 255) || null, expiresAt]
  );
  return { token, expiresAt };
}

export async function destroySession(token) {
  if (token) await pool.execute('DELETE FROM sessions WHERE token_hash = ?', [hashToken(token)]);
}

export async function requireAuth(request, response, next) {
  try {
    const token = request.cookies[SESSION_COOKIE];
    if (!token) return response.status(401).json({ error: 'Authentication required' });

    const [rows] = await pool.execute(
      `SELECT s.id AS session_id, s.user_id, s.expires_at, u.username, u.display_name, u.email, u.role, u.date_of_birth, u.onboarding_complete, u.account_status
       FROM sessions s INNER JOIN users u ON u.id = s.user_id
       WHERE s.token_hash = ?
         AND s.expires_at > CURRENT_TIMESTAMP
         AND s.last_seen_at > DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ${ttlMinutes} MINUTE)
         AND u.account_status = 'active'
       LIMIT 1`,
      [hashToken(token)]
    );
    if (!rows.length) return response.status(401).json({ error: 'Authentication required' });

    await pool.execute('UPDATE sessions SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?', [rows[0].session_id]);
    request.user = { id: rows[0].user_id, username: rows[0].username, displayName: rows[0].display_name, email: rows[0].email, role: rows[0].role, dateOfBirth: rows[0].date_of_birth, onboardingComplete: Boolean(rows[0].onboarding_complete) };
    next();
  } catch (error) {
    next(error);
  }
}

export function issueCsrfToken(response) {
  const token = crypto.randomBytes(32).toString('base64url');
  response.cookie(CSRF_COOKIE, token, {
    httpOnly: false,
    secure: process.env.NODE_ENV === 'production',
    sameSite: 'lax',
    path: '/',
    maxAge: 60 * 60 * 1000
  });
  return token;
}

export function requireCsrf(request, response, next) {
  const cookieToken = request.cookies[CSRF_COOKIE];
  const headerToken = request.get('x-csrf-token');
  if (!cookieToken || !headerToken || cookieToken.length !== headerToken.length || !crypto.timingSafeEqual(Buffer.from(cookieToken), Buffer.from(headerToken))) {
    return response.status(403).json({ error: 'CSRF validation failed' });
  }
  next();
}

export function setSessionCookie(response, token, expiresAt) {
  response.cookie(SESSION_COOKIE, token, {
    httpOnly: true,
    secure: process.env.NODE_ENV === 'production',
    sameSite: 'lax',
    path: '/',
    expires: expiresAt
  });
}

export function clearSessionCookie(response) {
  response.clearCookie(SESSION_COOKIE, { httpOnly: true, sameSite: 'lax', secure: process.env.NODE_ENV === 'production', path: '/' });
}
