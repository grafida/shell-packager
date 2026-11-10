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
