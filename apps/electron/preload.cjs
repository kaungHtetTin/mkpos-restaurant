const { contextBridge, ipcRenderer } = require('electron');
if (location.protocol === 'file:') {
  contextBridge.exposeInMainWorld('Desktop', {
    settings: () => ipcRenderer.invoke('server:settings'),
    connect: url => ipcRenderer.invoke('server:connect', url),
    local: () => ipcRenderer.invoke('server:local'),
  });
}
