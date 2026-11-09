# grafida/shell-packager

Build and packaging tooling for applications built on the
[Grafida desktop shell](https://github.com/grafida/shell): it turns an app (a PHP worker and its
`shell.json` manifest) into a package with the prebuilt shell and the
[phpmicro](https://github.com/grafida/phpmicro) `sibling-phar` worker runtime. It was extracted from
[Grafida](https://github.com/grafida/grafida)'s own build scripts, and it knows nothing about any
one app's layout: everything app-specific comes from the app's configuration and manifest.

**Status:** Linux (tarball + per-user installer) works. macOS (bundle, signing, notarisation, DMG)
and Windows (NSIS, Authenticode) follow, as tracked in
[grafida/grafida#82](https://github.com/grafida/grafida/issues/82).

## Use

```sh
composer require --dev grafida/shell-packager

vendor/bin/shell-packager linux-tarball --config=shell-package.json --arch=x86_64 --app-version=1.0.0
vendor/bin/shell-packager phar  --config=shell-package.json --output=build/app-worker.phar
vendor/bin/shell-packager fetch --config=shell-package.json --arch=aarch64
```

`SOURCE_DATE_EPOCH` fixes every timestamp. With the same inputs, the PHAR and the tarball are then
byte-identical. Downloads are cached by SHA-256 in `SHELL_PACKAGER_CACHE` (default
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
    "shell":    {"url": "https://github.com/grafida/shell/releases/download/0.1.0/grafida-shell-linux-{arch}",    "sha256": {"x86_64": "…", "aarch64": "…"}},
    "launcher": {"url": "https://github.com/grafida/shell/releases/download/0.1.0/grafida-launcher-linux-{arch}", "sha256": {"x86_64": "…", "aarch64": "…"}},
    "sfx":      {"url": "https://github.com/grafida/phpmicro/releases/download/sfx-…/linux-{arch}.standard.sfx",  "sha256": {"x86_64": "…", "aarch64": "…"}}
  },
  "linux": {
    "name": "myapp",
    "desktop": "assets/myapp.desktop",
    "icons": {"48": "assets/icons/48.png", "512": "assets/icons/512.png"},
    "files": {"LICENSE.txt": "LICENSE.txt"},
    "output": "build/dist/MyApp-{version}-linux-{debarch}.tar.gz"
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
- **`runtimes`** are pinned by the app. A download whose SHA-256 is not the pinned one is fatal,
  and so is an architecture with no pin. A hash published beside the download is never consulted.
  `--local NAME=PATH` substitutes a local file for testing an unreleased build; it is announced as
  UNPINNED every time.

## What is checked before a package is written

On a Linux machine of the target architecture, the packaged worker is run:

- **an ini probe:** the same block, on the same SFX, must read back every setting. phpmicro
  silently ignores a malformed block.
- **a handshake:** the payload on the SFX must send protocol v1's `ready` frame and exit when its
  stdin closes. Every shell worker speaks the protocol, so this holds for any app.

Both run with every per-user directory pointed at a temporary directory.

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

⚠️ **The SFX stub and its `.phar` are two files and must stay two.** A stub with the payload
appended cannot be code-signed: the signature lands past the PHAR and corrupts it. Linux does not
sign, but the same layout serves macOS and Windows.

## Licence

MIT — see [LICENSE](LICENSE).
