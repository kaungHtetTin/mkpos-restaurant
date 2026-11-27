const { _electron } = require('../../frontend/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const { listPackage } = require('@electron/asar');
const version = require('../package.json').version;
const production = require('../production.json');

function writeRasterBitmap(bytes, filename) {
  const frames = []; let offset = 2, height = 0, rowBytes = 0;
  while (bytes.subarray(offset, offset + 4).equals(Buffer.from([29, 118, 48, 0]))) {
    rowBytes = bytes.readUInt16LE(offset + 4); const rows = bytes.readUInt16LE(offset + 6);
    frames.push(bytes.subarray(offset + 8, offset + 8 + rowBytes * rows)); height += rows; offset += 8 + rowBytes * rows;
  }
  assert.ok(height > 20 && rowBytes === 48, '58mm raster should be non-empty and 384 dots wide');
  const raster = Buffer.concat(frames); assert.ok(raster.some(byte => byte !== 0), 'receipt must contain black pixels');
  const width = rowBytes * 8, stride = width * 3, bitmap = Buffer.alloc(54 + stride * height, 255);
  bitmap.fill(0, 0, 54);
  bitmap.write('BM'); bitmap.writeUInt32LE(bitmap.length, 2); bitmap.writeUInt32LE(54, 10); bitmap.writeUInt32LE(40, 14);
  bitmap.writeInt32LE(width, 18); bitmap.writeInt32LE(height, 22); bitmap.writeUInt16LE(1, 26); bitmap.writeUInt16LE(24, 28); bitmap.writeUInt32LE(0, 30); bitmap.writeUInt32LE(stride * height, 34);
  for (let y = 0; y < height; y++) for (let x = 0; x < width; x++) {
    const pixel = raster[y * rowBytes + (x >> 3)] & (128 >> (x & 7)) ? 0 : 255;
    const target = 54 + (height - 1 - y) * stride + x * 3; bitmap[target] = pixel; bitmap[target + 1] = pixel; bitmap[target + 2] = pixel;
  }
  fs.writeFileSync(filename, bitmap);
}
(async () => {
  fs.mkdirSync(path.resolve(__dirname, '../build'), { recursive: true });
  const dataRoot = fs.mkdtempSync(path.resolve(__dirname, '../build/cloud-smoke-'));
  fs.writeFileSync(path.join(dataRoot, 'server.json'), JSON.stringify({ mode: 'local' }));
  const environment = { ...process.env, POS_SMOKE_TEST: '1', POS_TEST_DATA_DIR: dataRoot }; delete environment.ELECTRON_RUN_AS_NODE;
  const arch = process.argv[2] || 'x64';
  const output = path.resolve(__dirname, '../../artifacts/windows/' + version);
  const folder = path.join(output, arch === 'ia32' ? 'win-ia32-unpacked' : 'win-unpacked');
  for (const resource of ['php', 'backend', 'realtime']) assert.equal(fs.existsSync(path.join(folder, 'resources', resource)), false);
  const jobs = [];
  const receiver = net.createServer(socket => { const chunks = []; socket.on('data', chunk => chunks.push(chunk)); socket.on('end', () => jobs.push(Buffer.concat(chunks))); });
  await new Promise(resolve => receiver.listen(0, '127.0.0.1', resolve));
  const electron = await _electron.launch({ args: [], executablePath: path.join(folder, 'MKPOS restaurant.exe'), env: environment, timeout: 45000 });
  const mainProcess = electron.process();
  try {
    const page = await electron.firstWindow(); page.setDefaultTimeout(45000);
    await page.waitForURL(production.appUrl + '/', { timeout: 60000 });
    await page.getByLabel('Email', { exact: true }).waitFor({ state: 'visible' });
    const isolation = await page.evaluate(() => ({ node: typeof window.require, desktop: typeof window.Desktop, printer: window.RestaurantPrinter?.platform }));
    assert.deepEqual(isolation, { node: 'undefined', desktop: 'undefined', printer: 'windows' });
    const contents = listPackage(path.join(folder, 'resources/app.asar'));
    assert.equal(contents.some(file => /local-server\.cjs|setup\.js/.test(file)), false);
    const config = await page.evaluate(async port => {
      const printer = window.RestaurantPrinter;
      const current = await printer.getConfig();
      const config = await printer.saveConfig({ config: { ...current, connection: 'tcp', host: '127.0.0.1', port, paper: '58mm', copies: 1, cut: true } });
      const result = await printer.checkConnection({ config }); return { config, result };
    }, receiver.address().port);
    assert.match(config.result.message, /No print data/);
    assert.equal(config.config.paper, '58mm');
    const font = fs.readFileSync(path.resolve(__dirname, '../../frontend/src/print-fonts/noto-sans-myanmar-myanmar-400-normal.woff2')).toString('base64');
    const logo = fs.readFileSync(path.resolve(__dirname, '../shell/icon.png')).toString('base64');
    const html = `<!doctype html><html><head><meta charset="utf-8"><style>@font-face{font-family:MM;src:url(data:font/woff2;base64,${font})}body{margin:0;width:58mm;background:white;color:black;font-family:MM,sans-serif}.receipt-shell{padding:10px}img{width:80px}h2{font-size:17px}p{font-size:14px}</style></head><body><div class="receipt-shell"><img src="data:image/png;base64,${logo}"><h2>MKPOS restaurant</h2><p>စမ်းသပ်ပုံနှိပ်ခြင်း</p><p>1 × Rice — 6,500 MMK</p><p>TEST ONLY / Not a sale</p></div></body></html>`;
    const result = await page.evaluate(async html => window.RestaurantPrinter.print({ html, title: 'Native printing QA', kind: 'receipt' }), html);
    assert.equal(result.status, 'submitted');
    for (let attempt = 0; jobs.length < 2 && attempt < 30; attempt++) await new Promise(resolve => setTimeout(resolve, 100));
    assert.equal(jobs[0].length, 0, 'connection check must not print');
    assert.deepEqual([...jobs[1].subarray(0, 2)], [27, 64]); assert.deepEqual([...jobs[1].subarray(-3)], [29, 86, 0]);
    writeRasterBitmap(jobs[1], path.join(output, arch + '-native-receipt.bmp'));
    await page.route(production.appUrl + '/**', route => route.abort('internetdisconnected'));
    assert.equal(await electron.evaluate(({ Menu }) => Menu.getApplicationMenu()), null, 'native menu bar must be absent');
    // CDP key dispatch bypasses native accelerators; exercise the reload path directly.
    await electron.evaluate(({ BrowserWindow }) => { BrowserWindow.getAllWindows().find(window => window.getTitle() === 'MKPOS restaurant').webContents.reload(); });
    await page.getByRole('heading', { name: 'Cannot reach MKPOS restaurant' }).waitFor();
    assert.equal(await page.evaluate(() => typeof window.RestaurantPrinter), 'undefined', 'offline page must not receive native printer access');
    await page.unroute(production.appUrl + '/**');
    await page.getByRole('link', { name: 'Retry connection' }).click();
    await page.waitForURL(production.appUrl + '/');
    assert.equal((await page.evaluate(() => window.RestaurantPrinter.getConfig())).paper, '58mm');
    console.log(arch + ': direct launch, protected bridge, saved printer, no-output connection check, real TCP raster print with Myanmar/logo/cut, and offline Retry passed.');
  } finally {
    receiver.close(); let timer;
    await Promise.race([electron.close().catch(() => {}), new Promise(resolve => { timer = setTimeout(() => { mainProcess.kill(); resolve(); }, 5000); })]); clearTimeout(timer);
    if (mainProcess.exitCode === null) spawnSync('taskkill', ['/PID', String(mainProcess.pid), '/T', '/F'], { windowsHide: true, stdio: 'ignore' });
  }
})().then(() => process.exit(0)).catch(error => { console.error(error); process.exit(1); });
