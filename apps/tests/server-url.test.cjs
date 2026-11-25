const test = require('node:test');
const assert = require('node:assert/strict');
const { normalizeServerUrl, sameOrigin } = require('../electron/server-url.cjs');

test('server settings accept only origin URLs, without embedded credentials', () => {
  assert.equal(normalizeServerUrl(' https://restaurant.mkposmyanmar.com/ '), 'https://restaurant.mkposmyanmar.com');
  assert.equal(normalizeServerUrl('http://192.168.1.10:8002'), 'http://192.168.1.10:8002');
  for (const url of ['file:///C:/test.html', 'javascript:alert(1)', 'https://user:password@example.com', 'https://example.com/path', 'https://example.com?url=x', 'not a url']) assert.throws(() => normalizeServerUrl(url));
  assert.equal(sameOrigin('https://restaurant.mkposmyanmar.com/orders/1', 'https://restaurant.mkposmyanmar.com'), true);
  assert.equal(sameOrigin('https://restaurant.mkposmyanmar.com.attacker.test', 'https://restaurant.mkposmyanmar.com'), false);
});
