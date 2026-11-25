const { _electron } = require('../../frontend/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');

(async () => {
  const dataRoot = fs.mkdtempSync(path.resolve(__dirname, '../build/electron-smoke-'));
  const environment = { ...process.env, POS_SMOKE_TEST: '1', POS_TEST_DATA_DIR: dataRoot };
  delete environment.ELECTRON_RUN_AS_NODE;
  const arch = process.argv[2] || 'x64';
  const packaged = path.resolve(__dirname, '../../artifacts/windows/' + (arch === 'ia32' ? 'win-ia32-unpacked' : 'win-unpacked') + '/Little Lemon POS.exe');
  const electron = await _electron.launch({ args: [], executablePath: packaged, env: environment, timeout: 30000 });
  const mainProcess = electron.process();
  try {
    electron.on('console', message => console.log(message.text()));
    await electron.evaluate(({ dialog }) => {
      dialog.showMessageBox = async (...args) => {
        const options = args.at(-1);
        console.error('Native dialog: ' + options.message + '\n' + (options.detail || ''));
        return { response: 0 };
      };
    });
    const page = await electron.firstWindow();
    page.setDefaultTimeout(30000);
    await page.getByRole('heading', { name: 'Connect to your restaurant' }).waitFor();
    assert.equal(await page.locator('#server').inputValue(), 'https://restaurant.mkposmyanmar.com');
    console.log('Setup screen is ready.');
    await page.getByRole('button', { name: 'Run locally on this PC' }).click({ noWaitAfter: true, timeout: 120000 });
    await page.waitForURL(/http:\/\/127\.0\.0\.1:\d+\//, { timeout: 120000 });
    console.log('Local page loaded:', page.url());
    await page.getByLabel('Email', { exact: true }).waitFor({ state: 'attached' });
    const isolation = await page.evaluate(() => ({ node: typeof window.require, desktop: typeof window.Desktop }));
    assert.deepEqual(isolation, { node: 'undefined', desktop: 'undefined' });
    console.log(arch + ': Electron setup, local backend, login screen, and remote-context isolation passed.');
  } finally {
    let timer;
    await Promise.race([electron.close().catch(() => {}), new Promise(resolve => { timer = setTimeout(() => { mainProcess.kill(); resolve(); }, 5000); })]);
    clearTimeout(timer);
    if (mainProcess.exitCode === null) spawnSync('taskkill', ['/PID', String(mainProcess.pid), '/T', '/F'], { windowsHide: true, stdio: 'ignore' });
  }
})().then(() => process.exit(0)).catch(error => { console.error(error); process.exit(1); });
