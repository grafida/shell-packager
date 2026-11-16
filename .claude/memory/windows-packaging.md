# Windows packaging and signing

Read before touching `src/Windows/`, PE resource editing, Jsign signing or the NSIS installer flow.

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
