#!/usr/bin/env bash
#
# End-to-end test of a Windows installer with the real, pinned runtimes (grafida/grafida#91):
# package tests/fixtures/app as an NSIS installer, install it silently (per user), check the
# installed tree and what Windows reads from it (the version resources, the icon, the Authenticode
# signatures when it was signed), launch it, and uninstall it. The fixture's page reports its
# origin to the worker and quits the shell, so a clean exit with the report in the log means: the
# pins verified, the shell found its manifest and its worker beside itself, the stub loaded its
# sibling .phar with the extra-INI block applied after its resources were rewritten (and its
# signature appended), and WebView2 reached the worker through boson://app.
#
# Usage (Git Bash on Windows, with a desktop session):
#   scripts/e2e-windows.sh                    build the installer (PHP 8.4 and makensis on PATH), then test it
#   INSTALLER=Setup.exe scripts/e2e-windows.sh  test one built elsewhere (the Mac, the Linux box)
#
# Signing: as the packager's (WINDOWS_SIGN_* in the environment). SIGNED=1 requires every installed
# executable, the uninstaller and the installer to carry a signature.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK="$(mktemp -d)"
# No space in it: NSIS takes /D= unquoted, and a path with a space reaches it quoted (and ignored).
INSTDIR="$WORK/installed/PackagerTest"
trap 'rm -rf "$WORK"' EXIT

ps1() { powershell.exe -NoProfile -NonInteractive -Command "$1" | tr -d '\r'; }
win() { cygpath -w "$1"; }
fail() { echo "FAIL: $*" >&2; log="$INSTDIR/logs/shell.log"; [ -f "$log" ] && sed 's/^/  log: /' "$log" >&2; exit 1; }

setup="${INSTALLER:-}"
if [ -z "$setup" ]; then
    setup="$WORK/Setup.exe"
    php -d phar.readonly=0 "$ROOT/bin/shell-packager" windows-installer \
        --config="$ROOT/tests/fixtures/app/shell-package.json" --app-version=1.2.3-beta4 --output="$setup"
fi
[ -f "$setup" ] || fail "no installer at $setup"

# The installer's own version resource, as Windows reads it.
desc="$(ps1 "(Get-Item '$(win "$setup")').VersionInfo.FileDescription")"
[ "$desc" = "Packager Test installer" ] || fail "the installer's FileDescription is '$desc'"

# Silent, per user, into a folder of ours (NSIS: /D= is last and unquoted). ⚠️ MSYS rewrites an
# argument that looks like a path (/S becomes S:/), which leaves the installer waiting on its UI;
# //S reaches it as /S.
start=$(date +%s)
"$setup" //S "/D=$(win "$INSTDIR")" || fail "the installer exited with $?"
for f in "Packager Test.exe" test-worker.exe test-worker.phar shell.json LICENSE.txt "Packager Test.ico" uninstall.exe; do
    [ -f "$INSTDIR/$f" ] || fail "the installer did not write $f"
done
key='HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall\test.packager.app'
[ "$(ps1 "(Get-ItemProperty '$key').DisplayVersion")" = "1.2.3-beta4" ] || fail "no Add/Remove Programs entry"

# What Task Manager and Explorer show: FileDescription, versions, the icon.
for exe in "Packager Test.exe:Packager Test" "test-worker.exe:Packager Test worker"; do
    file="${exe%%:*}" want="${exe#*:}"
    got="$(ps1 "\$v = (Get-Item '$(win "$INSTDIR/$file")').VersionInfo; '{0}|{1}|{2}|{3}' -f \$v.FileDescription, \$v.ProductName, \$v.FileVersion, \$v.FileVersionRaw")"
    [ "$got" = "$want|Packager Test|1.2.3-beta4|1.2.3.13" ] || fail "$file's version resource reads '$got'"
    icon="$(ps1 "Add-Type -AssemblyName System.Drawing; [System.Drawing.Icon]::ExtractAssociatedIcon('$(win "$INSTDIR/$file")').ToBitmap().GetPixel(16, 16).Name")"
    [ "$icon" = "ffffffff" ] || fail "$file's icon is not the fixture's (centre pixel $icon)"
done

# Authenticode, as Windows sees it: a throwaway certificate is untrusted, but it must be there,
# over a hash that matches (Status is then UnknownError/NotTrusted, never HashMismatch/NotSigned).
for f in "$INSTDIR/Packager Test.exe" "$INSTDIR/test-worker.exe" "$INSTDIR/uninstall.exe" "$setup"; do
    status="$(ps1 "\$s = Get-AuthenticodeSignature '$(win "$f")'; '{0}|{1}' -f \$s.Status, \$s.SignerCertificate.Subject")"
    echo "  $(basename "$f"): $status"
    case "$status" in
        NotSigned*) [ -z "${SIGNED:-}" ] || fail "$(basename "$f") is not signed" ;;
        HashMismatch*|Incompatible*) fail "$(basename "$f"): $status" ;;
    esac
done

# Launch it as a user would (from its folder, through the shell's own exit).
code=0
(cd / && timeout 120 "$INSTDIR/Packager Test.exe") || code=$?
took=$(( $(date +%s) - start ))
[ "$code" = 0 ] || fail "the app exited with $code"
log="$INSTDIR/logs/shell.log"
[ -f "$log" ] || fail "no log at $log"
grep -q 'fixture: hello from the phar, memory_limit=321M' "$log" || fail "the worker did not run from its PHAR with the ini block"
grep -q 'fixture-report: origin=boson://app' "$log" || fail "the page never reported"
if tasklist //FI "IMAGENAME eq test-worker.exe" 2>/dev/null | grep -qi test-worker; then fail "a worker outlived the shell"; fi
grep -E 'fixture|on WebView2' "$log" | cut -c26- | sed 's/^/  /'

# Uninstall, silently: everything gone, the registry entry too.
"$INSTDIR/uninstall.exe" //S || fail "the uninstaller exited with $?"
for _ in $(seq 1 50); do [ -e "$INSTDIR" ] || break; sleep 0.2; done   # NSIS uninstalls from a copy in TEMP
[ ! -e "$INSTDIR" ] || fail "the uninstaller left $(find "$INSTDIR" -mindepth 1 -printf '%P ')"
[ "$(ps1 "Test-Path '$key'")" = False ] || fail "the uninstaller left the Add/Remove Programs entry"

echo "ok: installed, launched, reported and uninstalled in ${took}s"
