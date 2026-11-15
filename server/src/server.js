import 'dotenv/config';
import crypto from 'node:crypto';
import express from 'express';
import cors from 'cors';
import cookieParser from 'cookie-parser';
import helmet from 'helmet';
import rateLimit from 'express-rate-limit';
import nodemailer from 'nodemailer';
import { z } from 'zod';
import { pool, withTransaction } from './db.js';
import { clearSessionCookie, createSession, destroySession, issueCsrfToken, requireAuth, requireCsrf, setSessionCookie } from './auth.js';
import { ROOM_TURNOVER_MINUTES, roomAvailableAt } from './screening-timing.js';
import { nameAfterGoogleSignIn } from './google-profile.js';
import { hasPlausibleSessionToken, isLoopbackSessionRead, isLoopbackSocket, loopbackSessionKey } from './rate-limit-policy.js';

const app = express();
const port = Number(process.env.PORT || 4000);
const clientOrigin = process.env.CLIENT_ORIGIN || 'http://localhost:5173';
const phpClientOrigin = (() => {
  const url = new URL(process.env.PHP_CLIENT_ORIGIN || 'http://localhost/SilverVillage');
  if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || url.search || url.hash) {
    throw new Error('PHP_CLIENT_ORIGIN must be an HTTP(S) base URL without credentials, a query, or a fragment.');
  }
  return url.href.replace(/\/+$/, '');
})();
app.set('trust proxy', 1);
app.use(helmet());
app.use(cors({ origin: clientOrigin, credentials: true }));
app.use(express.json({ limit: '100kb' }));
app.use(cookieParser());
const apiRateLimitOptions = { windowMs: 15 * 60_000, limit: 300, standardHeaders: 'draft-7', legacyHeaders: false, message: { error: 'Too many requests. Please try again shortly.' } };
const googleSignInLimiter = rateLimit({ windowMs: 15 * 60_000, limit: 40, standardHeaders: 'draft-7', legacyHeaders: false, message: { error: 'Too many sign-in requests. Please try again shortly.' } });
const loopbackSessionReadLimiter = rateLimit({
  windowMs: 15 * 60_000,
  limit: 600,
  standardHeaders: 'draft-7',
  legacyHeaders: false,
  message: { error: 'Too many account requests. Please try again shortly.' },
  skip: (request) => !isLoopbackSocket(request) || !hasPlausibleSessionToken(request),
  keyGenerator: loopbackSessionKey,
});
app.use('/api/admin', rateLimit(apiRateLimitOptions));
app.use('/api', rateLimit({ ...apiRateLimitOptions, skip: (request) => request.path === '/admin' || request.path.startsWith('/admin/') || isLoopbackSessionRead(request) }));
app.use(['/api/auth/me', '/api/tickets'], loopbackSessionReadLimiter);

const seatItem = z.object({ performanceId: z.coerce.number().int().positive(), seatIds: z.array(z.coerce.number().int().positive()).min(1).max(12) });
const bookingSchema = z.object({ customerPhone: z.string().trim().min(6).max(40), items: z.array(seatItem).min(1).max(10) });
const holdSchema = z.object({ seatIds: z.array(z.coerce.number().int().positive()).max(12) });
const calendarDateSchema = z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Use a valid date in YYYY-MM-DD format').refine((value) => {
  const parsed = new Date(`${value}T00:00:00Z`);
  return !Number.isNaN(parsed.valueOf()) && parsed.toISOString().slice(0, 10) === value;
}, 'Use a valid calendar date');
const movieThemeSchema = z.enum(['enchanted_castle', 'legends_and_courage', 'ocean_and_islands', 'hero_city', 'tech_and_arcade', 'worlds_of_imagination', 'sky_adventure', 'culinary_magic', 'animal_worlds']);
const localOrHttpAssetSchema = z.string().trim().max(500).refine((value) => {
  if (/^\/assets\/movies\/[a-z0-9]+(?:-[a-z0-9]+)*\/(?:poster|backdrop)\.webp$/.test(value)) return true;
  try { return ['http:', 'https:'].includes(new URL(value).protocol); } catch { return false; }
}, 'Use an approved /assets/movies/<slug>/poster.webp or backdrop.webp path, or an HTTP(S) URL');
const movieSchema = z.object({ title: z.string().trim().min(2).max(180), slug: z.string().trim().regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/).max(200), synopsis: z.string().trim().min(10), genre: z.string().trim().min(2).max(120), durationMinutes: z.coerce.number().int().min(30).max(360), ageRating: z.string().trim().min(1).max(20), posterUrl: localOrHttpAssetSchema, backdropUrl: localOrHttpAssetSchema, trailerUrl: z.string().url().max(500).optional().or(z.literal('')), themeKey: movieThemeSchema, status: z.enum(['draft', 'published', 'archived']), featured: z.coerce.boolean().optional(), runStartDate: calendarDateSchema, runEndDate: calendarDateSchema, salesOpenDate: calendarDateSchema.optional().or(z.literal('')) });
const movieUpdateSchema = z.object({ status: z.enum(['draft', 'published', 'archived']).optional(), themeKey: movieThemeSchema.optional(), runStartDate: calendarDateSchema.optional(), runEndDate: calendarDateSchema.optional(), salesOpenDate: calendarDateSchema.optional().or(z.literal('')) }).refine((value) => Object.values(value).some((field) => field !== undefined), 'Choose a movie change.');
const screeningSchema = z.object({ showId: z.coerce.number().int().positive(), roomId: z.coerce.number().int().positive(), startsAt: z.string().datetime({ offset: true }), format: z.string().trim().min(2).max(40), language: z.string().trim().min(2).max(60), unitPrice: z.coerce.number().positive().max(10_000_000) });
const profileSchema = z.object({ displayName: z.string().trim().min(2).max(160), dateOfBirth: z.coerce.date().max(new Date()) });
const accountActionSchema = z.object({ role: z.enum(['customer', 'staff', 'owner']).optional(), accountStatus: z.enum(['active', 'disabled']).optional() }).refine((value) => value.role || value.accountStatus, 'Choose an account change.');
const screeningUpdateSchema = z.object({ startsAt: z.string().datetime({ offset: true }).optional(), roomId: z.coerce.number().int().positive().optional(), format: z.string().trim().min(2).max(40).optional(), language: z.string().trim().min(2).max(60).optional(), unitPrice: z.coerce.number().positive().max(10_000_000).optional(), status: z.enum(['scheduled', 'cancelled']).optional(), cancellationReason: z.string().trim().max(255).optional() });
const screeningHistoryDeleteSchema = z.object({ ids: z.array(z.coerce.number().int().positive()).min(1).max(250) });
const ticketScanSchema = z.object({ code: z.string().trim().min(4).max(80) });
const reference = () => `SV-${Date.now()}-${crypto.randomBytes(4).toString('hex').toUpperCase()}`;
const ownerOnly = (request, response, next) => request.user?.role === 'owner' ? next() : response.status(403).json({ error: 'Owner access required' });
const staffOnly = (request, response, next) => ['owner', 'staff', 'manager'].includes(request.user?.role) ? next() : response.status(403).json({ error: 'Staff access required' });
const publicMovieFields = `id, title, slug, synopsis, genre, duration_minutes, age_rating, poster_url, backdrop_url, trailer_url, featured, status, theme_key, DATE_FORMAT(run_start_date, '%Y-%m-%d') AS run_start_date, DATE_FORMAT(run_end_date, '%Y-%m-%d') AS run_end_date, DATE_FORMAT(sales_open_date, '%Y-%m-%d') AS sales_open_date,
  CASE WHEN run_start_date IS NULL OR run_end_date IS NULL THEN 'ongoing' WHEN CURRENT_DATE < run_start_date THEN 'arriving_soon' WHEN CURRENT_DATE <= run_end_date THEN 'ongoing' ELSE 'ended' END AS lifecycle_status,
  IF(run_start_date IS NULL OR run_end_date IS NULL OR (CURRENT_DATE >= sales_open_date AND CURRENT_DATE <= run_end_date), 1, 0) AS tickets_on_sale`;
const adminScreeningFields = `p.id, p.starts_at, p.status, p.format, p.language, p.unit_price, p.capacity, p.seats_booked, p.cancelled_at, p.cancellation_reason,
  s.id AS show_id, s.title, r.id AS room_id, r.code AS room_code, r.name AS room_name,
  TIMESTAMPADD(MINUTE, s.duration_minutes, p.starts_at) AS ends_at,
  TIMESTAMPADD(MINUTE, s.duration_minutes + ${ROOM_TURNOVER_MINUTES}, p.starts_at) AS room_available_at,
  CURRENT_TIMESTAMP >= TIMESTAMPADD(MINUTE, s.duration_minutes, p.starts_at) AS is_completed`;
const publicMovieVisibility = `s.status = 'published' AND ((s.run_end_date IS NOT NULL AND s.run_end_date >= CURRENT_DATE) OR (s.run_end_date IS NULL AND EXISTS (SELECT 1 FROM performances visible_performance WHERE visible_performance.show_id = s.id AND visible_performance.status = 'scheduled' AND visible_performance.starts_at >= CURRENT_TIMESTAMP)))`;
const publicScreeningWindow = `s.status = 'published' AND p.status = 'scheduled' AND p.starts_at >= CURRENT_TIMESTAMP AND (s.run_start_date IS NULL OR DATE(p.starts_at) >= s.run_start_date) AND (s.run_end_date IS NULL OR DATE(p.starts_at) <= s.run_end_date)`;
const publicMovieNextScreeningJoin = `LEFT JOIN (
  SELECT future.show_id, MIN(future.starts_at) AS starts_at
  FROM performances future JOIN shows timed_show ON timed_show.id = future.show_id
  WHERE future.status = 'scheduled' AND future.history_deleted_at IS NULL
    AND future.starts_at >= CURRENT_TIMESTAMP
    AND (timed_show.run_start_date IS NULL OR DATE(future.starts_at) >= timed_show.run_start_date)
    AND (timed_show.run_end_date IS NULL OR DATE(future.starts_at) <= timed_show.run_end_date)
  GROUP BY future.show_id
) next_screening ON next_screening.show_id = s.id`;
const bookableScreeningWindow = `${publicScreeningWindow} AND (s.sales_open_date IS NULL OR CURRENT_DATE >= s.sales_open_date) AND (s.run_end_date IS NULL OR CURRENT_DATE <= s.run_end_date)`;
const activeTicketWindow = `s.status = 'published' AND p.status = 'scheduled' AND (s.run_end_date IS NULL OR CURRENT_DATE <= s.run_end_date) AND CURRENT_TIMESTAMP < TIMESTAMPADD(MINUTE, s.duration_minutes, p.starts_at)`;
const ticketUnavailableReason = `CASE WHEN p.status = 'cancelled' THEN 'Screening cancelled' WHEN s.status != 'published' THEN 'Movie no longer available' WHEN s.run_end_date IS NOT NULL AND CURRENT_DATE > s.run_end_date THEN 'Movie run finished' WHEN CURRENT_TIMESTAMP >= TIMESTAMPADD(MINUTE, s.duration_minutes, p.starts_at) THEN 'Screening finished' ELSE NULL END`;
async function archiveFinishedMovies() {
  // A finished run remains in the owner's history, but leaves every customer catalogue.
  await pool.execute(`UPDATE shows s SET s.status = 'archived'
    WHERE s.status = 'published' AND (
      (s.run_end_date IS NOT NULL AND s.run_end_date < CURRENT_DATE)
      OR ((s.run_start_date IS NULL OR s.run_start_date <= CURRENT_DATE)
        AND EXISTS (SELECT 1 FROM performances past
                    WHERE past.show_id = s.id AND past.status = 'scheduled'
                      AND past.history_deleted_at IS NULL
                      AND TIMESTAMPADD(MINUTE, s.duration_minutes, past.starts_at) <= CURRENT_TIMESTAMP)
        AND NOT EXISTS (SELECT 1 FROM performances upcoming
                        WHERE upcoming.show_id = s.id AND upcoming.status = 'scheduled'
                          AND upcoming.history_deleted_at IS NULL
                          AND TIMESTAMPADD(MINUTE, s.duration_minutes, upcoming.starts_at) > CURRENT_TIMESTAMP))
    )`);
}
async function requireCurrentRunForPublication(status, runEndDate) {
  if (status !== 'published') return;
  const [[row]] = await pool.execute("SELECT DATE_FORMAT(CURRENT_DATE, '%Y-%m-%d') AS today");
  if (runEndDate < row.today) throw Object.assign(new Error('This movie run has finished. Extend its end date before publishing it.'), { statusCode: 400 });
}
function oneCalendarMonthBefore(date) {
  const [year, month, day] = date.split('-').map(Number);
  const lastDayOfPreviousMonth = new Date(Date.UTC(year, month - 1, 0)).getUTCDate();
  return new Date(Date.UTC(year, month - 2, Math.min(day, lastDayOfPreviousMonth))).toISOString().slice(0, 10);
}
function databaseCalendarDate(value) {
  if (!value) return '';
  if (typeof value === 'string') return value.slice(0, 10);
  const year = value.getFullYear();
  const month = String(value.getMonth() + 1).padStart(2, '0');
  const day = String(value.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}
function normalizeMovieLifecycle(input) {
  if (input.runEndDate < input.runStartDate) throw Object.assign(new Error('Movie end date must be on or after its start date.'), { statusCode: 400 });
  const earliestSalesDate = oneCalendarMonthBefore(input.runStartDate);
  const salesOpenDate = input.salesOpenDate || earliestSalesDate;
  if (salesOpenDate < earliestSalesDate) throw Object.assign(new Error(`Ticket sales cannot open before ${earliestSalesDate}, one calendar month before the movie starts.`), { statusCode: 400 });
  if (salesOpenDate > input.runStartDate) throw Object.assign(new Error('Ticket sales must open on or before the movie start date.'), { statusCode: 400 });
  return { runStartDate: input.runStartDate, runEndDate: input.runEndDate, salesOpenDate };
}
function requireBookableScreening(screening) {
  if (Number(screening?.booking_open)) return;
  if (Number(screening?.before_sales)) throw Object.assign(new Error('Tickets for this movie are not on sale yet.'), { statusCode: 409 });
  throw Object.assign(new Error('This screening is outside the movie booking window or is no longer available.'), { statusCode: 409 });
}
const googleOwnerEmails = new Set((process.env.GOOGLE_OWNER_EMAILS || '').split(',').map((email) => email.trim().toLowerCase()).filter(Boolean));
const googleConfigured = () => process.env.GOOGLE_CLIENT_ID && process.env.GOOGLE_CLIENT_SECRET && process.env.GOOGLE_REDIRECT_URI;
const emailjsConfigured = () => process.env.EMAILJS_SERVICE_ID && process.env.EMAILJS_TEMPLATE_ID && process.env.EMAILJS_PUBLIC_KEY;
const smtpConfigured = () => process.env.SMTP_HOST && process.env.SMTP_USER && process.env.SMTP_PASS && process.env.SMTP_FROM;
const emailConfigured = () => emailjsConfigured() || smtpConfigured();
async function queueConfirmationEmail(bookingId, customerEmail, reference, total, items) {
  const ticketDetails = items.map((item) => `${item.title} · ${new Date(item.starts_at).toLocaleString('en-GB')} · ${item.room_name} · Seats ${item.seats.map((seat) => seat.seat_code).join(', ')}`).join('\n');
  const text = `Upon a Star booking ${reference} confirmed. ${ticketDetails}. Present each ticket QR code at the cinema.`;
  const [delivery] = await pool.execute('INSERT INTO email_deliveries (booking_id, recipient_email, delivery_status, provider_message) VALUES (?, ?, ?, ?)', [bookingId, customerEmail, emailConfigured() ? 'queued' : 'failed', emailConfigured() ? null : 'No email sender has been configured.']);
  if (!emailConfigured()) return;
  try {
    if (emailjsConfigured()) {
      const orders = items.flatMap((item) => item.seats.map((seat) => ({
        name: `${item.title} · ${item.room_name} · Seat ${seat.seat_code}`,
        units: 1,
        price: Number(item.unit_price).toLocaleString('id-ID'),
        ticket_code: seat.ticket_code,
        qr_url: `https://api.qrserver.com/v1/create-qr-code/?size=220x220&format=png&qzone=4&data=${encodeURIComponent(seat.ticket_code)}`
      })));
      const response = await fetch('https://api.emailjs.com/api/v1.0/email/send', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ service_id: process.env.EMAILJS_SERVICE_ID, template_id: process.env.EMAILJS_TEMPLATE_ID, user_id: process.env.EMAILJS_PUBLIC_KEY, accessToken: process.env.EMAILJS_PRIVATE_KEY || undefined, template_params: { email: customerEmail, to_email: customerEmail, customer_name: customerEmail, order_id: reference, email_subject: `Upon a Star · Tickets ${reference}`, is_reservation: false, orders, cost: { shipping: '0', tax: '0', total: Number(total).toLocaleString('id-ID') }, booking_reference: reference, ticket_details: ticketDetails, ticket_message: text } }) });
      const providerMessage = (await response.text()).slice(0, 500);
      if (!response.ok) throw new Error(providerMessage || 'EmailJS did not accept the booking email.');
      await pool.execute('UPDATE email_deliveries SET delivery_status = ?, provider_message = ?, sent_at = CURRENT_TIMESTAMP WHERE id = ?', ['sent', providerMessage || 'EmailJS accepted', delivery.insertId]);
      return;
    }
    const transport = nodemailer.createTransport({ host: process.env.SMTP_HOST, port: Number(process.env.SMTP_PORT || 587), secure: process.env.SMTP_SECURE === 'true', auth: { user: process.env.SMTP_USER, pass: process.env.SMTP_PASS } });
    const sent = await transport.sendMail({ from: process.env.SMTP_FROM, to: customerEmail, subject: `Your Upon a Star ticket · ${reference}`, text });
    await pool.execute('UPDATE email_deliveries SET delivery_status = ?, provider_message = ?, sent_at = CURRENT_TIMESTAMP WHERE id = ?', ['sent', String(sent.messageId || 'sent').slice(0, 500), delivery.insertId]);
  } catch (error) { await pool.execute('UPDATE email_deliveries SET delivery_status = ?, provider_message = ? WHERE id = ?', ['failed', String(error.message || 'Delivery failed').slice(0, 500), delivery.insertId]); }
}
const googleStateCookie = 'silver_google_state';
const googleClientCookie = 'silver_google_client';
const googleFlowCookieOptions = { httpOnly: true, secure: process.env.NODE_ENV === 'production', sameSite: 'lax', path: '/api/auth/google' };

app.get('/api/health', (_request, response) => response.json({ ok: true, service: 'silver-village-api' }));
app.get('/api/auth/csrf', (_request, response) => response.json({ token: issueCsrfToken(response) }));
app.get('/api/auth/google', googleSignInLimiter, (request, response) => {
  const client = request.query.client === undefined ? 'react' : request.query.client;
  if (client !== 'react' && client !== 'php') return response.status(400).json({ error: 'Unsupported sign-in client.' });
  if (!googleConfigured()) return response.status(503).json({ error: 'Google sign-in is not configured.' });
  const state = crypto.randomBytes(32).toString('base64url');
  response.cookie(googleStateCookie, state, { ...googleFlowCookieOptions, maxAge: 10 * 60 * 1000 });
  response.cookie(googleClientCookie, client, { ...googleFlowCookieOptions, maxAge: 10 * 60 * 1000 });
  const params = new URLSearchParams({ client_id: process.env.GOOGLE_CLIENT_ID, redirect_uri: process.env.GOOGLE_REDIRECT_URI, response_type: 'code', scope: 'openid email profile', state, prompt: 'select_account' });
  response.redirect(`https://accounts.google.com/o/oauth2/v2/auth?${params}`);
});
app.get('/api/auth/google/callback', googleSignInLimiter, async (request, response, next) => {
  try {
    const state = typeof request.query.state === 'string' ? request.query.state : '';
    const expectedState = request.cookies[googleStateCookie] || '';
    const client = request.cookies[googleClientCookie] === 'php' ? 'php' : 'react';
    response.clearCookie(googleStateCookie, googleFlowCookieOptions);
    response.clearCookie(googleClientCookie, googleFlowCookieOptions);
    if (!state || !expectedState || state.length !== expectedState.length || !crypto.timingSafeEqual(Buffer.from(state), Buffer.from(expectedState))) throw Object.assign(new Error('Google sign-in could not be verified. Please try again.'), { statusCode: 400 });
    if (typeof request.query.error === 'string') throw Object.assign(new Error('Google sign-in was cancelled.'), { statusCode: 400 });
    const code = typeof request.query.code === 'string' ? request.query.code : '';
    if (!code) throw Object.assign(new Error('Google did not return a sign-in code.'), { statusCode: 400 });
    const tokenResponse = await fetch('https://oauth2.googleapis.com/token', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ code, client_id: process.env.GOOGLE_CLIENT_ID, client_secret: process.env.GOOGLE_CLIENT_SECRET, redirect_uri: process.env.GOOGLE_REDIRECT_URI, grant_type: 'authorization_code' }) });
    const token = await tokenResponse.json();
    if (!tokenResponse.ok || !token.access_token) throw Object.assign(new Error('Google sign-in token exchange failed.'), { statusCode: 401 });
    const profileResponse = await fetch('https://openidconnect.googleapis.com/v1/userinfo', { headers: { Authorization: `Bearer ${token.access_token}` } });
    const profile = await profileResponse.json();
    if (!profileResponse.ok || !profile.sub || !profile.email || !profile.email_verified) throw Object.assign(new Error('A verified Google email address is required.'), { statusCode: 401 });
    const email = String(profile.email).trim().toLowerCase();
    const displayName = String(profile.name || email.split('@')[0]).trim().slice(0, 160);
    const configuredOwner = googleOwnerEmails.has(email);
    const [found] = await pool.execute('SELECT id, role, account_status, onboarding_complete, display_name FROM users WHERE google_subject = ? OR email = ? LIMIT 1', [profile.sub, email]);
    let userId;
    if (found.length) { if (found[0].account_status === 'disabled') throw Object.assign(new Error('This account has been disabled. Please contact the cinema.'), { statusCode: 403 }); userId = found[0].id; await pool.execute('UPDATE users SET display_name = ?, email = ?, google_subject = ?, role = ?, last_login_at = CURRENT_TIMESTAMP WHERE id = ?', [nameAfterGoogleSignIn(found[0].display_name, displayName), email, profile.sub, configuredOwner ? 'owner' : found[0].role, userId]); }
    else { const username = `google-${crypto.createHash('sha256').update(profile.sub).digest('hex').slice(0, 32)}`; const passwordHash = crypto.randomBytes(32).toString('hex'); const [created] = await pool.execute('INSERT INTO users (username, display_name, email, google_subject, password_hash, role, last_login_at) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)', [username, displayName, email, profile.sub, passwordHash, configuredOwner ? 'owner' : 'customer']); userId = created.insertId; }
    const { token: sessionToken, expiresAt } = await createSession(userId, request); setSessionCookie(response, sessionToken, expiresAt);
    response.redirect(client === 'php'
      ? `${phpClientOrigin}/${found[0]?.onboarding_complete ? '' : 'profile.php'}`
      : `${clientOrigin}/${found[0]?.onboarding_complete ? '' : 'profile'}`);
  } catch (error) { next(error); }
});
app.post('/api/auth/logout', requireCsrf, async (request, response, next) => { try { await destroySession(request.cookies.silver_session); clearSessionCookie(response); response.status(204).end(); } catch (error) { next(error); } });
app.get('/api/auth/me', requireAuth, (request, response) => response.json({ user: request.user }));
app.put('/api/profile', requireAuth, requireCsrf, async (request, response, next) => { try {
  const input = profileSchema.parse(request.body);
  await pool.execute('UPDATE users SET display_name = ?, date_of_birth = ?, onboarding_complete = 1 WHERE id = ?', [input.displayName, input.dateOfBirth, request.user.id]);
  response.json({ user: { ...request.user, displayName: input.displayName, dateOfBirth: input.dateOfBirth, onboardingComplete: true } });
} catch (error) { next(error); } });

async function screeningsFor(where, params) {
  const [rows] = await pool.execute(`SELECT p.id, p.show_id, p.starts_at, p.format, p.language, p.unit_price, p.status, r.id AS room_id, r.code AS room_code, r.name AS room_name, r.room_type, r.capacity,
    TIMESTAMPADD(MINUTE, s.duration_minutes, p.starts_at) AS ends_at,
    TIMESTAMPADD(MINUTE, s.duration_minutes + ${ROOM_TURNOVER_MINUTES}, p.starts_at) AS room_available_at,
    IF(${bookableScreeningWindow}, 1, 0) AS booking_open,
    (SELECT COUNT(*) FROM performance_seats ps LEFT JOIN booking_tickets bt ON bt.performance_seat_id = ps.id WHERE ps.performance_id = p.id AND bt.id IS NULL) AS seats_available
    FROM performances p JOIN shows s ON s.id = p.show_id JOIN rooms r ON r.id = p.room_id WHERE ${where} ORDER BY p.starts_at`, params);
  return rows;
}
app.get('/api/catalog', async (_request, response, next) => { try {
  await archiveFinishedMovies();
  const [shows] = await pool.execute(`SELECT ${publicMovieFields} FROM shows s ${publicMovieNextScreeningJoin} WHERE ${publicMovieVisibility} ORDER BY next_screening.starts_at IS NULL, next_screening.starts_at, s.title, s.id`);
  response.json({ shows, performances: await screeningsFor(publicScreeningWindow, []) });
} catch (error) { next(error); } });
app.get('/api/movies', async (_request, response, next) => { try {
  await archiveFinishedMovies();
  const [movies] = await pool.execute(`SELECT ${publicMovieFields} FROM shows s ${publicMovieNextScreeningJoin} WHERE ${publicMovieVisibility} ORDER BY next_screening.starts_at IS NULL, next_screening.starts_at, s.title, s.id`);
  response.json({ movies });
} catch (error) { next(error); } });
app.get('/api/movies/:slug', async (request, response, next) => { try {
  await archiveFinishedMovies();
  const [movies] = await pool.execute(`SELECT ${publicMovieFields} FROM shows s WHERE slug = ? AND ${publicMovieVisibility} LIMIT 1`, [request.params.slug]);
  if (!movies.length) return response.status(404).json({ error: 'Movie not found' });
  response.json({ movie: movies[0], screenings: await screeningsFor(`p.show_id = ? AND ${publicScreeningWindow}`, [movies[0].id]) });
} catch (error) { next(error); } });
app.get('/api/screenings/:id/seats', requireAuth, async (request, response, next) => { try {
  await pool.execute('DELETE FROM seat_holds WHERE expires_at <= CURRENT_TIMESTAMP');
  const [screenings] = await pool.execute(`SELECT p.id, p.starts_at, p.unit_price, p.format, s.title, s.synopsis, s.poster_url, s.sales_open_date, r.name AS room_name, r.room_type, IF(${bookableScreeningWindow}, 1, 0) AS booking_open, IF(s.sales_open_date IS NOT NULL AND CURRENT_DATE < s.sales_open_date, 1, 0) AS before_sales FROM performances p JOIN shows s ON s.id = p.show_id JOIN rooms r ON r.id = p.room_id WHERE p.id = ?`, [request.params.id]);
  if (!screenings.length) return response.status(404).json({ error: 'Screening not found' });
  requireBookableScreening(screenings[0]);
  const [seats] = await pool.execute(`SELECT ps.id, rs.seat_code, rs.row_label, rs.seat_number, rs.block_number, IF(bt.id IS NULL AND (sh.id IS NULL OR sh.user_id = ?), 1, 0) AS available, IF(sh.user_id = ?, 1, 0) AS held_by_you FROM performance_seats ps JOIN room_seats rs ON rs.id = ps.room_seat_id LEFT JOIN booking_tickets bt ON bt.performance_seat_id = ps.id LEFT JOIN seat_holds sh ON sh.performance_seat_id = ps.id AND sh.expires_at > CURRENT_TIMESTAMP WHERE ps.performance_id = ? ORDER BY rs.row_label, rs.seat_number`, [request.user.id, request.user.id, request.params.id]);
  response.json({ screening: screenings[0], seats });
} catch (error) { next(error); } });

app.post('/api/screenings/:id/hold', requireAuth, requireCsrf, async (request, response, next) => { try {
  const { seatIds } = holdSchema.parse(request.body); const performanceId = z.coerce.number().int().positive().parse(request.params.id);
  if (!seatIds.length) {
    await pool.execute('DELETE sh FROM seat_holds sh JOIN performance_seats ps ON ps.id = sh.performance_seat_id WHERE sh.user_id = ? AND ps.performance_id = ?', [request.user.id, performanceId]);
    return response.json({ heldSeatIds: [], expiresAt: null });
  }
  const expiresAt = new Date(Date.now() + 10 * 60_000);
  await withTransaction(async (connection) => {
    await connection.execute('DELETE FROM seat_holds WHERE expires_at <= CURRENT_TIMESTAMP');
    const [screenings] = await connection.execute(`SELECT s.sales_open_date, IF(${bookableScreeningWindow}, 1, 0) AS booking_open, IF(s.sales_open_date IS NOT NULL AND CURRENT_DATE < s.sales_open_date, 1, 0) AS before_sales FROM performances p JOIN shows s ON s.id = p.show_id WHERE p.id = ? FOR UPDATE`, [performanceId]);
    if (!screenings.length) throw Object.assign(new Error('Screening not found.'), { statusCode: 404 });
    requireBookableScreening(screenings[0]);
    const placeholders = seatIds.map(() => '?').join(',');
    const [seats] = await connection.execute(`SELECT ps.id, bt.id AS ticket_id, sh.user_id AS hold_user FROM performance_seats ps LEFT JOIN booking_tickets bt ON bt.performance_seat_id = ps.id LEFT JOIN seat_holds sh ON sh.performance_seat_id = ps.id AND sh.expires_at > CURRENT_TIMESTAMP WHERE ps.performance_id = ? AND ps.id IN (${placeholders}) FOR UPDATE`, [performanceId, ...seatIds]);
    if (seats.length !== seatIds.length || seats.some((seat) => seat.ticket_id || (seat.hold_user && seat.hold_user !== request.user.id))) throw Object.assign(new Error('One or more seats are no longer available.'), { statusCode: 409 });
    await connection.execute(`DELETE FROM seat_holds WHERE user_id = ? AND performance_seat_id IN (SELECT id FROM performance_seats WHERE performance_id = ?) AND performance_seat_id NOT IN (${placeholders})`, [request.user.id, performanceId, ...seatIds]);
    for (const seat of seats) await connection.execute('INSERT INTO seat_holds (performance_seat_id, user_id, expires_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), expires_at = VALUES(expires_at)', [seat.id, request.user.id, expiresAt]);
  });
  response.json({ heldSeatIds: seatIds, expiresAt });
} catch (error) { next(error); } });

app.post('/api/bookings', requireAuth, requireCsrf, async (request, response, next) => { try {
  const input = bookingSchema.parse(request.body);
  const seenPerformances = new Set(); const seenSeats = new Set();
  for (const item of input.items) { if (seenPerformances.has(item.performanceId)) throw Object.assign(new Error('A screening can only appear once in an order'), { statusCode: 400 }); seenPerformances.add(item.performanceId); for (const seatId of item.seatIds) { if (seenSeats.has(`${item.performanceId}:${seatId}`)) throw Object.assign(new Error('Each seat can only be selected once'), { statusCode: 400 }); seenSeats.add(`${item.performanceId}:${seatId}`); } }
  const booking = await withTransaction(async (connection) => {
    const selected = []; let total = 0;
    for (const item of input.items) {
      const [performances] = await connection.execute(`SELECT p.id, p.unit_price, p.room_id, p.starts_at, s.title, s.sales_open_date, r.name AS room_name, IF(${bookableScreeningWindow}, 1, 0) AS booking_open, IF(s.sales_open_date IS NOT NULL AND CURRENT_DATE < s.sales_open_date, 1, 0) AS before_sales FROM performances p JOIN shows s ON s.id = p.show_id JOIN rooms r ON r.id = p.room_id WHERE p.id = ? FOR UPDATE`, [item.performanceId]);
      if (!performances.length) throw Object.assign(new Error('One selected screening is no longer available'), { statusCode: 409 });
      requireBookableScreening(performances[0]);
      const placeholders = item.seatIds.map(() => '?').join(',');
      const [seats] = await connection.execute(`SELECT ps.id, rs.seat_code, bt.id AS ticket_id, sh.user_id AS hold_user FROM performance_seats ps JOIN room_seats rs ON rs.id = ps.room_seat_id LEFT JOIN booking_tickets bt ON bt.performance_seat_id = ps.id LEFT JOIN seat_holds sh ON sh.performance_seat_id = ps.id AND sh.expires_at > CURRENT_TIMESTAMP WHERE ps.performance_id = ? AND ps.id IN (${placeholders}) FOR UPDATE`, [item.performanceId, ...item.seatIds]);
      if (seats.length !== item.seatIds.length || seats.some((seat) => seat.ticket_id || seat.hold_user !== request.user.id)) throw Object.assign(new Error('Your ten-minute seat hold has expired. Please choose your seats again.'), { statusCode: 409 });
      const performance = performances[0]; selected.push({ ...performance, seats }); total += Number(performance.unit_price) * seats.length;
    }
    const publicReference = reference();
    const [created] = await connection.execute(`INSERT INTO bookings (user_id, public_reference, customer_name, customer_email, customer_phone, total_amount, booking_status, payment_status) VALUES (?, ?, ?, ?, ?, ?, 'confirmed', 'pending')`, [request.user.id, publicReference, request.user.displayName, request.user.email, input.customerPhone, total]);
    for (const item of selected) {
      const [line] = await connection.execute('INSERT INTO booking_items (booking_id, performance_id, ticket_quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?)', [created.insertId, item.id, item.seats.length, item.unit_price, Number(item.unit_price) * item.seats.length]);
      for (const seat of item.seats) {
        seat.ticket_code = `SVT-${crypto.randomBytes(6).toString('hex').toUpperCase()}`;
        await connection.execute('INSERT INTO booking_tickets (ticket_code, booking_id, booking_item_id, performance_seat_id) VALUES (?, ?, ?, ?)', [seat.ticket_code, created.insertId, line.insertId, seat.id]);
      }
      await connection.execute('UPDATE performances SET seats_booked = seats_booked + ? WHERE id = ?', [item.seats.length, item.id]);
      await connection.execute('DELETE FROM seat_holds WHERE user_id = ? AND performance_seat_id IN (' + item.seats.map(() => '?').join(',') + ')', [request.user.id, ...item.seats.map((seat) => seat.id)]);
    }
    await connection.execute('INSERT INTO audit_events (action_name, entity_type, entity_id, event_data, ip_address) VALUES (?, ?, ?, ?, ?)', ['create_reservation', 'booking', String(created.insertId), JSON.stringify({ publicReference }), request.ip]);
    return { id: created.insertId, email: request.user.email, reference: publicReference, total, items: selected };
  });
  try {
    await queueConfirmationEmail(booking.id, booking.email, booking.reference, booking.total, booking.items);
  } catch (_error) {
    console.error('The booking was saved, but its ticket email could not be queued.');
  }
  response.status(201).json({ reference: booking.reference, total: booking.total, items: booking.items.map((item) => ({ title: item.title, startsAt: item.starts_at, roomName: item.room_name, seats: item.seats.map((seat) => seat.seat_code) })) });
} catch (error) { next(error); } });

app.get('/api/bookings/:reference', requireAuth, async (request, response, next) => { try {
  const [bookings] = await pool.execute('SELECT id, user_id, public_reference AS reference, customer_name, total_amount, booking_status, created_at FROM bookings WHERE public_reference = ? LIMIT 1', [request.params.reference]);
  if (!bookings.length) return response.status(404).json({ error: 'Booking not found' });
  if (bookings[0].user_id !== request.user.id && !['owner', 'staff', 'manager'].includes(request.user.role)) return response.status(403).json({ error: 'You cannot view this booking.' });
  const [items] = await pool.execute(`SELECT s.title, p.starts_at, r.name AS room_name, bi.ticket_quantity, bi.unit_price, IF(${activeTicketWindow}, 1, 0) AS ticket_available, ${ticketUnavailableReason} AS ticket_unavailable_reason, GROUP_CONCAT(rs.seat_code ORDER BY rs.row_label, rs.seat_number SEPARATOR ', ') AS seats FROM booking_items bi JOIN performances p ON p.id = bi.performance_id JOIN shows s ON s.id = p.show_id JOIN rooms r ON r.id = p.room_id JOIN booking_tickets bt ON bt.booking_item_id = bi.id JOIN performance_seats ps ON ps.id = bt.performance_seat_id JOIN room_seats rs ON rs.id = ps.room_seat_id WHERE bi.booking_id = ? GROUP BY bi.id`, [bookings[0].id]);
  response.json({ booking: bookings[0], items });
} catch (error) { next(error); } });

app.get('/api/tickets', requireAuth, async (request, response, next) => { try {
  if (!request.user.email) return response.status(403).json({ error: 'Sign in with Google to view tickets.' });
  const [rows] = await pool.execute(`SELECT b.public_reference, b.created_at, s.title, p.starts_at, r.name AS room_name, r.room_type, bt.ticket_code, bt.checked_in_at, rs.seat_code, IF(${activeTicketWindow}, 1, 0) AS ticket_available, ${ticketUnavailableReason} AS ticket_unavailable_reason
    FROM bookings b
    JOIN booking_items bi ON bi.booking_id = b.id
    JOIN performances p ON p.id = bi.performance_id
    JOIN shows s ON s.id = p.show_id
    JOIN rooms r ON r.id = p.room_id
    JOIN booking_tickets bt ON bt.booking_item_id = bi.id
    JOIN performance_seats ps ON ps.id = bt.performance_seat_id
    JOIN room_seats rs ON rs.id = ps.room_seat_id
    WHERE b.user_id = ? AND b.booking_status = 'confirmed'
    ORDER BY p.starts_at DESC, rs.row_label, rs.seat_number`, [request.user.id]);
  const byReference = new Map();
  for (const row of rows) {
    if (!byReference.has(row.public_reference)) byReference.set(row.public_reference, { reference: row.public_reference, bookedAt: row.created_at, items: [] });
    const ticket = byReference.get(row.public_reference); let item = ticket.items.find((current) => current.title === row.title && String(current.startsAt) === String(row.starts_at) && current.roomName === row.room_name);
    if (!item) { item = { title: row.title, startsAt: row.starts_at, roomName: row.room_name, roomType: row.room_type, ticketAvailable: row.ticket_available, ticketUnavailableReason: row.ticket_unavailable_reason, seats: [] }; ticket.items.push(item); }
    item.seats.push({ code: row.seat_code, ticketCode: row.ticket_code, checkedInAt: row.checked_in_at });
  }
  response.json({ tickets: [...byReference.values()] });
} catch (error) { next(error); } });

const bookingDetailSql = `SELECT b.id, b.public_reference, b.customer_name, b.customer_email, b.customer_phone, b.total_amount, b.booking_status, b.created_at, bi.id AS booking_item_id, s.title, p.starts_at, p.status AS screening_status, r.name AS room_name, bt.id AS ticket_id, bt.ticket_code, bt.checked_in_at, rs.seat_code, IF(b.booking_status = 'confirmed' AND ${activeTicketWindow}, 1, 0) AS ticket_available, CASE WHEN b.booking_status = 'cancelled' THEN 'Booking cancelled' ELSE ${ticketUnavailableReason} END AS ticket_unavailable_reason FROM bookings b JOIN booking_items bi ON bi.booking_id = b.id JOIN performances p ON p.id = bi.performance_id JOIN shows s ON s.id = p.show_id JOIN rooms r ON r.id = p.room_id JOIN booking_tickets bt ON bt.booking_item_id = bi.id JOIN performance_seats ps ON ps.id = bt.performance_seat_id JOIN room_seats rs ON rs.id = ps.room_seat_id`;
function shapeBooking(rows) { if (!rows.length) return null; const booking = { id: rows[0].id, reference: rows[0].public_reference, customerName: rows[0].customer_name, customerEmail: rows[0].customer_email, customerPhone: rows[0].customer_phone, total: rows[0].total_amount, bookingStatus: rows[0].booking_status, createdAt: rows[0].created_at, items: [] }; const items = new Map(); for (const row of rows) { if (!items.has(row.booking_item_id)) { const item = { title: row.title, startsAt: row.starts_at, roomName: row.room_name, screeningStatus: row.screening_status, ticketAvailable: row.ticket_available, ticketUnavailableReason: row.ticket_unavailable_reason, tickets: [] }; items.set(row.booking_item_id, item); booking.items.push(item); } items.get(row.booking_item_id).tickets.push({ id: row.ticket_id, code: row.ticket_code, seat: row.seat_code, checkedInAt: row.checked_in_at, available: row.ticket_available, unavailableReason: row.ticket_unavailable_reason }); } return booking; }
app.get('/api/admin/bookings', requireAuth, ownerOnly, async (_request, response, next) => { try {
  const [bookings] = await pool.execute(`SELECT b.id, b.public_reference, b.customer_name, b.customer_email, b.customer_phone, b.total_amount, b.created_at, GROUP_CONCAT(rs.seat_code ORDER BY rs.seat_code SEPARATOR ', ') AS seats FROM bookings b LEFT JOIN booking_tickets bt ON bt.booking_id = b.id LEFT JOIN performance_seats ps ON ps.id = bt.performance_seat_id LEFT JOIN room_seats rs ON rs.id = ps.room_seat_id GROUP BY b.id ORDER BY b.created_at DESC LIMIT 100`);
  response.json({ bookings });
} catch (error) { next(error); } });
app.get('/api/operations/bookings/:reference', requireAuth, staffOnly, async (request, response, next) => { try { const [rows] = await pool.execute(`${bookingDetailSql} WHERE b.public_reference = ? ORDER BY bt.id`, [request.params.reference]); const booking = shapeBooking(rows); if (!booking) return response.status(404).json({ error: 'Booking not found.' }); response.json({ booking }); } catch (error) { next(error); } });
app.get('/api/operations/ticket-lookup', requireAuth, staffOnly, async (request, response, next) => { try { const query = String(request.query.q || '').trim(); if (query.length < 3) return response.status(400).json({ error: 'Enter a booking reference, ticket code, or customer email.' }); const [rows] = await pool.execute(`${bookingDetailSql} WHERE b.public_reference = ? OR bt.ticket_code = ? OR b.customer_email = ? ORDER BY b.created_at DESC, bt.id LIMIT 100`, [query, query, query.toLowerCase()]); const grouped = new Map(); for (const row of rows) { if (!grouped.has(row.id)) grouped.set(row.id, []); grouped.get(row.id).push(row); } response.json({ bookings: [...grouped.values()].map(shapeBooking) }); } catch (error) { next(error); } });
app.post('/api/operations/scan-ticket', requireAuth, staffOnly, requireCsrf, async (request, response, next) => { try {
  const raw = ticketScanSchema.parse(request.body).code;
  const code = raw.replace(/^SVT:/i, '').trim();
  const result = await withTransaction(async (connection) => {
    const [rows] = await connection.execute(`SELECT bt.id, bt.ticket_code, bt.checked_in_at, rs.seat_code, b.public_reference, b.customer_name, b.customer_email, s.title, p.starts_at, r.name AS room_name
      FROM booking_tickets bt JOIN bookings b ON b.id = bt.booking_id JOIN booking_items bi ON bi.id = bt.booking_item_id JOIN performances p ON p.id = bi.performance_id JOIN shows s ON s.id = p.show_id JOIN rooms r ON r.id = p.room_id JOIN performance_seats ps ON ps.id = bt.performance_seat_id JOIN room_seats rs ON rs.id = ps.room_seat_id
      WHERE bt.ticket_code = ? AND b.booking_status = 'confirmed' AND ${activeTicketWindow} LIMIT 1 FOR UPDATE`, [code]);
    if (!rows.length) throw Object.assign(new Error('Ticket not found or not valid for entry.'), { statusCode: 404 });
    const ticket = rows[0];
    if (ticket.checked_in_at) return { status: 'used', ticket };
    await connection.execute('UPDATE booking_tickets SET checked_in_at = CURRENT_TIMESTAMP, checked_in_by = ? WHERE id = ?', [request.user.id, ticket.id]);
    return { status: 'accepted', ticket: { ...ticket, checked_in_at: new Date() } };
  });
  response.json(result);
} catch (error) { next(error); } });
app.patch('/api/operations/tickets/:id/check-in', requireAuth, staffOnly, requireCsrf, async (request, response, next) => { try { const ticketId = z.coerce.number().int().positive().parse(request.params.id); const [result] = await pool.execute(`UPDATE booking_tickets bt
  JOIN bookings b ON b.id = bt.booking_id
  JOIN booking_items bi ON bi.id = bt.booking_item_id
  JOIN performances p ON p.id = bi.performance_id
  JOIN shows s ON s.id = p.show_id
  SET bt.checked_in_at = CURRENT_TIMESTAMP, bt.checked_in_by = ?
  WHERE bt.id = ? AND bt.checked_in_at IS NULL AND b.booking_status = 'confirmed' AND ${activeTicketWindow}`, [request.user.id, ticketId]); if (!result.affectedRows) return response.status(409).json({ error: 'This ticket is already used, cancelled, finished, or its movie is no longer available.' }); response.json({ ok: true }); } catch (error) { next(error); } });
app.get('/api/admin/summary', requireAuth, ownerOnly, async (_request, response, next) => { try { const [[summary]] = await pool.execute(`
  SELECT (SELECT COUNT(*) FROM performances WHERE status = 'scheduled' AND DATE(starts_at) = CURRENT_DATE) AS screenings_today,
         (SELECT COUNT(*) FROM booking_tickets bt JOIN bookings b ON b.id = bt.booking_id JOIN booking_items bi ON bi.id = bt.booking_item_id JOIN performances p ON p.id = bi.performance_id WHERE b.booking_status = 'confirmed' AND p.status = 'scheduled' AND DATE(p.starts_at) = CURRENT_DATE) AS seats_booked_today,
         (SELECT COUNT(*) FROM booking_tickets bt JOIN bookings b ON b.id = bt.booking_id JOIN booking_items bi ON bi.id = bt.booking_item_id JOIN performances p ON p.id = bi.performance_id WHERE b.booking_status = 'confirmed' AND bt.checked_in_at IS NOT NULL AND p.status = 'scheduled' AND DATE(p.starts_at) = CURRENT_DATE) AS seats_checked_in_today
`); const [[nextScreening]] = await pool.execute(`SELECT p.starts_at, s.title, r.name AS room_name FROM performances p JOIN shows s ON s.id = p.show_id JOIN rooms r ON r.id = p.room_id WHERE p.status = 'scheduled' AND p.starts_at >= CURRENT_TIMESTAMP ORDER BY p.starts_at LIMIT 1`); response.json({ summary, nextScreening }); } catch (error) { next(error); } });
app.get('/api/admin/screenings', requireAuth, ownerOnly, async (_request, response, next) => { try { const [screenings] = await pool.execute(`SELECT ${adminScreeningFields} FROM performances p JOIN shows s ON s.id = p.show_id JOIN rooms r ON r.id = p.room_id WHERE p.history_deleted_at IS NULL ORDER BY p.starts_at DESC`); response.json({ screenings }); } catch (error) { next(error); } });
app.delete('/api/admin/screenings', requireAuth, ownerOnly, requireCsrf, async (request, response, next) => { try {
  const { ids: requestedIds } = screeningHistoryDeleteSchema.parse(request.body);
  const ids = [...new Set(requestedIds)];
  const placeholders = ids.map(() => '?').join(',');
  const deletion = await withTransaction(async (connection) => {
    const [screenings] = await connection.execute(`SELECT p.id, p.starts_at, CURRENT_TIMESTAMP >= TIMESTAMPADD(MINUTE, s.duration_minutes, p.starts_at) AS is_past FROM performances p JOIN shows s ON s.id = p.show_id WHERE p.id IN (${placeholders}) AND p.history_deleted_at IS NULL FOR UPDATE`, ids);
    if (screenings.length !== ids.length) throw Object.assign(new Error('One or more selected screenings no longer exist in the visible history.'), { statusCode: 404 });
    if (screenings.some((screening) => !Number(screening.is_past))) throw Object.assign(new Error('Only past screenings can be deleted from history.'), { statusCode: 409 });
    const [bookingItems] = await connection.execute(`SELECT id, booking_id FROM booking_items WHERE performance_id IN (${placeholders}) FOR UPDATE`, ids);
    const bookingItemIds = bookingItems.map((item) => item.id);
    const bookingIds = [...new Set(bookingItems.map((item) => item.booking_id))];
    let deletedTickets = 0;
    let deletedBookings = 0;
    if (bookingItemIds.length) {
      const itemPlaceholders = bookingItemIds.map(() => '?').join(',');
      const [ticketResult] = await connection.execute(`DELETE FROM booking_tickets WHERE booking_item_id IN (${itemPlaceholders})`, bookingItemIds);
      deletedTickets = ticketResult.affectedRows;
      await connection.execute(`DELETE FROM booking_items WHERE id IN (${itemPlaceholders})`, bookingItemIds);
    }
    if (bookingIds.length) {
      const bookingPlaceholders = bookingIds.map(() => '?').join(',');
      await connection.execute(`UPDATE bookings b SET total_amount = COALESCE((SELECT SUM(bi.line_total) FROM booking_items bi WHERE bi.booking_id = b.id), 0) WHERE b.id IN (${bookingPlaceholders})`, bookingIds);
      const [emptyBookings] = await connection.execute(`SELECT b.id FROM bookings b WHERE b.id IN (${bookingPlaceholders}) AND NOT EXISTS (SELECT 1 FROM booking_items bi WHERE bi.booking_id = b.id) FOR UPDATE`, bookingIds);
      const emptyBookingIds = emptyBookings.map((booking) => booking.id);
      if (emptyBookingIds.length) {
        const emptyPlaceholders = emptyBookingIds.map(() => '?').join(',');
        await connection.execute(`DELETE FROM payment_events WHERE booking_id IN (${emptyPlaceholders})`, emptyBookingIds);
        await connection.execute(`DELETE FROM email_deliveries WHERE booking_id IN (${emptyPlaceholders})`, emptyBookingIds);
        const [bookingResult] = await connection.execute(`DELETE FROM bookings WHERE id IN (${emptyPlaceholders})`, emptyBookingIds);
        deletedBookings = bookingResult.affectedRows;
      }
    }
    const [result] = await connection.execute(`DELETE p FROM performances p JOIN shows s ON s.id = p.show_id WHERE p.id IN (${placeholders}) AND CURRENT_TIMESTAMP >= TIMESTAMPADD(MINUTE, s.duration_minutes, p.starts_at) AND p.history_deleted_at IS NULL`, ids);
    if (result.affectedRows !== ids.length) throw Object.assign(new Error('The screening history changed. Refresh and try again.'), { statusCode: 409 });
    await connection.execute('INSERT INTO audit_events (actor_user_id, action_name, entity_type, event_data, ip_address) VALUES (?, ?, ?, ?, ?)', [request.user.id, 'permanently_delete_past_screenings', 'performance', JSON.stringify({ screeningIds: ids, deletedTickets, deletedBookings }), request.ip]);
    return { deleted: result.affectedRows, deletedTickets, deletedBookings };
  });
  response.json({ ok: true, ...deletion });
} catch (error) { next(error); } });
app.patch('/api/admin/screenings/:id', requireAuth, ownerOnly, requireCsrf, async (request, response, next) => { try {
  const id = z.coerce.number().int().positive().parse(request.params.id);
  const input = screeningUpdateSchema.parse(request.body);
  const updated = await withTransaction(async (connection) => {
    const [existingRows] = await connection.execute(`SELECT p.id, p.room_id, p.starts_at, p.status, p.seats_booked, s.duration_minutes
      FROM performances p JOIN shows s ON s.id = p.show_id WHERE p.id = ? FOR UPDATE`, [id]);
    if (!existingRows.length) throw Object.assign(new Error('Screening not found.'), { statusCode: 404 });
    const existing = existingRows[0];
    const startsAt = input.startsAt ? new Date(input.startsAt) : new Date(existing.starts_at);
    const roomId = input.roomId || existing.room_id;
    const status = input.status || existing.status;
    if (Number.isNaN(startsAt.valueOf()) || (input.startsAt && startsAt <= new Date())) throw Object.assign(new Error('Choose a future start time.'), { statusCode: 400 });
    if (status === 'cancelled' && existing.status !== 'cancelled' && startsAt > new Date() && !input.cancellationReason?.trim()) {
      const [[booked]] = await connection.execute(`SELECT EXISTS(
        SELECT 1 FROM booking_tickets bt JOIN booking_items bi ON bi.id = bt.booking_item_id
        WHERE bi.performance_id = ? LIMIT 1
      ) AS has_tickets`, [id]);
      if (Number(existing.seats_booked) > 0 || Number(booked.has_tickets) > 0) {
        throw Object.assign(new Error('Give a cancellation reason: guests already hold tickets for this screening.'), { statusCode: 400 });
      }
    }
    const [[runWindow]] = await connection.execute(`SELECT IF(s.run_start_date IS NOT NULL AND s.run_end_date IS NOT NULL AND DATE(?) BETWEEN s.run_start_date AND s.run_end_date, 1, 0) AS within_run FROM performances p JOIN shows s ON s.id = p.show_id WHERE p.id = ?`, [startsAt, id]);
    if (!Number(runWindow.within_run)) throw Object.assign(new Error('The screening date must fall within the movie start and end dates.'), { statusCode: 400 });
    const [rooms] = await connection.execute('SELECT id, name, capacity FROM rooms WHERE id = ? AND active = 1 FOR UPDATE', [roomId]);
    if (!rooms.length) throw Object.assign(new Error('Room not found or inactive.'), { statusCode: 404 });
    const room = rooms[0];
    if (roomId !== existing.room_id) {
      const [[usage]] = await connection.execute(`SELECT (SELECT COUNT(*) FROM booking_tickets bt JOIN performance_seats ps ON ps.id = bt.performance_seat_id WHERE ps.performance_id = ?) AS tickets,
        (SELECT COUNT(*) FROM seat_holds WHERE performance_seat_id IN (SELECT id FROM performance_seats WHERE performance_id = ?) AND expires_at > CURRENT_TIMESTAMP) AS holds`, [id, id]);
      if (usage.tickets || usage.holds) throw Object.assign(new Error('Cannot move a screening to another room once seats are held or sold.'), { statusCode: 409 });
    }
    if (status === 'scheduled') {
      const availableAt = roomAvailableAt(startsAt, existing.duration_minutes);
      const [conflicts] = await connection.execute(`SELECT p.id FROM performances p JOIN shows s ON s.id = p.show_id
        WHERE p.id != ? AND p.room_id = ? AND p.status = 'scheduled' AND p.starts_at < ?
          AND TIMESTAMPADD(MINUTE, s.duration_minutes + ${ROOM_TURNOVER_MINUTES}, p.starts_at) > ? LIMIT 1`, [id, roomId, availableAt, startsAt]);
      if (conflicts.length) throw Object.assign(new Error('That room is already allocated during this time. Choose another room or time.'), { statusCode: 409 });
    }
    const fields = ['starts_at = ?', 'room_id = ?', 'auditorium = ?', 'capacity = ?'];
    const params = [startsAt, roomId, room.name, room.capacity];
    if (input.format) { fields.push('format = ?'); params.push(input.format); }
    if (input.language) { fields.push('language = ?'); params.push(input.language); }
    if (input.unitPrice) { fields.push('unit_price = ?'); params.push(input.unitPrice); }
    if (input.status) { fields.push('status = ?', 'cancelled_at = ?'); params.push(input.status, input.status === 'cancelled' ? new Date() : null); }
    if (input.cancellationReason !== undefined) { fields.push('cancellation_reason = ?'); params.push(input.cancellationReason || null); }
    await connection.execute(`UPDATE performances SET ${fields.join(', ')} WHERE id = ?`, [...params, id]);
    if (roomId !== existing.room_id) {
      await connection.execute('DELETE FROM performance_seats WHERE performance_id = ?', [id]);
      await connection.execute('INSERT INTO performance_seats (performance_id, room_seat_id) SELECT ?, id FROM room_seats WHERE room_id = ?', [id, roomId]);
    }
    return true;
  });
  response.json({ ok: updated });
} catch (error) { next(error); } });
app.get('/api/admin/accounts', requireAuth, ownerOnly, async (_request, response, next) => { try {
  const [accounts] = await pool.execute(`SELECT id, display_name, email, role, account_status, date_of_birth, onboarding_complete, last_login_at, created_at FROM users WHERE google_subject IS NOT NULL ORDER BY last_login_at DESC, created_at DESC`);
  response.json({ accounts });
} catch (error) { next(error); } });
app.patch('/api/admin/accounts/:id', requireAuth, ownerOnly, requireCsrf, async (request, response, next) => { try {
  const accountId = z.coerce.number().int().positive().parse(request.params.id);
  const input = accountActionSchema.parse(request.body);
  if (accountId === request.user.id) return response.status(400).json({ error: 'You cannot change your own owner access or disable your own account.' });
  const [accounts] = await pool.execute('SELECT id, role FROM users WHERE id = ? AND google_subject IS NOT NULL LIMIT 1', [accountId]);
  if (!accounts.length) return response.status(404).json({ error: 'Google account not found.' });
  const removingOwner = accounts[0].role === 'owner' && (input.role && input.role !== 'owner' || input.accountStatus === 'disabled');
  if (removingOwner) { const [[owners]] = await pool.execute("SELECT COUNT(*) AS total FROM users WHERE role = 'owner' AND account_status = 'active'"); if (owners.total <= 1) return response.status(409).json({ error: 'At least one active owner must remain.' }); }
  if (input.role) await pool.execute('UPDATE users SET role = ? WHERE id = ?', [input.role, accountId]);
  if (input.accountStatus) { await pool.execute('UPDATE users SET account_status = ? WHERE id = ?', [input.accountStatus, accountId]); if (input.accountStatus === 'disabled') await pool.execute('DELETE FROM sessions WHERE user_id = ?', [accountId]); }
  response.json({ ok: true });
} catch (error) { next(error); } });
app.get('/api/admin/rooms', requireAuth, ownerOnly, async (_request, response, next) => { try { const [rooms] = await pool.execute('SELECT id, code, name, room_type, capacity, active FROM rooms ORDER BY code'); response.json({ rooms }); } catch (error) { next(error); } });
app.get('/api/admin/movies', requireAuth, ownerOnly, async (_request, response, next) => { try { await archiveFinishedMovies(); const [movies] = await pool.execute(`SELECT ${publicMovieFields} FROM shows ORDER BY created_at DESC`); response.json({ movies }); } catch (error) { next(error); } });
app.post('/api/admin/movies', requireAuth, ownerOnly, requireCsrf, async (request, response, next) => { try {
  const m = movieSchema.parse(request.body);
  const lifecycle = normalizeMovieLifecycle(m);
  await requireCurrentRunForPublication(m.status, lifecycle.runEndDate);
  const [result] = await pool.execute('INSERT INTO shows (title, slug, synopsis, genre, duration_minutes, age_rating, poster_url, backdrop_url, trailer_url, theme_key, status, featured, run_start_date, run_end_date, sales_open_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [m.title, m.slug, m.synopsis, m.genre, m.durationMinutes, m.ageRating, m.posterUrl, m.backdropUrl, m.trailerUrl || null, m.themeKey, m.status, m.featured ? 1 : 0, lifecycle.runStartDate, lifecycle.runEndDate, lifecycle.salesOpenDate]);
  const [movies] = await pool.execute(`SELECT ${publicMovieFields} FROM shows WHERE id = ? LIMIT 1`, [result.insertId]);
  response.status(201).json({ id: result.insertId, movie: movies[0] });
} catch (error) { next(error); } });
app.patch('/api/admin/movies/:id', requireAuth, ownerOnly, requireCsrf, async (request, response, next) => { try {
  await archiveFinishedMovies();
  const id = z.coerce.number().int().positive().parse(request.params.id);
  const input = movieUpdateSchema.parse(request.body);
  const [movies] = await pool.execute('SELECT id, status, run_start_date, run_end_date, sales_open_date FROM shows WHERE id = ? LIMIT 1', [id]);
  if (!movies.length) return response.status(404).json({ error: 'Movie not found.' });
  const existing = movies[0];
  const nextStatus = input.status || existing.status;
  const lifecycleInput = {
    runStartDate: input.runStartDate || databaseCalendarDate(existing.run_start_date),
    runEndDate: input.runEndDate || databaseCalendarDate(existing.run_end_date),
    salesOpenDate: input.salesOpenDate !== undefined ? input.salesOpenDate : databaseCalendarDate(existing.sales_open_date),
  };
  let lifecycle = null;
  const changingDates = input.runStartDate !== undefined || input.runEndDate !== undefined || input.salesOpenDate !== undefined;
  if (changingDates || nextStatus === 'published') {
    if (!lifecycleInput.runStartDate || !lifecycleInput.runEndDate) throw Object.assign(new Error('Set the movie start and end dates before publishing it.'), { statusCode: 400 });
    lifecycle = normalizeMovieLifecycle(lifecycleInput);
    await requireCurrentRunForPublication(nextStatus, lifecycle.runEndDate);
    const [[outside]] = await pool.execute(`SELECT COUNT(*) AS total FROM performances WHERE show_id = ? AND status = 'scheduled' AND starts_at >= CURRENT_TIMESTAMP AND DATE(starts_at) NOT BETWEEN ? AND ?`, [id, lifecycle.runStartDate, lifecycle.runEndDate]);
    if (Number(outside.total)) throw Object.assign(new Error('The new movie dates must include every future scheduled screening. Move or cancel those screenings first.'), { statusCode: 409 });
  }
  if (existing.status === 'archived' && input.status === 'published') {
    const [[future]] = await pool.execute(`SELECT COUNT(*) AS total FROM performances
      WHERE show_id = ? AND status = 'scheduled' AND history_deleted_at IS NULL
        AND starts_at > CURRENT_TIMESTAMP`, [id]);
    if (!Number(future.total)) throw Object.assign(new Error('Add a future screening before republishing this archived movie.'), { statusCode: 409 });
  }
  const fields = []; const params = [];
  if (input.status) { fields.push('status = ?'); params.push(input.status); }
  if (input.themeKey) { fields.push('theme_key = ?'); params.push(input.themeKey); }
  if (lifecycle && changingDates) { fields.push('run_start_date = ?', 'run_end_date = ?', 'sales_open_date = ?'); params.push(lifecycle.runStartDate, lifecycle.runEndDate, lifecycle.salesOpenDate); }
  await pool.execute(`UPDATE shows SET ${fields.join(', ')} WHERE id = ?`, [...params, id]);
  response.json({ ok: true });
} catch (error) { next(error); } });
app.delete('/api/admin/movies/:id', requireAuth, ownerOnly, requireCsrf, async (request, response, next) => { try { const id = z.coerce.number().int().positive().parse(request.params.id); const [[counts]] = await pool.execute('SELECT (SELECT COUNT(*) FROM performances WHERE show_id = ?) AS screenings, (SELECT COUNT(*) FROM booking_items bi JOIN performances p ON p.id = bi.performance_id WHERE p.show_id = ?) AS bookings', [id, id]); if (counts.screenings || counts.bookings) return response.status(409).json({ error: 'This movie has screening history. Archive it instead so bookings remain auditable.' }); const [result] = await pool.execute('DELETE FROM shows WHERE id = ?', [id]); if (!result.affectedRows) return response.status(404).json({ error: 'Movie not found.' }); response.json({ ok: true }); } catch (error) { next(error); } });
app.post('/api/admin/screenings', requireAuth, ownerOnly, requireCsrf, async (request, response, next) => { try {
  const input = screeningSchema.parse(request.body); const start = new Date(input.startsAt); if (Number.isNaN(start.valueOf()) || start <= new Date()) throw Object.assign(new Error('Choose a future start time'), { statusCode: 400 });
  const [shows] = await pool.execute(`SELECT duration_minutes, run_start_date, run_end_date, IF(run_start_date IS NOT NULL AND run_end_date IS NOT NULL AND DATE(?) BETWEEN run_start_date AND run_end_date, 1, 0) AS within_run FROM shows WHERE id = ? LIMIT 1`, [start, input.showId]); if (!shows.length) return response.status(404).json({ error: 'Movie not found' });
  if (!shows[0].run_start_date || !shows[0].run_end_date) throw Object.assign(new Error('Set this movie’s start and end dates before scheduling screenings.'), { statusCode: 400 });
  if (!Number(shows[0].within_run)) throw Object.assign(new Error('The screening date must fall within the movie start and end dates.'), { statusCode: 400 });
  const availableAt = roomAvailableAt(start, shows[0].duration_minutes);
  const created = await withTransaction(async (connection) => {
    const [rooms] = await connection.execute('SELECT id FROM rooms WHERE id = ? AND active = 1 FOR UPDATE', [input.roomId]);
    if (!rooms.length) throw Object.assign(new Error('Room not found or inactive'), { statusCode: 404 });
    const [conflicts] = await connection.execute(`SELECT p.id FROM performances p JOIN shows s ON s.id = p.show_id WHERE p.room_id = ? AND p.status = 'scheduled' AND p.starts_at < ? AND TIMESTAMPADD(MINUTE, s.duration_minutes + ${ROOM_TURNOVER_MINUTES}, p.starts_at) > ? LIMIT 1`, [input.roomId, availableAt, start]);
    if (conflicts.length) throw Object.assign(new Error('That room is already allocated during this time. Choose another room or time.'), { statusCode: 409 });
    const [result] = await connection.execute('INSERT INTO performances (show_id, room_id, starts_at, auditorium, format, language, unit_price, capacity, seats_booked) SELECT ?, r.id, ?, r.name, ?, ?, ?, r.capacity, 0 FROM rooms r WHERE r.id = ?', [input.showId, start, input.format, input.language, input.unitPrice, input.roomId]);
    await connection.execute('INSERT INTO performance_seats (performance_id, room_seat_id) SELECT ?, id FROM room_seats WHERE room_id = ?', [result.insertId, input.roomId]);
    return result.insertId;
  });
  const [screenings] = await pool.execute(`SELECT ${adminScreeningFields} FROM performances p JOIN shows s ON s.id = p.show_id JOIN rooms r ON r.id = p.room_id WHERE p.id = ? LIMIT 1`, [created]);
  response.status(201).json({ id: created, screening: screenings[0] });
} catch (error) { next(error); } });

app.use((error, _request, response, _next) => { if (error instanceof z.ZodError) return response.status(400).json({ error: 'Invalid request data', fields: error.flatten().fieldErrors }); if (error.statusCode) return response.status(error.statusCode).json({ error: error.message }); if (error?.code === 'ER_DUP_ENTRY') return response.status(409).json({ error: 'That value already exists.' }); console.error(error); response.status(500).json({ error: 'Internal server error' }); });
const server = app.listen(port, () => console.log(`Upon a Star API listening on http://localhost:${port}`));
for (const signal of ['SIGTERM', 'SIGINT']) process.once(signal, () => server.close(() => pool.end().finally(() => process.exit(0))));
