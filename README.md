# grafida/shell-packager

Build and packaging tooling for applications built on the
[Grafida desktop shell](https://github.com/grafida/shell): it turns an app (a PHP worker and its
`shell.json` manifest) into a package with the prebuilt shell and the
[phpmicro](https://github.com/grafida/phpmicro) `sibling-phar` worker runtime. It was extracted from
[Grafida](https://github.com/grafida/grafida)'s own build scripts, and it knows nothing about any
one app's layout: everything app-specific comes from the app's configuration and manifest.

**Status:** Linux (tarball + per-user installer), macOS (arm64 and x86_64 bundles, signing, notarisation, DMG)
and Windows (x86_64 install tree, icon and version resources, Authenticode, an NSIS installer) work,
as tracked in [grafida/grafida#82](https://github.com/grafida/grafida/issues/82). Every platform's
package is built and signed on any host: the Mac builds all of them.

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

A JSON file in the app (conventionally `shell-package.json`). Paths are relative to it. The package
is named after the app's manifest, so the shell and the package cannot disagree: `id` names the
desktop entry and icons (and is the window's Wayland `app_id`), `title` appears in the alerts, and
`worker.binary` names the SFX stub and its `.phar`.

```jsonc
{
  "manifest": "shell.json",
  "phar": {
    "entrypoint": "bin/worker.php",
    "include": [
      {"directory": "assets/private"},
      {"directory": "src", "name": "*.php"},
      {"directory": "vendor", "name": "*.php", "notName": ["*Test.php"], "notPath": ["some/package"]}
    ],
    "ini": {"memory_limit": "512M", "opcache.enable": false}
  },
  "runtimes": {
    "linux": {
      "shell":    {"url": "https://github.com/grafida/shell/releases/download/0.2.0/grafida-shell-linux-{arch}",    "sha256": {"x86_64": "…", "aarch64": "…"}},
      "launcher": {"url": "https://github.com/grafida/shell/releases/download/0.2.0/grafida-launcher-linux-{arch}", "sha256": {"x86_64": "…", "aarch64": "…"}},
      "sfx":      {"url": "https://github.com/grafida/phpmicro/releases/download/sfx-…/linux-{arch}.standard.sfx",  "sha256": {"x86_64": "…", "aarch64": "…"}}
    },
    "macos": {
      "shell": {"url": {"aarch64": "https://github.com/grafida/shell/releases/download/0.2.0/grafida-shell-macos-arm64",
                        "x86_64":  "https://github.com/grafida/shell/releases/download/0.4.0/grafida-shell-macos-x86_64"},
                "sha256": {"aarch64": "…", "x86_64": "…"}},
      "sfx":   {"url": "https://github.com/grafida/phpmicro/releases/download/sfx-…/macos-{arch}.standard.sfx",     "sha256": {"aarch64": "…", "x86_64": "…"}}
    },
    "windows": {
      "shell": {"url": "https://github.com/grafida/shell/releases/download/0.3.0/grafida-shell-windows-x86_64.exe", "sha256": {"x86_64": "…"}},
      "sfx":   {"url": "https://github.com/grafida/phpmicro/releases/download/sfx-…/windows-x86_64.standard.sfx",  "sha256": {"x86_64": "…"}}
    }
  },
  "linux": {
    "name": "myapp",
    "desktop": "assets/myapp.desktop",
    "icons": {"48": "assets/icons/48.png", "512": "assets/icons/512.png"},
    "files": {"LICENSE.txt": "LICENSE.txt"},
    "output": "build/dist/MyApp-{version}-linux-{debarch}.tar.gz"
  },
  "macos": {
    "name": "MyApp",
    "icon": "assets/MyApp.icns",
    "infoPlist": {"NSHumanReadableCopyright": "…", "LSApplicationCategoryType": "public.app-category.productivity"},
    "entitlements": {"shell": "build/shell.entitlements", "worker": "build/worker.entitlements"},
    "resources": {"LICENSE.txt": "LICENSE.txt"},
    "dmg": {
      "background": "assets/dmg-background.tiff", "window": [640, 400], "iconSize": 128,
      "positions": {"app": [160, 210], "applications": [480, 210]}, "volumeIcon": "assets/MyApp.icns"
    },
    "app": "build/shell-macos/{name}.app",
    "output": "build/dist/MyApp-{version}-macos-{debarch}.dmg"
  },
  "windows": {
    "name": "MyApp",
    "icon": "assets/MyApp.ico",
    "version": {"companyName": "…", "legalCopyright": "…", "workerDescription": "MyApp worker"},
    "files": {"LICENSE.txt": "LICENSE.txt"},
    "installer": {"script": "build/windows/installer.nsi", "defines": {"SHORTCUTS": "yes"}},
    "tree": "build/shell-windows/{name}",
    "output": "build/dist/MyApp-{version}-windows-{debarch}-Setup.exe"
  }
}
```

- **`phar.include`** lists directories, optionally narrowed by `name` globs, `notName` globs and
  `notPath` prefixes. Anything under `vendor/` comes from a `composer install --no-dev
  --no-scripts` of the app's `composer.json` + `composer.lock` in a staging directory, never from
  the app's own `vendor/`, so dev dependencies never ship.
- **`phar.ini`** becomes phpmicro's extra-INI block (`fd f6 69 e6`, a big-endian length, the ini
  text) at the front of the `.phar` file. It applies before any PHP runs, so it can set what
  `ini_set()` cannot.
- **`runtimes`** are grouped by platform; a flat `runtimes` (the names straight under it) still
  means Linux, and a mix of the two shapes is an error. A URL without `{arch}` names one file. When
  the architectures come from different releases (an x86_64 pin added after the arm64 one was
  verified), `url` maps each architecture to its own URL. They are pinned by the app. A download whose SHA-256 is not the pinned one is fatal,
  and so is an architecture with no pin. A hash published beside the download is never consulted.
  `--local NAME=PATH` substitutes a local file for testing an unreleased build; it is announced as
  UNPINNED every time.

## What is checked before a package is written

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
- **Signing comes from the environment**, never the configuration: `MACOS_SIGN_IDENTITY` (a
  "Developer ID Application: …" identity) or, without it, ad hoc. Inside-out (the stub, the shell,
  the bundle), always with the **hardened runtime**, so an ad-hoc build runs under the same
  restrictions as the notarised one. Each binary gets `macos.entitlements.<shell|worker>`, or none.
  The signatures are then verified (`codesign --verify --strict`) and read back: the hardened
  runtime on each binary, and exactly the configured entitlements.
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
- **Signing comes from the environment**, with Jsign: Azure Trusted Signing (`WINDOWS_SIGN_ENDPOINT`,
  `WINDOWS_SIGN_PROFILE` as `<account>/<profile>`, and `WINDOWS_SIGN_TOKEN`, an access token for
  `https://codesigning.azure.net` the caller obtains), or a test keystore (`WINDOWS_SIGN_PKCS12`,
  `WINDOWS_SIGN_PKCS12_PASSWORD`); neither leaves everything unsigned. The secrets reach Jsign as
  `env:` references, never on its command line. Each signature is checked with `osslsigncode`: the
  signed digest must match the file; the chain only when `WINDOWS_SIGN_CAFILE` names its roots. A
  file with anything appended past its image is never signed (an installer is the one exception).
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
