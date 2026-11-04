// OpenDesk CRM - Windows client (Electron main process)
//
// A thin client for the OpenDesk CRM server application. All data lives on
// the server; this app only stores the server address entered on first
// launch (File > Change Server Address... updates it later).
const { app, BrowserWindow, Menu, ipcMain, shell, dialog } = require('electron');
const fs = require('fs');
const path = require('path');

app.setName('OpenDesk CRM');
// Match the app id so Windows groups the taskbar button under this app.
if (process.platform === 'win32') {
  app.setAppUserModelId('org.opendesk.crm');
}

let mainWindow = null;

// ---------------------------------------------------------------------------
// Server address storage (the ONLY thing this client persists)
// ---------------------------------------------------------------------------

function configFile() {
  return path.join(app.getPath('userData'), 'server.json');
}

function getServer() {
  try {
    const data = JSON.parse(fs.readFileSync(configFile(), 'utf8'));
    return typeof data.url === 'string' ? data.url : '';
  } catch (e) {
    return '';
  }
}

function setServer(url) {
  try {
    fs.mkdirSync(path.dirname(configFile()), { recursive: true });
    fs.writeFileSync(configFile(), JSON.stringify({ url: url }), 'utf8');
  } catch (e) {
    dialog.showErrorBox('OpenDesk CRM', 'Could not save the server address: ' + e.message);
  }
}

function normalizeServerUrl(raw) {
  let v = String(raw || '').trim();
  if (!v) return '';
  if (!/^https?:\/\//i.test(v)) v = 'http://' + v;
  return v;
}

// ---------------------------------------------------------------------------
// Page loading
// ---------------------------------------------------------------------------

function pagesDir() {
  return path.join(__dirname, 'pages');
}

function isLocalPage() {
  if (!mainWindow) return false;
  const url = mainWindow.webContents.getURL();
  return url.startsWith('file:');
}

function loadSetup() {
  mainWindow.loadFile(path.join(pagesDir(), 'setup.html'));
}

function loadError() {
  mainWindow.loadFile(path.join(pagesDir(), 'error.html'), {
    query: { server: getServer() }
  });
}

function loadServer() {
  const server = getServer();
  if (!server) {
    loadSetup();
    return;
  }
  mainWindow.loadURL(server);
}

function reloadCurrent() {
  const server = getServer();
  if (!server || isLocalPage()) {
    loadServer();
  } else {
    mainWindow.webContents.reload();
  }
}

// ---------------------------------------------------------------------------
// IPC from the local setup / error pages
// ---------------------------------------------------------------------------

ipcMain.on('client:save-server', (event, url) => {
  const normalized = normalizeServerUrl(url);
  if (normalized) {
    setServer(normalized);
    loadServer();
  }
});
ipcMain.on('client:get-server', (event) => {
  event.returnValue = getServer();
});
ipcMain.on('client:retry', () => loadServer());
ipcMain.on('client:change-server', () => loadSetup());

// ---------------------------------------------------------------------------
// Window
// ---------------------------------------------------------------------------

function createWindow() {
  mainWindow = new BrowserWindow({
    width: 1280,
    height: 820,
    minWidth: 360,
    minHeight: 560,
    title: 'OpenDesk CRM',
    icon: path.join(__dirname, 'icon.png'),
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      nodeIntegration: false,
      contextIsolation: true,
      sandbox: false
    }
  });

  const menu = Menu.buildFromTemplate([
    {
      label: 'File',
      submenu: [
        { label: 'Change Server Address...', click: () => loadSetup() },
        { label: 'Reload', accelerator: 'CmdOrCtrl+R', click: () => reloadCurrent() },
        { type: 'separator' },
        { role: 'quit' }
      ]
    },
    {
      label: 'Help',
      submenu: [
        {
          label: 'About OpenDesk CRM',
          click: () => {
            dialog.showMessageBox(mainWindow, {
              type: 'info',
              title: 'About OpenDesk CRM',
              message: 'OpenDesk CRM - Windows Client',
              detail: 'Version 1.0.0\n\nThis app connects to your OpenDesk CRM server. '
                + 'All data is stored on the server.\n\nCurrent server: '
                + (getServer() || '(not set)')
            });
          }
        }
      ]
    }
  ]);
  Menu.setApplicationMenu(menu);

  // If the CRM server cannot be reached, show the friendly error page
  // (with Retry / Change Server Address) instead of a blank screen.
  mainWindow.webContents.on('did-fail-load', (event, errorCode, errorDescription, validatedURL, isMainFrame) => {
    if (!isMainFrame) return;
    if (errorCode === -3) return; // aborted (e.g. superseded navigation)
    if (validatedURL && validatedURL.startsWith('file:')) return;
    loadError();
  });

  // Same-origin popups (e.g. print views) open in a normal window;
  // anything else opens in the user's default browser.
  mainWindow.webContents.setWindowOpenHandler(({ url }) => {
    const server = getServer();
    let sameOrigin = false;
    try {
      sameOrigin = !!server && new URL(url).origin === new URL(server).origin;
    } catch (e) {
      sameOrigin = false;
    }
    if (sameOrigin) {
      return { action: 'allow' };
    }
    if (/^https?:\/\//i.test(url)) {
      shell.openExternal(url);
    }
    return { action: 'deny' };
  });

  // Downloads: ask where to save, with the CRM's file name pre-filled.
  mainWindow.webContents.session.on('will-download', (event, item) => {
    const chosen = dialog.showSaveDialogSync(mainWindow, {
      defaultPath: item.getFilename()
    });
    if (chosen) {
      item.setSavePath(chosen);
    } else {
      item.cancel();
    }
  });

  if (getServer()) {
    loadServer();
  } else {
    loadSetup();
  }
}

app.whenReady().then(createWindow);
app.on('window-all-closed', () => app.quit());
app.on('activate', () => {
  if (BrowserWindow.getAllWindows().length === 0) createWindow();
});
