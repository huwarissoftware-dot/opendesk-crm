# OpenDesk CRM — Client Applications — Build Notes

Both clients are thin wrappers around the OpenDesk CRM server. They store
only the server address locally.

## Android (`android/`)

No-Gradle build, same pattern as the workspace's earlier apps:

- Toolchain: JDK 17 (`~/workspace/tools/jdk17`) + Android SDK build-tools
  35.0.0 / platform android-35 (`~/workspace/tools/android-sdk`).
- `MainActivity.java` — WebView client: first-run server-address page
  (`assets/setup.html`), unreachable-server page (`assets/error.html`) with
  Retry / Change Server, bridge exposed only to those local pages, file
  chooser for `<input type=file>`, DownloadManager downloads, same-host
  links stay in-app / external links open in the browser, options menu with
  Change Server + Reload.
- Package `org.opendesk.crm`, label "OpenDesk CRM", version 1.0.0 (code 1),
  minSdk 24, targetSdk 34, `usesCleartextTraffic` on (office LAN servers
  are commonly plain HTTP).
- Build: `./build-apk.sh` → `OpenDesk-CRM.apk`, release-signed with the NEW
  keystore in `android/release/` (passwords only in its 0600
  `keystore.properties` — never committed anywhere else, never printed).

## Windows (`windows/`)

- Electron 33.4.11 (win32-x64) runtime; app code in `main.js` +
  `preload.js` + `pages/` (same setup/error pages as Android; the preload
  bridge is exposed only on `file:` pages).
- Server address persists in `%APPDATA%\OpenDesk CRM\server.json`
  (Electron userData). Menu: File → Change Server Address... / Reload.
- `did-fail-load` → friendly error page (never blank); downloads get a
  Save dialog; same-origin popups allowed, external links go to the
  default browser.
- Assembly: unzip the Electron win32 zip to `dist/win-unpacked`, rename
  `electron.exe` → `OpenDesk CRM.exe` (plain ASCII name — non-ASCII names
  break makensis), copy the app into `resources/app/`, stamp icon/version
  with rcedit (wine), then compile `installer.nsi` with the POSIX
  makensis from the electron-builder cache
  (`NSISDIR=~/.cache/electron-builder/nsis/nsis-3.0.4.1` must be set).
  The installer is file-based (plain file copy, uninstaller generated on
  the user's machine at install time) and installs per-user to
  `%LOCALAPPDATA%\Programs\OpenDesk CRM`.

## Verification done on this Linux VM

- APK: `aapt dump badging` (package/label/version) and
  `apksigner verify` (v2 + v3 true, 1 signer).
- EXE: `node --check` on main/preload; rcedit version strings confirmed
  present in the exe; `7z l` on the Setup EXE lists all 83 payload files.
- NOT tested here: actual installation / run on a physical Android phone
  or a Windows PC (the user's smoke test), and live behaviour against a
  running CRM server.
