const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { normalize, defaults, registerPrinting } = require('../electron/printer-manager.cjs');

test('saved receipt printer is silent, bounded and keeps paper and device choices separate', () => {
  const { config, hardware } = normalize({ ...defaults(), printerName: 'Receipt USB', documentPrinterName: 'Office A4', copies: 2 });
  assert.equal(hardware.windows.silent, true);
  assert.equal(hardware.windows.printerName, 'Receipt USB');
  assert.equal(hardware.documentPrinterName, 'Office A4');
  assert.equal(hardware.windows.copies, 2);
  assert.equal(config.paper, '80mm');
  assert.equal(normalize({ connection: 'bluetooth', address: 'com5' }).hardware.bluetooth.port, 'COM5');
  for (const invalid of [{ connection: 'usb' }, { copies: 0 }, { copies: 11 }, { connection: 'tcp', host: '' }, { connection: 'tcp', host: 'https://printer.local' }, { connection: 'bluetooth', address: 'COM5', paper: 'a4' }, { connection: 'bluetooth', address: 'BLE' }]) assert.throws(() => normalize(invalid));
});

test('printer IPC rejects other windows, child frames and untrusted origins and persists device configuration', async () => {
  const build = path.resolve(__dirname, '../build'); fs.mkdirSync(build, { recursive: true });
  const folder = fs.mkdtempSync(path.join(build, 'printer-profile-test-'));
  const handlers = new Map();
  const frame = { url: 'https://restaurant.mkposmyanmar.com/settings' };
  const owner = { webContents: { mainFrame: frame, getPrintersAsync: async () => [{ name: 'Receipt USB', isDefault: true }] } };
  registerPrinting({ app: { getPath: () => folder }, BrowserWindow: {}, dialog: {}, shell: {}, getMainWindow: () => owner, ipcMain: { handle: (key, fn) => handlers.set(key, fn) }, serverUrl: 'https://restaurant.mkposmyanmar.com' });
  const event = { sender: owner.webContents, senderFrame: frame };
  assert.throws(() => handlers.get('printer:getConfig')({ sender: {}, senderFrame: frame }), /only/);
  assert.throws(() => handlers.get('printer:getConfig')({ ...event, senderFrame: { url: frame.url } }), /only/);
  frame.url = 'https://attacker.test';
  assert.throws(() => handlers.get('printer:getConfig')(event), /only/);
  frame.url = 'https://restaurant.mkposmyanmar.com/settings';
  const saved = handlers.get('printer:saveConfig')(event, { config: { ...defaults(), printerName: 'Receipt USB', paper: '58mm' } });
  assert.equal(saved.paper, '58mm');
  assert.equal(handlers.get('printer:getConfig')(event).printerName, 'Receipt USB');
  assert.equal(fs.existsSync(path.join(folder, 'printer.json')), true);
  assert.match((await handlers.get('printer:checkConnection')(event, { config: saved })).message, /queue found/);
  assert.equal((await handlers.get('printer:listDevices')(event)).items[0].address, 'Receipt USB');
});
