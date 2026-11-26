const { contextBridge, ipcRenderer } = require('electron');
if (location.origin === 'https://restaurant.mkposmyanmar.com') {
  const api = { platform: 'windows' };
  for (const method of ['getConfig', 'saveConfig', 'listDevices', 'listPaired', 'openBluetoothSettings', 'openSystemSettings', 'checkConnection', 'print']) {
    api[method] = payload => ipcRenderer.invoke('printer:' + method, payload);
  }
  contextBridge.exposeInMainWorld('RestaurantPrinter', Object.freeze(api));
}
