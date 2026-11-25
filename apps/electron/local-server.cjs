const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const crypto = require('node:crypto');
const { spawn } = require('node:child_process');
const { pathToFileURL } = require('node:url');

function freePort() {
  return new Promise((resolve, reject) => {
    const socket = net.createServer(); socket.on('error', reject);
    socket.listen(0, '127.0.0.1', () => { const { port } = socket.address(); socket.close(() => resolve(port)); });
  });
}

function runPhp(php, args, cwd, env) {
  return new Promise((resolve, reject) => {
    const child = spawn(php, args, { cwd, env, windowsHide: true }); let output = '';
    child.stdout.on('data', chunk => { output += chunk; }); child.stderr.on('data', chunk => { output += chunk; });
    child.on('error', reject); child.on('exit', code => code === 0 ? resolve(output) : reject(new Error('Local server setup failed: ' + output.slice(-4000))));
  });
}

async function startLocalServer(resources, dataRoot, phpOverride) {
  const source = path.join(resources, 'backend');
  const root = path.join(dataRoot, 'local-server');
  fs.mkdirSync(root, { recursive: true });
  // App code is refreshed from this build; customer files and the SQLite database stay in the data directory.
  for (const name of ['app', 'bootstrap', 'config', 'public', 'routes', 'resources', 'vendor']) await fs.promises.cp(path.join(source, name), path.join(root, name), { recursive: true });
  await fs.promises.cp(path.join(source, 'database/migrations'), path.join(root, 'database/migrations'), { recursive: true });
  await fs.promises.cp(path.join(source, 'database/seeders'), path.join(root, 'database/seeders'), { recursive: true });
  await fs.promises.copyFile(path.join(source, 'artisan'), path.join(root, 'artisan'));
  await fs.promises.copyFile(path.join(source, 'composer.json'), path.join(root, 'composer.json'));
  for (const directory of ['storage/app/private', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs']) fs.mkdirSync(path.join(root, directory), { recursive: true });
  const database = path.join(root, 'database/local.sqlite');
  if (!fs.existsSync(database)) fs.writeFileSync(database, '');
  const identityPath = path.join(root, 'local-identity.json');
  if (!fs.existsSync(identityPath)) fs.writeFileSync(identityPath, JSON.stringify({ key: 'base64:' + crypto.randomBytes(32).toString('base64'), secret: crypto.randomBytes(32).toString('hex'), password: crypto.randomBytes(18).toString('base64url') }));
  const identity = JSON.parse(fs.readFileSync(identityPath, 'utf8'));
  const port = await freePort(); const socketPort = await freePort(); const url = `http://127.0.0.1:${port}`;
  const php = phpOverride || path.join(resources, 'php/php.exe');
  const iniPath = path.join(root, 'php.ini');
  fs.writeFileSync(iniPath, fs.readFileSync(path.join(path.dirname(php), 'php.ini'), 'utf8').replace('extension_dir="ext"', 'extension_dir="' + path.join(path.dirname(php), 'ext').replaceAll('\\', '/') + '"'));
  const env = { ...process.env, PHPRC: iniPath, PHP_INI_SCAN_DIR: '', APP_ENV: 'production', APP_DEBUG: 'false', APP_KEY: identity.key, APP_URL: url,
    DB_CONNECTION: 'sqlite', DB_DATABASE: database, DB_URL: '', SESSION_DRIVER: 'database', SESSION_SECURE_COOKIE: 'false', CACHE_STORE: 'file', QUEUE_CONNECTION: 'sync',
    LOG_CHANNEL: 'single', MAIL_MAILER: 'log', REALTIME_ENABLED: 'true', REALTIME_SECRET: identity.secret,
    REALTIME_PUBLISH_URL: `http://127.0.0.1:${socketPort}/publish`, REALTIME_PUBLIC_URL: `ws://127.0.0.1:${socketPort}/socket`,
    OFFICE_ADMIN_NAME: 'Local administrator', OFFICE_ADMIN_EMAIL: 'local-admin@restaurant.local', OFFICE_ADMIN_PASSWORD: identity.password };
  await runPhp(php, ['artisan', 'package:discover', '--no-interaction'], root, env);
  await runPhp(php, ['artisan', 'migrate', '--force', '--no-interaction'], root, env);
  await runPhp(php, ['artisan', 'db:seed', '--force', '--no-interaction'], root, env);
  const { createRealtimeServer } = await import(pathToFileURL(path.join(resources, 'realtime/server.mjs')).href);
  const realtime = createRealtimeServer({ secret: identity.secret, origins: [url], local: false });
  await new Promise((resolve, reject) => { realtime.server.once('error', reject); realtime.server.listen(socketPort, '127.0.0.1', resolve); });
  const logs = fs.openSync(path.join(root, 'storage/logs/php-server.log'), 'a');
  const child = spawn(php, ['-S', '127.0.0.1:' + port, '-t', path.join(root, 'public'), path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], { cwd: path.join(root, 'public'), env, windowsHide: true, stdio: ['ignore', logs, logs] });
  child.on('error', () => {});
  let closed = false;
  const close = () => {
    if (closed) return; closed = true;
    child.kill();
    void realtime.close(); fs.closeSync(logs);
  };
  for (let attempt = 0; attempt < 100; attempt++) {
    try { const response = await fetch(url + '/up', { signal: AbortSignal.timeout(2000) }); if (response.ok) return { url, root, admin: { email: env.OFFICE_ADMIN_EMAIL, password: identity.password }, close }; } catch { /* Wait for PHP to bind its local port. */ }
    if (child.exitCode !== null) break;
    await new Promise(resolve => setTimeout(resolve, 200));
  }
  close();
  throw new Error('The bundled PHP server did not start. See local-server/storage/logs/php-server.log in the app data folder.');
}

module.exports = { startLocalServer, freePort };
