import { readFileSync } from 'node:fs';

/** Shared by the API and the explicit database installer; creates no connections. */
export function buildDbConfig(env = process.env) {
  const setting = String(env.MYSQL_SSL || '').trim().toLowerCase();
  if (!['', 'false', '0', 'true', '1'].includes(setting)) {
    throw new Error('MYSQL_SSL must be true or false.');
  }
  const port = Number(env.MYSQL_PORT || 3306);
  if (!Number.isInteger(port) || port < 1 || port > 65535) {
    throw new Error('MYSQL_PORT must be a valid TCP port.');
  }
  const options = {
    host: env.MYSQL_HOST,
    port,
    database: env.MYSQL_DATABASE,
    user: env.MYSQL_USER,
    password: env.MYSQL_PASSWORD,
    timezone: '+08:00',
    waitForConnections: true,
    connectionLimit: 10,
    maxIdle: 5,
    idleTimeout: 240_000,
    namedPlaceholders: false,
  };
  if (setting === 'true' || setting === '1') {
    options.ssl = { minVersion: 'TLSv1.2', rejectUnauthorized: true, verifyIdentity: true };
    if (env.MYSQL_SSL_CA?.trim()) {
      try { options.ssl.ca = readFileSync(env.MYSQL_SSL_CA.trim()); }
      catch { throw new Error('MYSQL_SSL_CA must name a readable CA certificate bundle.'); }
    }
  }
  return options;
}

/** mysql2 queues this before the first query on each newly opened connection. */
export function initializeDbConnection(connection) {
  connection.query("SET time_zone = '+08:00'", (error) => {
    // A connection with an unknown time zone must never return to the pool.
    if (error) connection.destroy();
  });
}
