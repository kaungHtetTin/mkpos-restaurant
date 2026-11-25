import test from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { cp, mkdtemp, mkdir, writeFile } from 'node:fs/promises';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { once } from 'node:events';
import { createHmac } from 'node:crypto';
import { WebSocket } from 'ws';

const root = dirname(fileURLToPath(import.meta.url));
const secret = 'standalone-service-secret-for-automated-tests-only';
const sign = value => createHmac('sha256', secret).update(value).digest('hex');

async function standalone(t, explicitEnv) {
  const artifacts = resolve(root, '../artifacts/realtime');
  await mkdir(artifacts, { recursive: true });
  const folder = await mkdtemp(join(artifacts, 'test-'));
  for (const file of ['server.mjs', 'index.js', 'package.json']) await cp(join(root, file), join(folder, file));
  await cp(join(root, 'node_modules/ws'), join(folder, 'node_modules/ws'), { recursive: true });
  const envPath = join(folder, explicitEnv ? 'custom.env' : '.env');
  await writeFile(envPath, `APP_ENV=production\nREALTIME_HOST=127.0.0.1\nREALTIME_PORT=1\nREALTIME_ORIGINS=https://restaurant.mkposmyanmar.com\nREALTIME_SECRET=${secret}\n`);
  const env = { ...process.env };
  for (const name of ['APP_KEY', 'APP_URL', 'APP_ENV', 'REALTIME_SECRET', 'REALTIME_ORIGINS', 'REALTIME_HOST', 'REALTIME_PORT', 'REALTIME_ENV_FILE']) delete env[name];
  env.PORT = '0';
  if (explicitEnv) env.REALTIME_ENV_FILE = envPath;
  const child = spawn(process.execPath, [join(folder, 'index.js')], { cwd: artifacts, env, windowsHide: true });
  t.after(() => child.kill());
  let output = '';
  const port = await new Promise((resolvePort, reject) => {
    const timeout = setTimeout(() => reject(new Error('Standalone service did not start: ' + output)), 5000);
    child.stdout.on('data', chunk => { output += chunk; const match = output.match(/listening on port (\d+)/); if (match) { clearTimeout(timeout); resolvePort(Number(match[1])); } });
    child.stderr.on('data', chunk => { output += chunk; });
    child.on('error', error => { clearTimeout(timeout); reject(error); });
    child.on('exit', code => { if (!/listening on port/.test(output)) { clearTimeout(timeout); reject(new Error('Service exited with ' + code + ': ' + output)); } });
  });
  return 'http://127.0.0.1:' + port;
}

for (const explicitEnv of [false, true]) {
  test('standalone startup loads ' + (explicitEnv ? 'an explicit environment file' : 'its own .env without Laravel') + ' and honors PORT', async t => {
    const url = await standalone(t, explicitEnv);
    const health = await fetch(url + '/health');
    assert.equal(health.status, 200);
    assert.deepEqual(await health.json(), { status: 'ok', transport: 'websocket' });
    const socket = new WebSocket(url.replace('http:', 'ws:') + '/socket', { origin: 'https://restaurant.mkposmyanmar.com' });
    t.after(() => socket.terminate());
    await once(socket, 'open');
    const payload = Buffer.from(JSON.stringify({ channels: ['business:1'], exp: Math.floor(Date.now() / 1000) + 60 })).toString('base64url');
    const ready = once(socket, 'message');
    socket.send(JSON.stringify({ type: 'auth', token: payload + '.' + sign(payload) }));
    assert.equal(JSON.parse(String((await ready)[0])).type, 'ready');
    const changed = once(socket, 'message');
    const body = JSON.stringify({ id: 'standalone-test', channels: ['business:1'], topic: 'business', disconnect: [] });
    const timestamp = String(Math.floor(Date.now() / 1000));
    const response = await fetch(url + '/publish', { method: 'POST', body, headers: { 'X-Realtime-Timestamp': timestamp, 'X-Realtime-Signature': sign(timestamp + '.' + body) } });
    assert.equal(response.status, 202);
    assert.equal(JSON.parse(String((await changed)[0])).id, 'standalone-test');
  });
}
