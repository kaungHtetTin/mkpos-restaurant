const { app, BrowserWindow, Menu, ipcMain, dialog, shell, session } = require('electron');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { normalizeServerUrl, sameOrigin } = require('./server-url.cjs');
const production = require('../production.json');
const { startLocalServer } = require('./local-server.cjs');

if (process.env.POS_SMOKE_TEST && process.env.POS_TEST_DATA_DIR) {
  fs.mkdirSync(process.env.POS_TEST_DATA_DIR, { recursive: true }); app.setPath('userData', process.env.POS_TEST_DATA_DIR);
} else if (process.env.PORTABLE_EXECUTABLE_DIR) {
  const data = path.join(process.env.PORTABLE_EXECUTABLE_DIR, 'Little-Lemon-POS-Data');
  fs.mkdirSync(data, { recursive: true }); app.setPath('userData', data);
}

let mainWindow;
let settingsWindow;
let serverUrl = production.appUrl;
let mode = 'setup';
let localServer;
const setupPath = path.join(__dirname, '../shell/index.html');
const setupUrl = pathToFileURL(setupPath).href;
const preferencesPath = () => path.join(app.getPath('userData'), 'server.json');
const preferences = { sandbox: true, contextIsolation: true, nodeIntegration: false, webSecurity: true, preload: path.join(__dirname, 'preload.cjs') };
const isSetup = contents => contents.getURL().split('?')[0] === setupUrl;

function secureWindow(window) {
  window.webContents.on('will-navigate', (event, url) => {
    if (url !== setupUrl && !sameOrigin(url, serverUrl)) event.preventDefault();
  });
  window.webContents.setWindowOpenHandler(({ url }) => {
    if (sameOrigin(url, serverUrl)) {
      const child = new BrowserWindow({ width: 1050, height: 850, webPreferences: preferences });
      secureWindow(child); child.loadURL(url);
    } else if (/^https?:\/\//.test(url)) {
      dialog.showMessageBox(window, { type: 'question', message: 'Open this link in your browser?', detail: url, buttons: ['Cancel', 'Open'], defaultId: 0 }).then(({ response }) => { if (response === 1) shell.openExternal(url); });
    }
    return { action: 'deny' };
  });
  window.webContents.on('will-attach-webview', event => event.preventDefault());
}

function showSettings() {
  if (settingsWindow && !settingsWindow.isDestroyed()) { settingsWindow.focus(); return; }
  settingsWindow = new BrowserWindow({ width: 600, height: 620, resizable: true, title: 'Restaurant server', webPreferences: preferences });
  settingsWindow.setMenu(null); secureWindow(settingsWindow); settingsWindow.loadFile(setupPath);
  settingsWindow.on('closed', () => { settingsWindow = null; });
}

async function openServer() {
  if (mode === 'setup') return mainWindow.loadFile(setupPath);
  if (mode === 'local' && !localServer) {
    try {
      localServer = await startLocalServer(app.isPackaged ? process.resourcesPath : path.join(__dirname, '../build'), app.getPath('userData'), app.isPackaged ? undefined : path.join(__dirname, '../.tooling/php-' + process.arch + '/php.exe'));
      serverUrl = localServer.url;
    } catch (error) {
      mode = 'setup'; await dialog.showMessageBox(mainWindow, { type: 'error', message: 'Local server could not start.', detail: error.message });
      return mainWindow.loadFile(setupPath);
    }
  }
  if (mode === 'local') serverUrl = localServer.url;
  try { await mainWindow.loadURL(serverUrl); }
  catch {
    if (!mainWindow.isDestroyed()) {
      await dialog.showMessageBox(mainWindow, { type: 'error', message: 'Cannot reach the restaurant server.', detail: 'Check that the backend is running and that this computer can reach ' + serverUrl });
      await mainWindow.loadFile(setupPath);
    }
  }
}

if (!app.requestSingleInstanceLock()) app.quit();
else {
  app.on('second-instance', () => { if (mainWindow) { if (mainWindow.isMinimized()) mainWindow.restore(); mainWindow.focus(); } });
  app.whenReady().then(async () => {
    try { const saved = JSON.parse(fs.readFileSync(preferencesPath(), 'utf8')); mode = saved.mode || 'cloud'; serverUrl = normalizeServerUrl(saved.serverUrl || production.appUrl); } catch { /* First launch uses the server setup screen. */ }
    session.defaultSession.setPermissionCheckHandler((contents, permission, origin) => !!contents && sameOrigin(origin, serverUrl) && ['media', 'clipboard-sanitized-write'].includes(permission));
    session.defaultSession.setPermissionRequestHandler(async (contents, permission, callback, details) => {
      if (!sameOrigin(details.requestingUrl || contents.getURL(), serverUrl)) return callback(false);
      if (permission === 'clipboard-sanitized-write') return callback(true);
      if (permission !== 'media' || details.mediaTypes?.some(type => type !== 'video')) return callback(false);
      const { response } = await dialog.showMessageBox(BrowserWindow.fromWebContents(contents), { type: 'question', message: 'Allow the restaurant app to use your camera?', buttons: ['Deny', 'Allow'], defaultId: 0 });
      callback(response === 1);
    });
    ipcMain.handle('server:settings', event => { if (!isSetup(event.sender)) throw new Error('Unavailable'); return { serverUrl: mode === 'local' ? production.appUrl : serverUrl, platform: 'windows', localSupported: true }; });
    ipcMain.handle('server:local', async event => {
      if (!isSetup(event.sender)) throw new Error('Unavailable');
      mode = 'local'; fs.mkdirSync(app.getPath('userData'), { recursive: true });
      fs.writeFileSync(preferencesPath(), JSON.stringify({ mode: 'local' }));
      if (settingsWindow && !settingsWindow.isDestroyed()) settingsWindow.close();
      await openServer(); return { ok: mode === 'local' };
    });
    ipcMain.handle('server:connect', async (event, value) => {
      if (!isSetup(event.sender)) throw new Error('Unavailable');
      try {
        const next = normalizeServerUrl(value);
        const response = await fetch(next + '/api/v1/platform/branding', { signal: AbortSignal.timeout(12000), redirect: 'error' });
        const branding = await response.json();
        if (!response.ok || typeof branding.name !== 'string') throw new Error('This address did not respond as a restaurant server.');
        fs.mkdirSync(app.getPath('userData'), { recursive: true });
        fs.writeFileSync(preferencesPath(), JSON.stringify({ mode: 'cloud', serverUrl: next }, null, 2));
        serverUrl = next; mode = 'cloud';
        if (settingsWindow && !settingsWindow.isDestroyed()) settingsWindow.close();
        void openServer(); return { ok: true };
      } catch (error) { return { ok: false, message: error.message }; }
    });
    mainWindow = new BrowserWindow({ show: !process.env.POS_SMOKE_TEST, width: 1360, height: 900, minWidth: 380, minHeight: 600, title: 'Little Lemon POS', webPreferences: preferences });
    secureWindow(mainWindow);
    Menu.setApplicationMenu(Menu.buildFromTemplate([
      { label: 'Restaurant', submenu: [{ label: 'Server settings…', click: showSettings }, { label: 'Home', accelerator: 'CmdOrCtrl+H', click: openServer }, { label: 'Open data folder', click: () => shell.openPath(app.getPath('userData')) }, { label: 'Local office administrator', click: () => dialog.showMessageBox(mainWindow, { message: 'Local office administrator', detail: localServer ? `Sign in at ${localServer.url}/office\nEmail: ${localServer.admin.email}\nInitial password: ${localServer.admin.password}\nChange this password after first sign-in.` : 'Start local mode first. Credentials apply only to this PC’s local database.' }) }, { type: 'separator' }, { label: 'Print', accelerator: 'CmdOrCtrl+P', click: () => BrowserWindow.getFocusedWindow()?.webContents.print({ printBackground: true }) }, { type: 'separator' }, { role: 'quit' }] },
      { label: 'Edit', submenu: [{ role: 'undo' }, { role: 'redo' }, { type: 'separator' }, { role: 'cut' }, { role: 'copy' }, { role: 'paste' }, { role: 'selectAll' }] },
      { label: 'View', submenu: [{ label: 'Back', accelerator: 'Alt+Left', click: () => { if (mainWindow.webContents.navigationHistory.canGoBack()) mainWindow.webContents.navigationHistory.goBack(); } }, { role: 'reload' }, { role: 'resetZoom' }, { role: 'zoomIn' }, { role: 'zoomOut' }, { role: 'togglefullscreen' }] },
    ]));
    await openServer();
  });
  app.on('window-all-closed', () => app.quit());
  app.on('before-quit', () => localServer?.close());
}
