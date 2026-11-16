# Worker PHAR and the worker check

Read before touching `Phar\PharBuilder`, `Phar\WorkerCheck`, or anything that runs the worker on a Windows host.

- **Vendor files come from a `composer install --no-dev --no-scripts` staging copy**
  (`Phar\PharBuilder`), never the app's `vendor/`. A relative `path` repository has to be made
  absolute in **both** the staged `composer.json` and the staged `composer.lock`, since the install
  reads the lock's `dist.url`.
- **The ini block is checked by running it** (`Phar\WorkerCheck`): phpmicro ignores a malformed
  block silently and then writes its bytes to stdout. The handshake check (protocol v1 `ready`) is
  the app-agnostic proof that a payload boots. Both need a host of the target's OS and architecture
  (Linux, a Mac, or Windows x86_64; an x86_64 macOS worker also on an arm64 Mac with Rosetta 2, which
  `WorkerCheck::decide()` asks about through an injected probe, `arch -x86_64 /usr/bin/true`);
  elsewhere they are skipped with a note, never faked. On macOS the
  handshake runs on the **signed stub inside the finished bundle** (`handshakeAt`), under the
  hardened runtime with the entitlements it was given: that is the test any entitlement must fail
  without before it is kept (grafida/grafida#88).
- **On a Windows host** (the worker check, CI): `proc_open()` pipes are not selectable, so the
  handshake polls a stdout file; `composer` runs through a command string (CreateProcess runs no
  `.bat` without its extension, and a batch file found on PATH under a quoted name gets the wrong
  `%~dp0`); a path package is installed as a **copy** (the lock's `transport-options`, which an
  install from a lock reads; composer's junction is a leaf to `RecursiveDirectoryIterator`, so the
  package went missing from the PHAR). The Linux fixture tarball was byte-identical before and after.
