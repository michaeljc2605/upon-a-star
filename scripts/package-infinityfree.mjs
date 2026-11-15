/** Build a clean deployment directory; never copy private configuration or database records. */
import { cp, mkdir, readFile, readdir, writeFile, stat } from 'node:fs/promises';
import { dirname, resolve, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { prepareSnapshot } from '../deployment/init-database.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const destination = process.argv[2] ? resolve(process.argv[2]) : resolve(root, 'build/infinityfree');
try { await stat(destination); throw new Error('Choose a new output directory; existing files will not be replaced.'); }
catch (error) { if (error.code !== 'ENOENT') throw error; }
await mkdir(join(destination, 'htdocs'), { recursive: true });
const files = [];
async function copyPublic(directory) {
  for (const entry of await readdir(join(root, directory), { withFileTypes: true })) {
    const source = join(directory, entry.name);
    if (entry.isSymbolicLink()) throw new Error(`Unexpected symlink in publication source: ${source}`);
    if (entry.name === 'config.local.php' || entry.name.startsWith('.env') || entry.name === '.DS_Store'
        || entry.name.startsWith('._') || source === 'base/database') continue;
    if (entry.isDirectory()) { await copyPublic(source); continue; }
    if (!entry.isFile() || !(/\.(?:php|css|js|webp|png|jpe?g|svg|txt)$/i.test(entry.name) || entry.name === '.htaccess')) continue;
    if (/\.(?:test|spec)\./.test(entry.name)) continue;
    const target = join(destination, 'htdocs', source);
    await mkdir(dirname(target), { recursive: true });
    await cp(join(root, source), target, { errorOnExist: true, force: false });
    files.push(source);
  }
}
await copyPublic('base');
await copyPublic('php');
await cp(join(root, 'index.php'), join(destination, 'htdocs/index.php'));
await cp(join(root, 'deployment/infinityfree/.htaccess'), join(destination, 'htdocs/.htaccess'));
files.push('index.php', '.htaccess');
let sql = prepareSnapshot(await readFile(join(root, 'database/submission/silver_screen.sql'), 'utf8'), 'silver_screen');
sql = sql.replace(/^CREATE DATABASE IF NOT EXISTS `silver_screen` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\s*$/m, '')
  .replace(/^USE `silver_screen`;\s*$/m, '');
await mkdir(join(destination, 'database'));
await writeFile(join(destination, 'database/empty-database-import.sql'), '-- Import ONLY into a new empty database already selected in phpMyAdmin.\n-- No database is selected or dropped by this file.\n' + sql);
await writeFile(join(destination, 'manifest.json'), JSON.stringify({ backend: 'native-php', files: files.sort(), privateConfigurationIncluded: false }, null, 2) + '\n');
await writeFile(join(destination, 'README.txt'), `Upon a Star — full native PHP deployment\n\nUpload only the CONTENTS of htdocs into the hosting account's htdocs.\nImport database/empty-database-import.sql into a NEW EMPTY database selected in phpMyAdmin.\nDo not upload the database folder, this README or manifest.json into htdocs.\nCreate php/config.local.php privately from php/config.example.php and supply the assigned database and service values.\nSet PHP_BACKEND=native, PHP_SITE_PATH='', and the exact HTTPS PHP_PUBLIC_URL.\nThe runtime database name must match the hosting account's assigned database name.\nGoogle sign-in and EmailJS still require their real service settings.\nRead deployment documentation and verify the full hosted flow before publishing the URL.\n`);
console.log(`Prepared ${files.length} public runtime files in ${relative(process.cwd(), destination)}. No live configuration was copied.`);
