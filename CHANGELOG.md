shell-packager 0.6.0
================================================================================
+ `linux-appimage` without a host `mksquashfs`: it runs in a Docker image built from a pinned Dockerfile (#1)
+ `linux.appimage.squashfs` (`auto`, `host` or `docker`) and `linux.appimage.docker.image`, pinned by digest
+ `runtimes.linux.appimage-runtime` may be `{"latest": true}`: type2-runtime's newest (`continuous`) build, with a warning
~ The README links to the user documentation in grafida/shell instead of repeating it
# [LOW] An AppImage built on a Mac was not byte-identical to one built on Linux (the mode of `.DirIcon`)
# [LOW] `shell-packager version` said 0.4.0 in 0.5.0 and 0.5.1

shell-packager 0.5.1
================================================================================
# [MEDIUM] `linux-appimage` failed with squashfs-tools 4.5 (Ubuntu 22.04): it has no `-repro-time`

shell-packager 0.5.0
================================================================================
+ `linux-deb` and `linux-rpm`: packages under `/opt/<name>`, built by a pinned nfpm 2.49.0 (grafida/grafida#87)
+ `linux-appimage`: a reproducible AppImage on the pinned type-2 runtime, built with `mksquashfs` (grafida/grafida#87)
+ `linux-pkgbuild`: an Arch `PKGBUILD` for the tarballs, on the system `webkit2gtk-4.1` and `gtk3` (grafida/grafida#87)
+ `linux.package`, `linux.appimage` and `linux.pkgbuild` sections; WebKitGTK 4.1 and GTK 3 are built-in dependencies
+ `runtimes.linux.appimage-runtime`: the pinned type-2 runtime (`--local appimage-runtime=PATH`)
+ `fetch --nfpm` fetches and verifies the pinned nfpm for this build host
+ `--no-sign` for `macos-app`, `macos-dmg`, `windows-tree` and `windows-installer`: a package with nothing signed
+ A runtime may be `{"latest": true}`: the newest shell, launcher or SFX release, unpinned, with a warning

shell-packager 0.4.0
================================================================================
+ macOS x86_64: `macos-app` and `macos-dmg --arch=x86_64` build an Intel bundle and image (`{debarch}` `amd64`)
+ `linux.replaces`: `install.sh` removes an earlier build's desktop entry and icons under another id
+ A runtime's `url` may name one URL per architecture
~ The macOS worker check runs an x86_64 worker on an arm64 Mac under Rosetta 2, and skips with a note without it
~ `macos-dmg --app=` refuses a bundle whose binaries are not of `--arch`
# [LOW] `macos-dmg` failed now and then with "hdiutil: convert failed - Resource temporarily unavailable"

shell-packager 0.3.1
================================================================================
~ The PHAR is filled in one call: Grafida's 1,690 files took 25 s and take 0.1 s, to the same bytes

shell-packager 0.3.0
================================================================================
+ `windows-tree`: the x86_64 install tree, shell and SFX stub with the app's icon and version resources, signed
+ `windows-installer`: the app's own NSIS script compiled by makensis on any host, its uninstaller signed through `!uninstfinalize` (+ `.sha256`)
+ `windows-sign`: Authenticode with Jsign from the environment (Trusted Signing, or a test keystore), checked with osslsigncode
+ `runtimes.windows` and a `windows` section; `fetch --platform=windows`
+ A PE reader in PHP (PE32+ and PE32): a stub with its payload appended is refused, never signed
+ Icon and version resources written in PHP: `.rsrc` rebuilt in place, `.reloc` moved behind it, the manifest kept
+ The worker check runs on Windows hosts, on the signed stub in the finished tree
+ Windows end-to-end test: the fixture installer installed silently, launched and uninstalled (CI, windows-2022)
- On Windows hosts: composer ran as a quoted batch file, and a path package installed as a junction went missing from the PHAR
- A drive-letter path in the configuration was taken for a relative one
~ `ext-mbstring` is required

shell-packager 0.2.0
================================================================================
+ `macos-app`: an arm64 app bundle with a generated `Info.plist`, signed inside-out with the hardened runtime
+ `macos-dmg`: a disk image with the app and an `/Applications` link; Finder layout, volume icon, notarisation
+ `runtimes` grouped by platform (`linux`, `macos`); a flat `runtimes` still means Linux
+ The bundle's binaries are checked: arm64, minimum macOS within 12.0, and no payload appended to the stub
+ The packaged macOS worker is run inside the signed bundle before the image is written
+ macOS end-to-end test: the fixture app's image mounted, copied out and launched through LaunchServices
~ `composer test` turns `phar.readonly` off itself

shell-packager 0.1.0
================================================================================
+ Repository skeleton: CLI entry point, CI, licence
+ `linux-tarball`: launcher, shell, SFX stub + sibling `.phar`, manifest, desktop entry, icons, per-user `install.sh`
+ `phar`: worker PHAR from a `--no-dev` staging install, behind phpmicro's extra-INI block; deterministic
+ `fetch`: runtimes pinned by SHA-256 in the app's configuration; a mismatch or missing pin is fatal
+ The packaged worker is run before a package is written: the ini block must apply, the worker must send `ready`
+ End-to-end test: the fixture app packaged with the real shell and SFX, installed and run headless
