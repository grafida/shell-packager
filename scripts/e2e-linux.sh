#!/usr/bin/env bash
#
# End-to-end test of a Linux tarball with the real, pinned runtimes (grafida/grafida#86): package
# tests/fixtures/app, extract it, run its install.sh, and start its launcher. The fixture's page
# reports its origin to the worker and quits the shell, so a clean exit with the report in the log
# means: the pins verified, the launcher's library check passed, the shell found its manifest and
# worker, the SFX loaded the PHAR from its sibling file with the extra-INI block applied, and the
# webview reached the worker through shell://app.
#
# Usage: scripts/e2e-linux.sh [ARCH]   (default: this machine's)
# Needs WebKitGTK 4.1 + GTK 3 installed, a display or xvfb-run, tar, and network access the first
# time (downloads are cached by SHA-256 in SHELL_PACKAGER_CACHE).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ARCH="${1:-$(uname -m)}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

fail() { echo "FAIL: $*" >&2; log="$WORK/x/test-app/logs/shell.log"; [ -f "$log" ] && sed 's/^/  log: /' "$log" >&2; exit 1; }

SOURCE_DATE_EPOCH=1700000000 "$ROOT/bin/shell-packager" linux-tarball \
    --config="$ROOT/tests/fixtures/app/shell-package.json" --arch="$ARCH" --app-version=e2e \
    --output="$WORK/test-app.tar.gz"

mkdir -p "$WORK/x" "$WORK/home"
tar -xzf "$WORK/test-app.tar.gz" -C "$WORK/x"
export HOME="$WORK/home" XDG_DATA_HOME="$WORK/home/data" XDG_CONFIG_HOME="$WORK/home/config" \
       XDG_CACHE_HOME="$WORK/home/cache" XDG_STATE_HOME="$WORK/home/state"

sh "$WORK/x/test-app/install.sh"
grep -q "^Exec=\"$WORK/x/test-app/test-app\"$" "$XDG_DATA_HOME/applications/test.packager.app.desktop" \
    || fail "install.sh did not point the desktop entry at the launcher"

run=("$WORK/x/test-app/test-app")
if [ -z "${DISPLAY:-}${WAYLAND_DISPLAY:-}" ]; then
    command -v xvfb-run >/dev/null || fail "no display and no xvfb-run"
    run=(xvfb-run -a -s "-screen 0 1280x1024x24" "${run[@]}")
    command -v dbus-run-session >/dev/null && run=(dbus-run-session -- "${run[@]}")
fi

code=0
timeout 120 "${run[@]}" > "$WORK/stdout" 2> "$WORK/stderr" || code=$?
[ "$code" = 0 ] || { sed 's/^/  stderr: /' "$WORK/stderr" >&2; fail "the app exited with $code"; }

log="$WORK/x/test-app/logs/shell.log"
grep -q 'fixture: hello from the phar, memory_limit=321M' "$log" || fail "the worker did not run from its PHAR with the ini block"
grep -q 'fixture-report: origin=shell://app' "$log" || fail "the page never reported"
if pgrep -f "$WORK/x/test-app/test-worker" >/dev/null; then fail "a worker outlived the shell"; fi

echo "ok: the packaged fixture ran end to end on $ARCH"
grep -E 'fixture|on WebKitGTK' "$log" | cut -c26- | sed 's/^/  /'
