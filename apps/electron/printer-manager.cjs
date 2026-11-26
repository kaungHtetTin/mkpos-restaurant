const fs = require('node:fs');
const path = require('node:path');
const { randomUUID } = require('node:crypto');
const { normalizeConfig } = require('./printer-config.cjs');
const { sendTcpBytes } = require('./escpos.cjs');
const { runSerial, serialRequest } = require('./serial.cjs');
const { createPrintService } = require('./printing.cjs');
const { sameOrigin } = require('./server-url.cjs');

function defaults() {
  return { connection: 'system', paper: '80mm', printerName: '', documentPrinterName: '', host: '', port: 9100, address: '', copies: 1, cut: false, color: false, landscape: false, bluetooth_mode: 'standard' };
}
function normalize(input = {}) {
  const c = { ...defaults(), ...input };
  if (!['system', 'tcp', 'bluetooth'].includes(c.connection)) throw new Error('Choose a supported connection type.');
  if (!['50mm', '58mm', '80mm', '85mm', 'a4', 'a5'].includes(c.paper)) throw new Error('Choose a supported paper size.');
  if (c.connection !== 'system' && ['a4', 'a5'].includes(c.paper)) throw new Error('Choose thermal paper for direct printing.');
  if (!['standard', 'compatibility'].includes(c.bluetooth_mode) || typeof c.cut !== 'boolean') throw new Error('Invalid thermal printer settings.');
  const hardware = normalizeConfig({ connection: c.connection === 'system' ? 'windows' : c.connection,
    documentPrinterName: c.documentPrinterName,
    windows: { printerName: c.printerName, silent: true, copies: c.copies, color: c.color, landscape: c.landscape },
    tcp: c.connection === 'tcp' ? { host: c.host, port: c.port } : undefined,
    bluetooth: { port: c.address }, thermal: { copies: c.copies, cut: c.cut ? 'full' : 'none', chunkSize: c.bluetooth_mode === 'compatibility' ? 128 : 512, chunkDelayMs: c.bluetooth_mode === 'compatibility' ? 30 : 10 },
  });
  return { config: { ...c, address: hardware.bluetooth.port, copies: hardware.thermal.copies, port: hardware.tcp.port, ...(c.connection === 'tcp' ? { host: hardware.tcp.host } : {}) }, hardware };
}
function registerPrinting({ app, BrowserWindow, ipcMain, dialog, shell, getMainWindow, serverUrl }) {
  const file = path.join(app.getPath('userData'), 'printer.json');
  const read = () => normalize(fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8')) : defaults());
  const print = createPrintService({ BrowserWindow, getMainWindow, versions: process.versions,
    sendSerialBytes: (config, bytes) => runSerial('send', serialRequest(config, bytes)),
    savePdf: async (owner, pdf) => {
      const result = await dialog.showSaveDialog(owner, { title: 'Save MKPOS document as PDF', defaultPath: 'MKPOS-document.pdf', filters: [{ name: 'PDF', extensions: ['pdf'] }] });
      if (result.canceled || !result.filePath) return false;
      await fs.promises.writeFile(result.filePath, pdf); return true;
    },
  });
  let checking = false;
  const guard = event => {
    const owner = getMainWindow();
    if (!owner || event.sender !== owner.webContents || event.senderFrame !== owner.webContents.mainFrame || !sameOrigin(event.senderFrame.url, serverUrl)) throw new Error('Printing is available only to the restaurant application.');
  };
  const handle = (method, callback) => ipcMain.handle('printer:' + method, (event, value) => { guard(event); return callback(value); });
  handle('getConfig', () => read().config);
  handle('saveConfig', ({ config }) => {
    if (print.isBusy() || checking) throw new Error('Finish the active print or connection check first.');
    const clean = normalize(config).config;
    fs.mkdirSync(path.dirname(file), { recursive: true });
    const temp = file + '.' + randomUUID() + '.tmp';
    try { fs.writeFileSync(temp, JSON.stringify(clean), { flag: 'wx' }); fs.renameSync(temp, file); }
    finally { if (fs.existsSync(temp)) fs.unlinkSync(temp); }
    return clean;
  });
  handle('listDevices', async () => ({ items: (await getMainWindow().webContents.getPrintersAsync()).map(p => ({ name: p.name, address: p.name, isDefault: p.isDefault })) }));
  handle('listPaired', () => runSerial('list').then(result => ({ items: result.items.map(p => ({ name: p.printerName || p.name, address: p.port })) })));
  handle('openBluetoothSettings', () => shell.openExternal('ms-settings:bluetooth'));
  handle('openSystemSettings', () => shell.openExternal('ms-settings:printers'));
  handle('checkConnection', async ({ config }) => {
    if (print.isBusy() || checking) throw new Error('Finish the active print or connection check first.');
    const { hardware } = normalize(config); checking = true;
    try {
      if (hardware.connection === 'windows') {
        const printers = await getMainWindow().webContents.getPrintersAsync();
        if (!printers.some(p => hardware.windows.printerName ? p.name === hardware.windows.printerName : p.isDefault)) throw new Error('Select an installed printer or set a Windows default printer.');
        return { message: 'Windows queue found. Use Test print to check actual output.' };
      }
      if (hardware.connection === 'bluetooth') await runSerial('test', serialRequest(hardware));
      else await sendTcpBytes(hardware.tcp, Buffer.alloc(0), hardware.thermal);
      return { message: 'Connection opened. No print data was sent.' };
    } finally { checking = false; }
  });
  handle('print', async payload => {
    if (checking) throw new Error('Finish the connection check first.');
    const { config, hardware } = read();
    const document = payload?.kind === 'document';
    if (document) { hardware.connection = 'windows'; hardware.windows.printerName = config.documentPrinterName; }
    return print({ html: payload?.html, paperSize: document ? 'a4' : config.paper, printerConfig: hardware });
  });
}
module.exports = { defaults, normalize, registerPrinting };
