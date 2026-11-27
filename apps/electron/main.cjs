const { app, BrowserWindow, Menu, dialog, shell, session } = require('electron');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { sameOrigin } = require('./server-url.cjs');
const production = require('../production.json');
const { registerPrinting } = require('./printer-manager.cjs');
app.setName('MKPOS restaurant');
const appIcon = path.join(__dirname, '../shell/icon.png');
const serverUrl = production.appUrl;
const offlinePath = path.join(__dirname, '../shell/index.html');
const offlineUrl = pathToFileURL(offlinePath).href;

if (process.env.POS_SMOKE_TEST && process.env.POS_TEST_DATA_DIR) {
  fs.mkdirSync(process.env.POS_TEST_DATA_DIR, { recursive: true }); app.setPath('userData', process.env.POS_TEST_DATA_DIR);
} else if (process.env.PORTABLE_EXECUTABLE_DIR) {
  const legacyData = path.join(process.env.PORTABLE_EXECUTABLE_DIR, 'Little-Lemon-POS-Data');
  const data = fs.existsSync(legacyData) ? legacyData : path.join(process.env.PORTABLE_EXECUTABLE_DIR, 'MKPOS-Restaurant-Data');
  fs.mkdirSync(data, { recursive: true }); app.setPath('userData', data);
}

let mainWindow;
let offlinePending = false;
const preferences = { sandbox: true, contextIsolation: true, nodeIntegration: false, webSecurity: true, preload: path.join(__dirname, 'preload.cjs') };

async function printingAction(method) {
  if (!sameOrigin(mainWindow.webContents.getURL(), serverUrl)) return;
  try {
    const supported = await mainWindow.webContents.executeJavaScript(`(async()=>{if(typeof window.${method} !== 'function')return false;await window.${method}();return true;})()`);
    if (!supported) throw new Error('Upload the latest website build to enable printer settings and native receipt printing.');
  } catch (error) { await dialog.showMessageBox(mainWindow, { type: 'error', message: 'Printing', detail: error.message }); }
}

async function showOffline() {
  if (!mainWindow || mainWindow.isDestroyed() || offlinePending || mainWindow.webContents.getURL() === offlineUrl) return;
  offlinePending = true;
  try { await mainWindow.loadFile(offlinePath); }
  finally { offlinePending = false; }
}

async function openServer() {
  try { await mainWindow.loadURL(serverUrl); }
  catch { await showOffline(); }
}

async function goBack() {
  if (sameOrigin(mainWindow.webContents.getURL(), serverUrl)) {
    const closed = await mainWindow.webContents.executeJavaScript('(function(){const event=new Event("restaurant:back-request",{cancelable:true});return !window.dispatchEvent(event)||Boolean(window.dismissRestaurantPrinterSettings&&window.dismissRestaurantPrinterSettings());})()');
    if (closed) return;
  }
  if (mainWindow.webContents.navigationHistory.canGoBack()) mainWindow.webContents.navigationHistory.goBack();
}

function secureWindow(window) {
  window.setMenu(null);
  window.webContents.on('page-title-updated', event => { event.preventDefault(); window.setTitle('MKPOS restaurant'); });
  window.webContents.on('will-navigate', (event, url) => {
    if (!sameOrigin(url, serverUrl)) event.preventDefault();
  });
  window.webContents.setWindowOpenHandler(({ url }) => {
    if (sameOrigin(url, serverUrl)) {
      const child = new BrowserWindow({ width: 1050, height: 850, title: 'MKPOS restaurant', icon: appIcon, webPreferences: preferences });
      secureWindow(child); child.loadURL(url);
    } else if (/^https?:\/\//.test(url)) {
      dialog.showMessageBox(window, { type: 'question', message: 'Open this link in your browser?', detail: url, buttons: ['Cancel', 'Open'], defaultId: 0 }).then(({ response }) => { if (response === 1) shell.openExternal(url); });
    }
    return { action: 'deny' };
  });
  window.webContents.on('will-attach-webview', event => event.preventDefault());
}

if (!app.requestSingleInstanceLock()) app.quit();
else {
  app.on('second-instance', () => { if (mainWindow) { if (mainWindow.isMinimized()) mainWindow.restore(); mainWindow.focus(); } });
  app.whenReady().then(async () => {
    session.defaultSession.setPermissionCheckHandler((contents, permission, origin) => !!contents && sameOrigin(origin, serverUrl) && ['media', 'clipboard-sanitized-write'].includes(permission));
    session.defaultSession.setPermissionRequestHandler(async (contents, permission, callback, details) => {
      if (!sameOrigin(details.requestingUrl || contents.getURL(), serverUrl)) return callback(false);
      if (permission === 'clipboard-sanitized-write') return callback(true);
      if (permission !== 'media' || details.mediaTypes?.some(type => type !== 'video')) return callback(false);
      const { response } = await dialog.showMessageBox(BrowserWindow.fromWebContents(contents), { type: 'question', message: 'Allow the restaurant app to use your camera?', buttons: ['Deny', 'Allow'], defaultId: 0 });
      callback(response === 1);
    });
    mainWindow = new BrowserWindow({ show: !process.env.POS_SMOKE_TEST, width: 1360, height: 900, minWidth: 380, minHeight: 600, autoHideMenuBar: true, title: 'MKPOS restaurant', icon: appIcon, webPreferences: preferences });
    secureWindow(mainWindow);
    registerPrinting({ app, BrowserWindow, ipcMain: require('electron').ipcMain, dialog, shell, getMainWindow: () => mainWindow, serverUrl });
    mainWindow.webContents.on('did-fail-load', (_event, code, _description, url, isMainFrame) => {
      if (isMainFrame && code !== -3 && sameOrigin(url, serverUrl)) void showOffline();
    });
    Menu.setApplicationMenu(null);
    mainWindow.webContents.on('before-input-event', (event, input) => {
      if (input.type !== 'keyDown') return;
      if ((input.control || input.meta) && input.key.toLowerCase() === 'p') { event.preventDefault(); void printingAction('restaurantPrintCurrentDocument'); }
      else if (input.alt && input.key === 'ArrowLeft') { event.preventDefault(); void goBack(); }
      else if ((input.control || input.meta) && input.key.toLowerCase() === 'r') { event.preventDefault(); if (mainWindow.webContents.getURL() === offlineUrl) void openServer(); else mainWindow.webContents.reload(); }
    });
    await openServer();
  });
  app.on('window-all-closed', () => app.quit());
}
