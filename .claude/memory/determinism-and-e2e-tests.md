# Determinism and end-to-end tests

Read before changing archive / tarball creation, the fixture app, the `scripts/e2e-*.sh` scripts, or any Linux packaging output that must stay byte-identical.

- **Determinism:** sorted PHAR entries, `seld/phar-utils` timestamps, `SOURCE_DATE_EPOCH`, and our
  own `Archive\TarWriter` (ustar, uid/gid 0, fixed mtime, streamed gzip). PharData cannot set modes
  and times, and BSD tar on macOS has no `--sort`/`--mtime`, while Linux packages are also built on
  macOS.
- **The fixture app (`tests/fixtures/app`) carries a minimal, independent protocol v1 worker** (its
  `bin/worker.php`). Its `shell-package.json` pins the **real** shell by version (`shell.version` plus the committed
  `shell-package.lock`; `shell-packager update` after a bump) and the real SFX releases by URL and hash.
  `scripts/e2e-linux.sh` packages it, installs it and runs it headless;
  `scripts/e2e-macos.sh` builds its DMG, mounts it, copies the app out and launches it **through
  LaunchServices** (`open`), which is the only thing that reads `Info.plist` (`--no-style` on a runner without
  Finder automation; `ARCH=x86_64` for the Intel bundle). `scripts/e2e-windows.sh` builds its installer (or takes one, `INSTALLER=`, as the
  test guests do), installs it silently, reads back the resources and signatures as Windows does,
  launches it and uninstalls it. ⚠️ In Git Bash an NSIS switch
  is `//S` (MSYS rewrites `/S` to `S:/`), and `/D=` takes a path with no space in it. Bump those pins
  when a new shell or SFX should be covered. ⚠️ **CI runs none of these end-to-end scripts** (nor installs any package): they are run by hand on the maintainer's machines and VMs, which is cheaper and closer to real systems; CI keeps the lint and the unit suite. The macOS tests need `SHELL_PACKAGER_TEST_MACOS_SHELL`
  and `_SFX`, the Windows ones `SHELL_PACKAGER_TEST_WINDOWS_SHELL` and `_SFX` (CI fetches the pins
  with `fetch --platform=…`).
- **Every Linux change is checked byte for byte**: with a fixed `SOURCE_DATE_EPOCH`, the fixture's
  tarball before and after must have the same SHA-256 (done for the 0.2.0 refactor, on the 0.1.0
  pins, before they were bumped).
