# grafida/shell-packager

Build and packaging tooling for applications built on the
[Grafida desktop shell](https://github.com/grafida/shell): it turns an app (a PHP worker and its
`shell.json` manifest) into installable packages for Linux, macOS and Windows, with the prebuilt shell and the
[phpmicro](https://github.com/grafida/phpmicro) worker runtime. It knows nothing about any one app's layout: everything
app-specific comes from the app's configuration and manifest.

## Documentation

All user documentation lives in [grafida/shell](https://github.com/grafida/shell/blob/main/docs/user/README.md).
For this package:

- [Packaging](https://github.com/grafida/shell/blob/main/docs/user/packaging.md): installing the packager, which
  machine builds what, the configuration file, the pinned runtimes, and every command.
- [Linux packages](https://github.com/grafida/shell/blob/main/docs/user/packaging.md#linux): the tarball, `.deb` and
  `.rpm`, the AppImage and the Arch `PKGBUILD`.
- [macOS](https://github.com/grafida/shell/blob/main/docs/user/packaging.md#macos) and
  [Windows](https://github.com/grafida/shell/blob/main/docs/user/packaging.md#windows) packages.
- [What the packager checks](https://github.com/grafida/shell/blob/main/docs/user/packaging.md#the-packaged-worker-is-tested-before-it-is-written)
  before it writes a package.
- [Unsigned builds](https://github.com/grafida/shell/blob/main/docs/user/packaging.md#unsigned-builds),
  [signing credentials](https://github.com/grafida/shell/blob/main/docs/user/packaging.md#providing-signing-credentials)
  and [getting signing certificates](https://github.com/grafida/shell/blob/main/docs/user/code-signing.md).

`vendor/bin/shell-packager help` lists the commands and options.

## Development

- `composer test` runs the unit suite; `composer linter:check` runs PHPStan (level max, strict rules). Scripts in
  `scripts/` and `resources/` must pass `shellcheck`.
- Tests that need real binaries or tools are skipped unless these are set:
  - `SHELL_PACKAGER_TEST_APPIMAGE_RUNTIME`: the pinned type-2 runtime (`fetch --platform=linux`).
  - `SHELL_PACKAGER_TEST_DOCKER`: build an AppImage's squashfs in the packager's Docker image, and compare it with
    the host's `mksquashfs`.
  - `SHELL_PACKAGER_TEST_GITHUB=1`: list the real `grafida/shell` releases, through `gh` and through the GitHub API.
  - `SHELL_PACKAGER_TEST_NFPM`: an nfpm binary, to build and read back `.deb` and `.rpm` packages (`fetch --nfpm`).
  - `SHELL_PACKAGER_TEST_SFX` (Linux), `SHELL_PACKAGER_TEST_MACOS_SHELL`, `_SFX` (with `_ARCH`, default aarch64) and
    `SHELL_PACKAGER_TEST_WINDOWS_SHELL`, `_SFX`: the pinned shells and SFX stubs (`fetch --platform=…`), for the
    worker check, signing and the bundles.
- `scripts/e2e-linux.sh`, `scripts/e2e-macos.sh` and `scripts/e2e-windows.sh` package the fixture app
  (`tests/fixtures/app`), install it and run it on a real machine. CI does not run them.
- `CHANGELOG.md` gets one terse line per change.

## Licence

MIT — see [LICENSE](LICENSE).
