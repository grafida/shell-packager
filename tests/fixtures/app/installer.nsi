; The fixture app's installer (grafida/grafida#91): what an app's own NSIS script looks like when
; shell-packager builds it. Everything app-specific comes from the defines the packager passes
; (src/Windows/WindowsInstaller.php documents them); PUBLISHER and SHORTCUTS come from
; windows.installer.defines in shell-package.json.
;
; Per user (no elevation), into %LOCALAPPDATA%\Programs\<APPNAME>; /S installs silently, and /D=
; chooses the folder, as for any NSIS installer.

Unicode true
!include "MUI2.nsh"
!include "LogicLib.nsh"

; Signed builds: the uninstaller is written inside the installer, so it is signed as makensis
; produces it, or never.
!ifdef SHELL_PACKAGER_SIGN
  !uninstfinalize '${SHELL_PACKAGER_SIGN}' = 0
!endif

!define UNINSTKEY "Software\Microsoft\Windows\CurrentVersion\Uninstall\${APPID}"

Name "${APPNAME}"
OutFile "${OUTFILE}"
RequestExecutionLevel user
InstallDir "$LOCALAPPDATA\Programs\${APPNAME}"
SetCompressor /SOLID lzma
VIProductVersion "${VIVERSION}"
VIAddVersionKey "ProductName" "${APPNAME}"
VIAddVersionKey "FileDescription" "${APPNAME} installer"
VIAddVersionKey "CompanyName" "${PUBLISHER}"
VIAddVersionKey "FileVersion" "${APPVERSION}"
VIAddVersionKey "ProductVersion" "${APPVERSION}"
VIAddVersionKey "LegalCopyright" "${PUBLISHER}"

!ifdef ICONFILE
  !define MUI_ICON "${ICONFILE}"
  !define MUI_UNICON "${ICONFILE}"
!endif

!insertmacro MUI_PAGE_DIRECTORY
!insertmacro MUI_PAGE_INSTFILES
!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES
!insertmacro MUI_LANGUAGE "English"

; The WebView2 Evergreen runtime, per machine or per user. No bootstrapper: the shell itself says
; so if it is missing.
Function .onInit
  ReadRegStr $0 HKLM "SOFTWARE\WOW6432Node\Microsoft\EdgeUpdate\Clients\{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}" "pv"
  ${If} $0 == ""
    ReadRegStr $0 HKCU "Software\Microsoft\EdgeUpdate\Clients\{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}" "pv"
  ${EndIf}
  ${If} $0 == ""
  ${OrIf} $0 == "0.0.0.0"
    IfSilent +2
    MessageBox MB_OK|MB_ICONEXCLAMATION "${APPNAME} needs the Microsoft Edge WebView2 Runtime, which is not installed."
  ${EndIf}
FunctionEnd

Section "Install"
  SetOutPath "$INSTDIR"
  File "${SRCDIR}\${APPEXE}"
  File "${SRCDIR}\${WORKEREXE}"
  File "${SRCDIR}\${WORKERPHAR}"
  File "${SRCDIR}\shell.json"
  File "${SRCDIR}\LICENSE.txt"
!ifdef ICONFILE
  File "${ICONFILE}"
!endif
  WriteUninstaller "$INSTDIR\uninstall.exe"

!if "${SHORTCUTS}" == "yes"
  CreateShortCut "$SMPROGRAMS\${APPNAME}.lnk" "$INSTDIR\${APPEXE}"
!endif

  WriteRegStr HKCU "${UNINSTKEY}" "DisplayName" "${APPNAME}"
  WriteRegStr HKCU "${UNINSTKEY}" "DisplayVersion" "${APPVERSION}"
  WriteRegStr HKCU "${UNINSTKEY}" "Publisher" "${PUBLISHER}"
  WriteRegStr HKCU "${UNINSTKEY}" "DisplayIcon" "$INSTDIR\${APPEXE}"
  WriteRegStr HKCU "${UNINSTKEY}" "UninstallString" '"$INSTDIR\uninstall.exe"'
  WriteRegStr HKCU "${UNINSTKEY}" "QuietUninstallString" '"$INSTDIR\uninstall.exe" /S'
  WriteRegDWORD HKCU "${UNINSTKEY}" "NoModify" 1
  WriteRegDWORD HKCU "${UNINSTKEY}" "NoRepair" 1
SectionEnd

Section "Uninstall"
  Delete "$INSTDIR\${APPEXE}"
  Delete "$INSTDIR\${WORKEREXE}"
  Delete "$INSTDIR\${WORKERPHAR}"
  Delete "$INSTDIR\shell.json"
  Delete "$INSTDIR\LICENSE.txt"
  Delete "$INSTDIR\${APPNAME}.ico"
  RMDir /r "$INSTDIR\logs"
  Delete "$INSTDIR\uninstall.exe"
  RMDir "$INSTDIR"
!if "${SHORTCUTS}" == "yes"
  Delete "$SMPROGRAMS\${APPNAME}.lnk"
!endif
  DeleteRegKey HKCU "${UNINSTKEY}"
SectionEnd
