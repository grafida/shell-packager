# grafida/shell-packager

Build and packaging tooling for applications built on the
[Grafida desktop shell](https://github.com/grafida/shell): it turns an app (a PHP worker and its
`shell.json` manifest) into a package with the prebuilt shell and the
[phpmicro](https://github.com/grafida/phpmicro) `sibling-phar` worker runtime. It was extracted from
[Grafida](https://github.com/grafida/grafida)'s own build scripts, and it knows nothing about any
one app's layout: everything app-specific comes from the app's configuration and manifest.

> [!TIP]
> **Start with the user documentation:** [Packaging](https://github.com/grafida/shell/blob/main/docs/user/packaging.md) covers the configuration, the pinned runtimes,
> building for each platform, unsigned builds and signing credentials, and
> [Getting signing certificates](https://github.com/grafida/shell/blob/main/docs/user/code-signing.md) explains how to obtain them. This README keeps the
> packager-specific reference: command line, the layout of each package and what the packager checks.

## Use

```sh
composer require --dev grafida/shell-packager

vendor/bin/shell-packager linux-tarball --config=shell-package.json --arch=x86_64 --app-version=1.0.0
vendor/bin/shell-packager macos-app --config=shell-package.json --app-version=1.0.0
vendor/bin/shell-packager macos-dmg --config=shell-package.json --app-version=1.0.0
vendor/bin/shell-packager windows-tree --config=shell-package.json --app-version=1.0.0
vendor/bin/shell-packager windows-installer --config=shell-package.json --app-version=1.0.0
vendor/bin/shell-packager phar  --config=shell-package.json --output=build/app-worker.phar
vendor/bin/shell-packager fetch --config=shell-package.json --arch=aarch64 [--platform=linux|macos|windows]
```

`SOURCE_DATE_EPOCH` fixes every timestamp. With the same inputs, the PHAR and the Linux tarball are
then byte-identical (the macOS bundle and image are not: `codesign` and `hdiutil` are not). Downloads are cached by SHA-256 in `SHELL_PACKAGER_CACHE` (default
`$XDG_CACHE_HOME/grafida-shell-packager`).

## The configuration

The configuration is a JSON file in the app, conventionally `shell-package.json`; paths in it are relative to it. Its
members (`phar`, `runtimes`, `linux`, `macos`, `windows`), the pinned and `latest` runtimes, and `--local` are
documented in [Packaging](https://github.com/grafida/shell/blob/main/docs/user/packaging.md#the-configuration-file-shell-packagejson). The package is named after the
app's manifest, so the shell and the package cannot disagree: `id` names the desktop entry and icons (and is the
window's Wayland `app_id`), `title` appears in the alerts, and `worker.binary` names the SFX stub and its `.phar`.

Two implementation details: `phar.ini` becomes phpmicro's extra-INI block (`fd f6 69 e6`, a
big-endian length, the ini text) at the front of the `.phar` file, and a flat `runtimes` (the names straight under it)
still means Linux, while a mix of the flat and the per-platform shape is an error.

## What is checked before a package is written

The packaged worker is run before a package is written; see
[the packaged worker is tested](https://github.com/grafida/shell/blob/main/docs/user/packaging.md#the-packaged-worker-is-tested-before-it-is-written). In detail:

On a machine of the target's OS and architecture (Linux, a Mac, or Windows x86_64; an x86_64 macOS
worker also on an arm64 Mac with Rosetta 2, translated), the packaged worker is run:

- **an ini probe:** the same block, on the same SFX, must read back every setting. phpmicro
  silently ignores a malformed block.
- **a handshake:** the payload on the SFX must send protocol v1's `ready` frame and exit when its
  stdin closes. Every shell worker speaks the protocol, so this holds for any app.

Both run with every per-user directory pointed at a temporary directory. On macOS the handshake runs
on the **signed** stub inside the finished bundle, so it also proves the stub finds its payload in
`../Resources` and runs under the hardened runtime with the entitlements it was given. On Windows it
runs on the signed stub in the finished tree, its resources rewritten and its signature appended.
Elsewhere (a Windows package built on the Mac, an x86_64 bundle on an arm64 Mac without Rosetta 2)
it is skipped with a note, never faked.

## The Linux tarball

```
myapp/
  myapp                  the launcher (static): checks the system libraries, alerts, execs the shell
  grafida-shell          the shell
  myapp-worker           the phpmicro SFX stub
  myapp-worker.phar      the worker payload (extra-INI block + PHAR)
  shell.json
  <id>.desktop           Exec, Icon and StartupWMClass forced to agree with the package
  icons/hicolor/<n>x<n>/apps/<id>.png
  install.sh             ./install.sh installs the desktop entry and icons for the current user
  …                      linux.files
```

`linux.replaces` lists the desktop ids of an earlier build of the app installed under another id
(`["myapp"]` for an app whose old packaging used `myapp.desktop`). Installing removes each one's
`~/.local/share/applications/<id>.desktop` and its hicolor icons, but only when the entry exists and
its `Exec` (field codes dropped, quotes removed) ends in `/<linux.name>`; an entry with that id that
starts anything else is left alone, and `install.sh` says so. `--uninstall` removes only this
build's own entry and icons.

⚠️ **The SFX stub and its `.phar` are two files and must stay two.** A stub with the payload
appended cannot be code-signed: the signature lands past the PHAR and corrupts it. Linux does not
sign, but the same layout serves macOS and Windows.

## The macOS bundle and image

```
MyApp.app/Contents/
  Info.plist              generated (below)
  MacOS/MyApp             the shell (CFBundleExecutable)
  MacOS/myapp-worker      the phpmicro SFX stub
  Resources/myapp-worker.phar   the worker payload (extra-INI block + PHAR)
  Resources/shell.json
  Resources/MyApp.icns    macos.icon
  Resources/…             macos.resources
```

- **One architecture per bundle**, `--arch=aarch64` (the default) or `--arch=x86_64` for Intel Macs.
  Both binaries must be thin Mach-O files of that architecture whose minimum macOS (read from
  `LC_BUILD_VERSION`) is within the bundle's `LSMinimumSystemVersion`, 12.0: an `Info.plist` must
  never offer the app to a Mac it cannot run on. `{arch}` (aarch64/x86_64) and `{debarch}`
  (arm64/amd64) in `macos.app` and `macos.output` name each architecture's bundle and image, and
  `macos-dmg --app=` refuses a bundle whose binaries are not of `--arch`.
- **`Info.plist`** is generated. The packager computes the identifier (the manifest `id`), the
  names (`title`, `macos.name`), the version (`--app-version`, one to three integers), the icon
  and `LSMinimumSystemVersion`. Everything else comes from `macos.infoPlist`; a computed key there is
  an error.
- **The stub must be bare.** A stub with its PHAR still appended is refused: signing it would seal
  past the PHAR and corrupt it.
- **Signing** is described in [Unsigned builds](https://github.com/grafida/shell/blob/main/docs/user/packaging.md#unsigned-builds) and
  [Providing signing credentials](https://github.com/grafida/shell/blob/main/docs/user/packaging.md#providing-signing-credentials). It goes inside out (the stub, the
  shell, the bundle), always with the **hardened runtime**, so an ad-hoc build runs under the same restrictions as the
  notarised one. Each binary gets `macos.entitlements.<shell|worker>`, or none. The signatures are then verified
  (`codesign --verify --strict`) and read back: the hardened runtime on each binary, and exactly the configured
  entitlements.
- **The image** holds the bundle and an `/Applications` link. Finder lays it out (`macos.dmg`) through
  AppleScript, best-effort: it needs Finder's Automation permission, runs under a time limit, and
  styles only a volume mounted at exactly `/Volumes/<name>`; otherwise the image stays plain, with a
  warning. `--no-style` skips it (a CI runner has no Finder to drive). The volume icon is written
  after the layout. The image is UDZO, signed with the Developer ID when there is one.
- **Notarisation** runs when `MACOS_NOTARY_PROFILE` names a `notarytool` keychain profile (it needs
  the identity too): submit and wait, staple, validate, and `spctl` on the image.

## The Windows tree and installer

```
MyApp/
  MyApp.exe              the shell, with windows.icon and a version resource
  myapp-worker.exe       the phpmicro SFX stub, likewise
  myapp-worker.phar      the worker payload (extra-INI block + PHAR)
  shell.json
  MyApp.ico              windows.icon, for shortcuts and Add/Remove Programs
  …                      windows.files
```

- **x86_64 only**, and both executables must be GUI-subsystem (a console one would open a window),
  bare (nothing appended: the stub's PHAR stays a sibling file) and unsigned (the packager writes
  their resources first).
- **Icon and version resources are written in PHP** on any host (no `rcedit`). The executable's
  `.rsrc` section is rebuilt in place, keeping the application manifest, and the `.reloc` section
  behind it moves up; the image size and checksum follow. `FileDescription` is what Task Manager
  names the process by: `windows.version.shellDescription` (default: the name) and
  `workerDescription` (default: "<name> worker"). The four-part version comes from `--app-version`:
  major.minor.patch, then 1000 for a release, or 0–9 / 10–100 / 900–999 for alpha / beta / rc N, so
  a pre-release sorts below its release.
- **Signing** (Azure Trusted Signing or a keystore, through Jsign, or nothing) and `--no-sign` are described in
  [Providing signing credentials](https://github.com/grafida/shell/blob/main/docs/user/packaging.md#providing-signing-credentials) and
  [Unsigned builds](https://github.com/grafida/shell/blob/main/docs/user/packaging.md#unsigned-builds). Each signature is checked with `osslsigncode`: the signed digest
  must match the file; the chain only when `WINDOWS_SIGN_CAFILE` names its roots. A file with anything appended past
  its image is never signed (an installer is the one exception).
- **The installer is the app's own NSIS script** (`windows.installer.script`), compiled by
  `makensis` 3.08 or later, which runs on macOS and Linux. It gets the tree and the documented
  defines (`SRCDIR`, `OUTFILE`, `APPNAME`, `APPID`, `APPEXE`, `WORKEREXE`, `WORKERPHAR`, `ICONFILE`,
  `APPVERSION`, `VIVERSION`, `PUBLISHER`; see `src/Windows/WindowsInstaller.php`) plus
  `windows.installer.defines`. When signing, it also gets `SHELL_PACKAGER_SIGN`, and **must** sign its
  uninstaller with it (`!uninstfinalize '${SHELL_PACKAGER_SIGN}' = 0`), since the uninstaller is
  written inside the installer and nothing can reach it later; a script that does not is refused,
  and one that signs nothing fails the build. Then the installer itself is signed.
  `tests/fixtures/app/installer.nsi` is a complete example.

## Licence

MIT — see [LICENSE](LICENSE).
