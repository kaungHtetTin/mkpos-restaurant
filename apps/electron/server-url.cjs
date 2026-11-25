function normalizeServerUrl(value) {
  const url = new URL(String(value).trim());
  if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) throw new Error('Use an HTTP or HTTPS server address without a username or password.');
  if (url.pathname !== '/' || url.search || url.hash) throw new Error('Enter the server address only, such as https://pos.example.com.');
  return url.origin;
}

function sameOrigin(value, server) {
  try { return new URL(value).origin === new URL(server).origin; } catch { return false; }
}

module.exports = { normalizeServerUrl, sameOrigin };
