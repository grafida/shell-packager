# grafida/shell-packager — AI assistant orientation

Packaging tooling for apps on the Grafida desktop shell (umbrella issue: grafida/grafida#82):
building the worker PHAR, fetching and verifying the shell and phpmicro runtime binaries, and
producing signed per-platform artefacts. It is extracted from Grafida's `scripts/` and
`build/tasks/`, and the hard-won detail behind it lives in Grafida's `build/readme/01`–`04`.

## Rules that are not guessable from the code

- **App-agnostic.** No path, name, bundle id or certificate of Grafida's may be hard-coded; a second
  consumer (Akeeba Extract) must be able to use this verbatim.
- **Signing depends on the split binary.** The worker is a phpmicro `sibling-phar` SFX stub plus a
  separate `<name>.phar` (on macOS, `../Resources/<basename>.phar`). Signing a stub with the PHAR
  *appended* puts the signature past the PHAR, corrupts it, and the app dies at startup. Never
  re-combine them.
- Every downloaded binary is pinned and verified by SHA-256 before use.

- **Every name comes from the app's manifest** (`id`, `title`, `worker.binary`), never from the
  packaging config, so the shell and the package cannot disagree. The desktop entry is
  `<id>.desktop`, with `Icon` and `StartupWMClass` forced to the id, because the shell's GTK
  program name (its Wayland `app_id`) is the id.
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
- **macOS (`src/Macos/`) builds one bundle per architecture** (arm64, and x86_64 for Intel Macs,
  grafida/grafida#92; `--arch`, default aarch64). `MachO` reads, in PHP, each binary's CPU, its minimum
  macOS (`LC_BUILD_VERSION`) and where its image ends; a file longer than its last segment is an SFX
  with its payload still appended, which is refused (signing it corrupts the PHAR). Both real pins
  end exactly at their last segment; `MachOTest` checks that on CI. `LSMinimumSystemVersion` is
  `InfoPlist::MINIMUM_SYSTEM_VERSION` (12.0, the shell's floor) and every binary's minos must be
  within it. `Info.plist` keys the packager computes are an error in `macos.infoPlist`.
- **Signing identity and notary profile come from the environment** (`MACOS_SIGN_IDENTITY`,
  `MACOS_NOTARY_PROFILE`), never an app's repository. Without the identity everything is signed ad
  hoc, but **always with the hardened runtime**, so a local build behaves like the notarised one.
  Inside-out: the stub, the shell, then the bundle, whose signature re-signs the main executable and
  must therefore carry the shell's entitlements too. Each signature is verified and read back
  (`codesign -d --entitlements - --xml`): exactly the configured entitlements, hardened runtime set.
- **The DMG's Finder layout is best-effort and must never hang or touch another volume**:
  `osascript` runs under a time limit (an unanswered Automation prompt blocks forever), and only a
  volume mounted at exactly `/Volumes/<volumeName>` is styled. The volume icon goes on after the
  layout (Finder deletes one that is there when it opens the window). `hdiutil attach`/`detach`
  print a deprecation warning on newer macOS; `diskutil image` does not exist on 12, so they stay.
- **Windows (`src/Windows/`) is x86_64 only, and everything runs on any host** (the Mac builds and
  signs Windows packages; grafida/grafida#91). `PeFile` reads PE32+ and PE32 (NSIS writes x86
  installers): machine, subsystem, sections, directories, imports, the overlay and the certificate
  table. `ResourceWriter` rebuilds `.rsrc` **in place** and moves `.reloc` (nothing refers to it but
  its directory) behind it; any other layout is refused, not patched. The manifest resource is kept;
  icons and the version are replaced. A file with an overlay (an SFX with its PHAR appended) is never
  given resources or signed. `VersionInfo::decode()` exists so the tests read back what Windows
  reads; it was checked against PHP's own `rc.exe`-built block.
- **Windows signing is Jsign, from the environment** (`WINDOWS_SIGN_*`: Trusted Signing, or a PKCS#12
  keystore for tests), secrets as `env:` references, each signature checked with `osslsigncode`
  (digest always, chain with `WINDOWS_SIGN_CAFILE`). The uninstaller is signed by the app's `.nsi`
  through `!uninstfinalize '${SHELL_PACKAGER_SIGN}'`, which runs `windows-sign`; it records what it
  signed in `SHELL_PACKAGER_SIGN_LOG`, and an empty record fails the build.
- **On a Windows host** (the worker check, CI): `proc_open()` pipes are not selectable, so the
  handshake polls a stdout file; `composer` runs through a command string (CreateProcess runs no
  `.bat` without its extension, and a batch file found on PATH under a quoted name gets the wrong
  `%~dp0`); a path package is installed as a **copy** (the lock's `transport-options`, which an
  install from a lock reads; composer's junction is a leaf to `RecursiveDirectoryIterator`, so the
  package went missing from the PHAR). The Linux fixture tarball was byte-identical before and after.
- **`runtimes` is grouped by platform** (`linux`, `macos`, `windows`); a flat one is Linux's, and a
  mix is an error. Grafida's configuration used the flat shape. A runtime's `url` is one template
  (`{arch}`) or an object of one URL per architecture, for pins of one platform verified on
  different releases (Grafida's macOS arm64 and x86_64).
- **`linux.replaces`** (grafida/grafida#92) lets `install.sh` remove an earlier build's desktop
  entry and hicolor icons under another id, only when its main `Exec` (field codes dropped, quotes
  removed; an older installer wrote an unquoted path with spaces) ends in `/<linux.name>`. The ids are
  validated as desktop ids so the script can glob them; the app's own id is refused.
- **Determinism:** sorted PHAR entries, `seld/phar-utils` timestamps, `SOURCE_DATE_EPOCH`, and our
  own `Archive\TarWriter` (ustar, uid/gid 0, fixed mtime, streamed gzip). PharData cannot set modes
  and times, and BSD tar on macOS has no `--sort`/`--mtime`, while Linux packages are also built on
  macOS.
- **The fixture app (`tests/fixtures/app`) carries a minimal, independent protocol v1 worker** (its
  `bin/worker.php`), and its `shell-package.json` pins the **real** shell and SFX releases.
  `scripts/e2e-linux.sh` packages it, installs it and runs it headless (CI does, on both arches);
  `scripts/e2e-macos.sh` builds its DMG, mounts it, copies the app out and launches it **through
  LaunchServices** (`open`), which is the only thing that reads `Info.plist` (CI does, on macos-15,
  with `--no-style`; and the x86_64 bundle on `macos-15-intel`, `ARCH=x86_64`). `scripts/e2e-windows.sh` builds its installer (or takes one, `INSTALLER=`, as the
  test guests do), installs it silently, reads back the resources and signatures as Windows does,
  launches it and uninstalls it (CI does, unsigned, on windows-2022). ⚠️ In Git Bash an NSIS switch
  is `//S` (MSYS rewrites `/S` to `S:/`), and `/D=` takes a path with no space in it. Bump those pins
  when a new shell or SFX should be covered. The macOS tests need `SHELL_PACKAGER_TEST_MACOS_SHELL`
  and `_SFX`, the Windows ones `SHELL_PACKAGER_TEST_WINDOWS_SHELL` and `_SFX` (CI fetches the pins
  with `fetch --platform=…`).
- **Every Linux change is checked byte for byte**: with a fixed `SOURCE_DATE_EPOCH`, the fixture's
  tarball before and after must have the same SHA-256 (done for the 0.2.0 refactor, on the 0.1.0
  pins, before they were bumped).
- Every PHP file uses the MIT docblock; PHPStan level max + strict rules (`composer linter:check`)
  and the suite (`composer test`) must pass, as must `shellcheck` on `scripts/` and `resources/`.

## Conventions

- Scripts are POSIX `sh`/`bash` that pass `shellcheck`, or PHP ^8.4 with `declare(strict_types=1)`.
  Every PHP file starts with the MIT docblock used in `bin/shell-packager`.
- Keep a terse `CHANGELOG.md` entry per change (Grafida's `!` `+` `-` `~` `# [HIGH|MEDIUM|LOW]` style).
- Commit directly on `main`.
