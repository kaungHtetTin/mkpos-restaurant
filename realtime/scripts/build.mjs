import { cp, mkdir, readFile, writeFile, mkdtemp } from 'node:fs/promises';
import { resolve, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const artifacts = resolve(root, '../artifacts/realtime');
await mkdir(artifacts, { recursive: true });
const staging = await mkdtemp(join(artifacts, 'package-'));
const target = join(staging, 'restaurant-realtime');
await mkdir(target);
for (const file of ['index.js', 'server.mjs', 'package-lock.json', '.env.example', 'start.cmd', 'start.sh', 'Dockerfile', '.dockerignore', 'ecosystem.config.cjs', 'deploy']) {
  await cp(join(root, file), join(target, file), { recursive: true });
}
const sourcePackage = JSON.parse(await readFile(join(root, 'package.json'), 'utf8'));
const { scripts: _scripts, ...packageData } = sourcePackage;
await writeFile(join(target, 'package.json'), JSON.stringify({ ...packageData, scripts: { start: 'node index.js' } }, null, 2));
await cp(join(root, 'node_modules/ws'), join(target, 'node_modules/ws'), { recursive: true });
await writeFile(join(target, 'DEPLOY.txt'), `Restaurant realtime service\n\nNode.js >=22.16 is required. Production dependencies are included.\n\n1. Extract this folder and copy .env.example to .env.\n2. Set REALTIME_SECRET to exactly the same long random value as Laravel.\n3. Start with npm start or node index.js. Hosting startup file: index.js.\n4. Attach restaurant-socket.mkposmyanmar.com with HTTPS/WebSocket proxying.\n\nPublic WebSocket: wss://restaurant-socket.mkposmyanmar.com/socket\nLaravel publish URL: https://restaurant-socket.mkposmyanmar.com/publish\nHealth check: https://restaurant-socket.mkposmyanmar.com/health\nAllowed browser origin: https://restaurant.mkposmyanmar.com\n\nThe hosting PORT environment variable overrides REALTIME_PORT.\nUse one process/instance; clients are held in memory, without a shared message broker.\nPM2 and Docker launch configuration, and an example Nginx proxy, are included.\nNo production secret is included.\n`);
const archive = join(artifacts, 'Restaurant-Realtime-1.0.0-node.zip');
function archiveContents(folder, destination) {
  if (process.platform === 'win32') {
    const ps = value => "'" + value.replaceAll("'", "''") + "'";
    execFileSync('powershell', ['-NoProfile', '-Command', `$ErrorActionPreference = 'Stop'; Add-Type -AssemblyName System.IO.Compression.FileSystem; if (Test-Path -LiteralPath ${ps(destination)}) { Remove-Item -LiteralPath ${ps(destination)} }; [System.IO.Compression.ZipFile]::CreateFromDirectory(${ps(folder)}, ${ps(destination)})`], { windowsHide: true, stdio: 'inherit' });
  } else {
    execFileSync('zip', ['-qr', destination, '.'], { cwd: folder, stdio: 'inherit' });
  }
}
archiveContents(target, archive);
console.log('Deployment package: ' + archive);
const hostinger = join(staging, 'hostinger');
await mkdir(hostinger);
for (const file of ['package.json', 'package-lock.json', 'index.js', 'server.mjs']) {
  await cp(join(target, file), join(hostinger, file));
}
const timestamp = new Date().toISOString().replace(/[-:]/g, '').replace('T', '_').slice(0, 15);
const hostingerArchive = join(artifacts, `Restaurant-Realtime-1.0.0-hostinger_${timestamp}.zip`);
archiveContents(hostinger, hostingerArchive);
console.log('Hostinger upload package: ' + hostingerArchive);
