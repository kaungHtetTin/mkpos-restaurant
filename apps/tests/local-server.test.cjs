const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { startLocalServer } = require('../electron/local-server.cjs');
const WebSocket = require('../../realtime/node_modules/ws');

for (const arch of ['x64', 'ia32']) {
  const php = path.resolve(__dirname, '../.tooling/php-' + arch + '/php.exe');
  test('bundled ' + arch + ' PHP boots a fresh database and provides authenticated realtime', { skip: !fs.existsSync(php), timeout: 120000 }, async t => {
    const dataRoot = fs.mkdtempSync(path.resolve(__dirname, '../build/test-' + arch + '-'));
    const local = await startLocalServer(path.resolve(__dirname, '../build'), dataRoot, php);
    t.after(() => local.close());
    const branding = await fetch(local.url + '/api/v1/platform/branding');
    assert.equal(branding.status, 200); assert.equal(typeof (await branding.json()).name, 'string');
    const session = await fetch(local.url + '/api/v1/office/session');
    const sessionData = await session.json();
    const cookies = session.headers.getSetCookie().map(cookie => cookie.split(';')[0]).join('; ');
    const login = await fetch(local.url + '/api/v1/office/login', { method: 'POST', headers: { Cookie: cookies, 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': sessionData.csrf }, body: JSON.stringify(local.admin) });
    assert.equal(login.status, 200, await login.text());
    const authenticatedCookies = login.headers.getSetCookie().map(cookie => cookie.split(';')[0]).join('; ');
    const realtimeResponse = await fetch(local.url + '/api/v1/office/realtime', { headers: { Cookie: authenticatedCookies, Accept: 'application/json' } });
    assert.equal(realtimeResponse.status, 200);
    const credentials = await realtimeResponse.json();
    await new Promise((resolve, reject) => {
      const socket = new WebSocket(credentials.url, { origin: local.url });
      const timeout = setTimeout(() => { socket.terminate(); reject(new Error('Local realtime authentication timed out.')); }, 5000);
      socket.on('error', reject);
      socket.on('open', () => socket.send(JSON.stringify({ type: 'auth', token: credentials.token })));
      socket.on('message', message => { if (JSON.parse(String(message)).type === 'ready') { clearTimeout(timeout); socket.close(); resolve(); } });
    });
    const identity = JSON.parse(fs.readFileSync(path.join(local.root, 'local-identity.json')));
    assert.ok(identity.password.length >= 20);
    assert.ok(fs.statSync(path.join(local.root, 'database/local.sqlite')).size > 0);
  });
}
