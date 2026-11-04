; ============================================================================
; OpenDesk CRM - Windows Client Setup (NSIS, FILE-BASED)
;
; The classic file-based NSIS flow is used: the installer embeds the
; win-unpacked app tree as individual files (File /r) and NSIS itself
; writes them out - plain file copy, no archive extraction plugins.
; WriteUninstaller generates the uninstaller on the user's own machine at
; install time.
;
; Build (Linux, native makensis from the electron-builder cache):
;   makensis installer.nsi
; ============================================================================

Unicode True

!ifndef NSIS_ROOT
  !define NSIS_ROOT "/home/hatch/.cache/electron-builder/nsis/nsis-3.0.4.1"
!endif
!addincludedir "${NSIS_ROOT}/Include"

!include "MUI2.nsh"
!include "FileFunc.nsh"

!define APP_NAME "OpenDesk CRM"
; NOTE: all file names stay plain ASCII - the POSIX makensis build can
; fail when its recursive File scan meets a non-ASCII file name.
!define APP_EXE "OpenDesk CRM.exe"
!define UNINST_EXE "Uninstall OpenDesk CRM.exe"
!define PUBLISHER "OpenDesk"
!define VERSION "1.0.0"
!define UNINST_KEY "Software\Microsoft\Windows\CurrentVersion\Uninstall\OpenDeskCRM"
!define PAYLOAD_DIR "dist/win-unpacked"

Name "${APP_NAME}"
Caption "${APP_NAME} Setup"
OutFile "dist/OpenDesk-CRM-Setup.exe"
InstallDir "$LOCALAPPDATA\Programs\${APP_NAME}"
InstallDirRegKey HKCU "Software\OpenDesk\CRM" "InstallDir"
RequestExecutionLevel user
SetCompressor lzma
Icon "icon.ico"
UninstallIcon "icon.ico"

VIProductVersion "1.0.0.0"
VIAddVersionKey /LANG=1033 "ProductName" "${APP_NAME}"
VIAddVersionKey /LANG=1033 "CompanyName" "${PUBLISHER}"
VIAddVersionKey /LANG=1033 "FileDescription" "OpenDesk CRM - Windows client Setup"
VIAddVersionKey /LANG=1033 "LegalCopyright" "Copyright OpenDesk CRM - All Rights Reserved"
VIAddVersionKey /LANG=1033 "FileVersion" "${VERSION}"
VIAddVersionKey /LANG=1033 "ProductVersion" "${VERSION}"

!define MUI_ICON "icon.ico"
!define MUI_UNICON "icon.ico"
!define MUI_ABORTWARNING

!insertmacro MUI_PAGE_WELCOME
!insertmacro MUI_PAGE_LICENSE "LICENSE.txt"
!insertmacro MUI_PAGE_DIRECTORY
!insertmacro MUI_PAGE_INSTFILES
!insertmacro MUI_PAGE_FINISH

!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES

!insertmacro MUI_LANGUAGE "English"

Section "Install" SEC01
  ; --- install the app payload: plain file copy of the whole win-unpacked
  ;     tree (NSIS File /r recreates the directory structure under $INSTDIR).
  SetOutPath "$INSTDIR"
  File /r "${PAYLOAD_DIR}\*.*"

  ; --- shortcuts (desktop + Start Menu) ---
  CreateShortcut "$DESKTOP\${APP_NAME}.lnk" "$INSTDIR\${APP_EXE}" "" "$INSTDIR\${APP_EXE}" 0
  CreateDirectory "$SMPROGRAMS\${APP_NAME}"
  CreateShortcut "$SMPROGRAMS\${APP_NAME}\${APP_NAME}.lnk" "$INSTDIR\${APP_EXE}" "" "$INSTDIR\${APP_EXE}" 0
  CreateShortcut "$SMPROGRAMS\${APP_NAME}\Uninstall ${APP_NAME}.lnk" "$INSTDIR\${UNINST_EXE}" "" "$INSTDIR\${UNINST_EXE}" 0

  ; --- uninstaller, generated here on the user's machine ---
  WriteUninstaller "$INSTDIR\${UNINST_EXE}"

  ; --- Add/Remove Programs registration ---
  WriteRegStr HKCU "Software\OpenDesk\CRM" "InstallDir" "$INSTDIR"
  WriteRegStr HKCU "${UNINST_KEY}" "DisplayName" "${APP_NAME}"
  WriteRegStr HKCU "${UNINST_KEY}" "DisplayVersion" "${VERSION}"
  WriteRegStr HKCU "${UNINST_KEY}" "Publisher" "${PUBLISHER}"
  WriteRegStr HKCU "${UNINST_KEY}" "DisplayIcon" "$INSTDIR\${APP_EXE}"
  WriteRegStr HKCU "${UNINST_KEY}" "InstallLocation" "$INSTDIR"
  WriteRegStr HKCU "${UNINST_KEY}" "UninstallString" '"$INSTDIR\${UNINST_EXE}"'
  WriteRegStr HKCU "${UNINST_KEY}" "QuietUninstallString" '"$INSTDIR\${UNINST_EXE}" /S'
  WriteRegDWORD HKCU "${UNINST_KEY}" "NoModify" 1
  WriteRegDWORD HKCU "${UNINST_KEY}" "NoRepair" 1
  ${GetSize} "$INSTDIR" "/S=0K" $0 $1 $2
  IntFmt $0 "0x%08X" $0
  WriteRegDWORD HKCU "${UNINST_KEY}" "EstimatedSize" "$0"
SectionEnd

Section "Uninstall"
  Delete "$DESKTOP\${APP_NAME}.lnk"
  Delete "$SMPROGRAMS\${APP_NAME}\${APP_NAME}.lnk"
  Delete "$SMPROGRAMS\${APP_NAME}\Uninstall ${APP_NAME}.lnk"
  RMDir "$SMPROGRAMS\${APP_NAME}"
  DeleteRegKey HKCU "${UNINST_KEY}"
  DeleteRegKey HKCU "Software\OpenDesk\CRM"
  RMDir /r "$INSTDIR"
SectionEnd
