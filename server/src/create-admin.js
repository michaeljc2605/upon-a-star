import 'dotenv/config';
import readline from 'node:readline/promises';
import { stdin as input, stdout as output } from 'node:process';
import bcrypt from 'bcryptjs';
import { pool } from './db.js';

const rl = readline.createInterface({ input, output });
try {
  const username = (await rl.question('Owner username: ')).trim();
  const displayName = (await rl.question('Display name: ')).trim();
  const password = await rl.question('Password: ', { hideEchoBack: true });
  if (!username || !displayName || password.length < 12) throw new Error('Username and display name are required; password must be at least 12 characters.');
  const hash = await bcrypt.hash(password, 12);
  await pool.execute('INSERT INTO users (username, display_name, password_hash, role) VALUES (?, ?, ?, \'owner\')', [username, displayName, hash]);
  console.log('Owner account created. The plaintext password was not stored.');
} finally {
  rl.close();
  await pool.end();
}
