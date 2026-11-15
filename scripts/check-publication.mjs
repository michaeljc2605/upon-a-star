import { execFileSync } from 'node:child_process';
import { readdir, readFile } from 'node:fs/promises';
import { dirname, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const omittedDirectories = new Set(['.git', 'node_modules', 'dist', 'build', 'coverage', '.vite', '.cache']);
const envExamples = new Set(['server/.env.example', 'server/.env.production.example']);
const textExtensions = /\.(?:[cm]?js|jsx|json|php|html|css|md|sql|sh|ya?ml|example|txt|xml)$/i;
const privateTables = 'users|sessions|audit_events|bookings|booking_items|booking_tickets|payment_events|seat_holds|email_deliveries';
const privateInsert = new RegExp('(?:INSERT(?:\\s+(?:IGNORE|LOW_PRIORITY|HIGH_PRIORITY|DELAYED))*|REPLACE)\\s+INTO\\s+(?:`?\\w+`?\\.)?`?(?:' + privateTables + ')`?\\b', 'i');
const findings = [];
const report = (path, line, reason) => findings.push(`${path}${line ? `:${line}` : ''}: ${reason}`);

async function walk(directory = root) {
  const files = [];
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    if (omittedDirectories.has(entry.name) || entry.name === '.DS_Store' || entry.name.startsWith('._')) continue;
    const path = resolve(directory, entry.name);
    if (entry.isDirectory()) files.push(...await walk(path));
    else if (entry.isFile()) files.push(relative(root, path).replaceAll('\\', '/'));
  }
  return files;
}

let files;
try {
  const gitRoot = execFileSync('git', ['rev-parse', '--show-toplevel'], { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
  if (resolve(gitRoot) !== root) throw new Error('This directory is not the repository root.');
  files = execFileSync('git', ['ls-files', '--cached', '--others', '--exclude-standard', '-z'], { cwd: root, encoding: 'utf8' }).split('\0').filter(Boolean);
} catch {
  files = await walk();
}

for (const path of [...new Set(files)].sort()) {
  const filename = path.split('/').at(-1);
  if ((/^\.env(?:\.|$)/.test(filename) && !envExamples.has(path)) || /\.(?:pem|key|p12|pfx)$/i.test(filename) || filename === '.npmrc' || filename === 'config.local.php') {
    report(path, 0, 'private configuration or key file must not be published');
    continue;
  }
  if (filename === '.DS_Store' || path.split('/').some((part) => omittedDirectories.has(part))) {
    report(path, 0, 'generated or local file must not be published');
    continue;
  }
  if (!textExtensions.test(path)) continue;
  let source;
  try { source = await readFile(resolve(root, path), 'utf8'); }
  catch (error) { if (error.code === 'ENOENT') continue; throw error; }
  const lines = source.split(/\r?\n/);
  for (let index = 0; index < lines.length; index += 1) {
    const line = lines[index];
    if (/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/.test(line)) report(path, index + 1, 'private-key material detected; value withheld');
    if (/\b(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,}|AKIA[A-Z0-9]{16}|sk_live_[A-Za-z0-9]{20,}|xox[baprs]-[A-Za-z0-9-]{20,})\b/.test(line)) report(path, index + 1, 'credential-like token detected; value withheld');
    if (path.endsWith('.sql')) {
      if (privateInsert.test(line)) report(path, index + 1, 'private operational table data detected; values withheld');
      if (/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i.test(line)) report(path, index + 1, 'email address in database export; value withheld');
      if (/\$2[aby]\$\d\d\$/.test(line)) report(path, index + 1, 'password hash in database export; value withheld');
    }
    if (envExamples.has(path)) {
      const assignment = line.match(/^([A-Z0-9_]*(?:PASSWORD|PASS|SECRET|PRIVATE_KEY|OWNER_EMAILS))\s*=\s*(.*)$/);
      if (assignment && assignment[2].trim() && !/^(?:replace-with-a-secret|your-[a-z-]+)$/.test(assignment[2].trim())) report(path, index + 1, 'environment example contains a non-placeholder sensitive value; value withheld');
    }
  }
}

if (findings.length) {
  console.error(`Publication check failed (${findings.length} finding${findings.length === 1 ? '' : 's'}):\n${findings.join('\n')}`);
  process.exitCode = 1;
} else {
  console.log(`Publication check passed: ${new Set(files).size} source files checked; no blocked files or matched private-data patterns.`);
}
