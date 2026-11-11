#!/usr/bin/env bash
#
# End-to-end test of a macOS disk image with the real, pinned runtimes (grafida/grafida#88): package
# tests/fixtures/app as a signed (ad hoc) bundle in a DMG, verify and mount the image, copy the app
# out as a user would, and launch it through LaunchServices (`open`), which reads Info.plist: a
# broken CFBundleExecutable, LSMinimumSystemVersion or plist would fail here and nowhere else. The
# fixture's page reports its origin to the worker and quits the shell, so a clean exit with the
# report in the log means: the pins verified, the shell found its manifest in Contents/Resources
# and its worker beside itself, the signed stub loaded its PHAR from ../Resources under the hardened
# runtime with the extra-INI block applied, and the webview reached the worker through boson://app.
#
# Usage: scripts/e2e-macos.sh        STYLE=1 also runs the Finder layout (needs Finder's Automation
#                                    permission; CI has neither, so it builds a plain image)
#                                    ARCH=x86_64 packages the Intel bundle (default aarch64)
# Needs a Mac with a desktop session that runs ARCH's code (x86_64: an Intel Mac, or Apple Silicon
# with Rosetta 2; grafida/grafida#92), PHP 8.4, and network access the first time.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK="$(mktemp -d)"
MOUNT=
trap '[ -n "$MOUNT" ] && hdiutil detach "$MOUNT" -force >/dev/null 2>&1; rm -rf "$WORK"' EXIT

APP="$WORK/Applications/Packager Test.app"
fail() { echo "FAIL: $*" >&2; log="$APP/Contents/Resources/logs/shell.log"; [ -f "$log" ] && sed 's/^/  log: /' "$log" >&2; exit 1; }

style=(--no-style)
[ -n "${STYLE:-}" ] && style=()
"$ROOT/bin/shell-packager" macos-dmg --config="$ROOT/tests/fixtures/app/shell-package.json" \
    --arch="${ARCH:-aarch64}" --app-version=1.0.0 --output="$WORK/test.dmg" "${style[@]}"

hdiutil verify "$WORK/test.dmg" >/dev/null || fail "the image does not verify"
MOUNT="$(hdiutil attach -readonly -noverify -noautoopen -nobrowse -mountrandom "$WORK" "$WORK/test.dmg" \
    | awk -F'\t' 'NF > 1 && $NF ~ /^\// && $NF !~ /^\/dev\// { print $NF }' | tail -1)"
[ -d "$MOUNT/Packager Test.app" ] || fail "no app in the image (mounted at '$MOUNT')"
[ "$(readlink "$MOUNT/Applications")" = /Applications ] || fail "no /Applications link in the image"
mkdir -p "$WORK/Applications"
ditto "$MOUNT/Packager Test.app" "$APP"
hdiutil detach "$MOUNT" >/dev/null && MOUNT=

codesign --verify --strict --verbose=2 "$APP" 2>&1 | sed 's/^/  /'
plutil -lint "$APP/Contents/Info.plist" >/dev/null || fail "Info.plist does not lint"
exe="$(/usr/libexec/PlistBuddy -c 'Print :CFBundleExecutable' "$APP/Contents/Info.plist")"
[ -x "$APP/Contents/MacOS/$exe" ] || fail "CFBundleExecutable '$exe' is not in Contents/MacOS"
want=$([ "${ARCH:-aarch64}" = x86_64 ] && echo x86_64 || echo arm64)
for bin in "$APP/Contents/MacOS/"*; do
    [ "$(lipo -archs "$bin")" = "$want" ] || fail "$bin is $(lipo -archs "$bin"), not $want"
done
[ "$(/usr/libexec/PlistBuddy -c 'Print :LSMinimumSystemVersion' "$APP/Contents/Info.plist")" = 12.0 ] \
    || fail "LSMinimumSystemVersion is not 12.0"

# Through LaunchServices, under a time limit (perl's alarm: macOS has no timeout(1)).
start=$(date +%s)
code=0
perl -e 'alarm shift; exec @ARGV' 120 open -n -W "$APP" || code=$?
took=$(( $(date +%s) - start ))
[ "$code" = 0 ] || { pkill -f "$APP/Contents/MacOS/" || true; fail "open exited with $code after ${took}s"; }

log="$APP/Contents/Resources/logs/shell.log"
[ -f "$log" ] || fail "no log at $log"
grep -q 'fixture: hello from the phar, memory_limit=321M' "$log" || fail "the worker did not run from its PHAR with the ini block"
grep -q 'fixture-report: origin=boson://app' "$log" || fail "the page never reported"
if pgrep -f "$APP/Contents/MacOS/test-worker" >/dev/null; then fail "a worker outlived the shell"; fi

echo "ok: the packaged fixture ran end to end through LaunchServices in ${took}s"
grep -E 'fixture|on WebKit' "$log" | cut -c26- | sed 's/^/  /'
