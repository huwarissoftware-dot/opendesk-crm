// OpenDesk CRM - Windows client preload.
// The bridge is exposed ONLY on the client's own local pages (file://),
// never on pages served by the CRM server.
const { contextBridge, ipcRenderer } = require('electron');

if (window.location.protocol === 'file:') {
  contextBridge.exposeInMainWorld('clientBridge', {
    saveServer: (url) => ipcRenderer.send('client:save-server', url),
    getServer: () => ipcRenderer.sendSync('client:get-server'),
    retry: () => ipcRenderer.send('client:retry'),
    changeServer: () => ipcRenderer.send('client:change-server')
  });
}
