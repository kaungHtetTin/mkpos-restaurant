import { createServer } from 'node:http';
import { createHmac, timingSafeEqual, createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { WebSocketServer, WebSocket } from 'ws';

export function createRealtimeServer({ secret, origins, local = false, heartbeatMs = 25000 }) {
  if (!secret || secret.length < 32) throw new Error('Configure a strong REALTIME_SECRET or backend APP_KEY.');
  const sockets = new WebSocketServer({ noServer: true, maxPayload: 4096, perMessageDeflate: false });
  const equal = (a, b) => typeof a === 'string' && typeof b === 'string' && a.length === b.length && timingSafeEqual(Buffer.from(a), Buffer.from(b));
  const signature = value => createHmac('sha256', secret).update(value).digest('hex');
  const validOrigin = origin => {
    if (origins.includes(origin)) return true;
    try { const url = new URL(origin); return local && ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname) && ['http:', 'https:'].includes(url.protocol); } catch { return false; }
  };
  const server = createServer(async (req, res) => {
    if (req.method === 'GET' && req.url === '/health') { res.writeHead(200, {'Content-Type':'application/json'}).end('{"status":"ok","transport":"websocket"}'); return; }
    if (req.method !== 'POST' || req.url !== '/publish') { res.writeHead(404).end(); return; }
    let body = '';
    try {
      for await (const chunk of req) { body += chunk; if (Buffer.byteLength(body) > 16384) { res.writeHead(413).end(); return; } }
      const timestamp = req.headers['x-realtime-timestamp'];
      if (!/^\d{10}$/.test(timestamp || '') || Math.abs(Date.now()/1000 - Number(timestamp)) > 30 || !equal(signature(timestamp+'.'+body), req.headers['x-realtime-signature'])) { res.writeHead(401).end(); return; }
      const event = JSON.parse(body);
      if (!Array.isArray(event.channels) || !Array.isArray(event.disconnect) || typeof event.id !== 'string' || typeof event.topic !== 'string' || [...event.channels,...event.disconnect].some(channel=>typeof channel !== 'string' || channel.length > 150)) { res.writeHead(422).end(); return; }
      if (event.request_key != null && (typeof event.request_key !== 'string' || event.request_key.length > 100)) { res.writeHead(422).end(); return; }
      for (const client of sockets.clients) {
        if (!client.channels) continue;
        if (event.channels.some(channel=>client.channels.includes(channel)) && client.readyState === WebSocket.OPEN) {
          if (client.bufferedAmount > 65536) { client.terminate(); continue; }
          client.send(JSON.stringify({type:'changed', id:event.id, topic:event.topic,...(typeof event.request_key === 'string'?{request_key:event.request_key}:{})}));
        }
        if (event.disconnect.some(channel=>client.channels.includes(channel))) client.close(4001, 'Authorization changed');
      }
      res.writeHead(202).end();
    } catch { if (!res.headersSent) res.writeHead(400).end(); }
  });
  server.on('upgrade', (request, socket, head) => {
    socket.on('error', ()=>{});
    if (request.url !== '/socket' || !validOrigin(request.headers.origin)) { socket.end('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n'); return; }
    sockets.handleUpgrade(request, socket, head, client => sockets.emit('connection', client));
  });
  sockets.on('connection', client => {
    client.on('error', ()=>{});
    client.alive = true;
    const authTimer = setTimeout(()=>client.close(4003,'Authentication required'), 5000);
    let expiryTimer;
    client.on('pong', ()=>{client.alive=true;});
    client.on('message', raw => {
      if (client.channels) { client.close(1008, 'Read only connection'); return; }
      try {
        const message = JSON.parse(raw.toString());
        if (message.type !== 'auth' || typeof message.token !== 'string') throw new Error();
        const [encoded, mac, extra] = message.token.split('.');
        if (extra || !equal(signature(encoded), mac)) throw new Error();
        const claims = JSON.parse(Buffer.from(encoded,'base64url').toString());
        const duration = claims.exp*1000-Date.now();
        if (!Number.isFinite(duration) || duration <= 0 || duration > 86400000 || !Array.isArray(claims.channels) || !claims.channels.length || claims.channels.some(channel=>typeof channel !== 'string')) throw new Error();
        client.channels = claims.channels;
        clearTimeout(authTimer);
        expiryTimer=setTimeout(()=>client.close(4001,'Session renewal required'), duration);
        client.send(JSON.stringify({type:'ready'}));
      } catch { client.close(4003,'Invalid credentials'); }
    });
    client.on('close',()=>{clearTimeout(authTimer);clearTimeout(expiryTimer);});
  });
  const heartbeat = setInterval(()=>{
    for (const client of sockets.clients) {
      if (!client.alive) { client.terminate(); continue; }
      client.alive=false; client.ping();
    }
  }, heartbeatMs);
  server.on('close',()=>clearInterval(heartbeat));
  return { server, sockets, close: async () => { clearInterval(heartbeat); for(const client of sockets.clients)client.terminate(); await new Promise(resolve=>sockets.close(resolve)); await new Promise(resolve=>server.close(resolve)); } };
}

export function startRealtimeFromEnvironment() {
  const envFile = process.env.REALTIME_ENV_FILE ? resolve(process.env.REALTIME_ENV_FILE)
    : [new URL('./.env', import.meta.url), new URL('../backend/.env', import.meta.url)].map(fileURLToPath).find(existsSync);
  if (envFile) process.loadEnvFile(envFile);
  const secret = process.env.REALTIME_SECRET || (process.env.APP_KEY ? createHash('sha256').update('realtime:'+process.env.APP_KEY).digest('hex') : '');
  const port = Number(process.env.PORT || process.env.REALTIME_PORT || 8081);
  if (!Number.isInteger(port) || port < 0 || port > 65535) throw new Error('PORT or REALTIME_PORT must be a valid port number.');
  const app = createRealtimeServer({secret, origins:(process.env.REALTIME_ORIGINS || process.env.APP_URL || '').split(',').map(value=>value.trim()), local:process.env.APP_ENV === 'local'});
  app.server.listen(port, process.env.REALTIME_HOST || '0.0.0.0', ()=>console.log('Restaurant WebSocket service listening on port '+app.server.address().port));
  let stopping = false;
  const shutdown=()=>{ if (stopping) return; stopping = true; void app.close().then(()=>process.exit(0)); };
  process.on('SIGINT',shutdown);process.on('SIGTERM',shutdown);
  return app;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === resolve(process.argv[1])) {
  startRealtimeFromEnvironment();
}
