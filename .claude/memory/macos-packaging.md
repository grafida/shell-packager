# macOS packaging, signing and DMG

Read before touching `src/Macos/`, Mach-O inspection, code signing / notarisation, or the DMG layout.

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
