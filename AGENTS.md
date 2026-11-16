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
- Every downloaded binary is verified by SHA-256 before use. There is no silent "no hash" path.
  - **The shell and launcher are pinned by version** (`src/Shell/`): `"shell": {"version": "<Composer constraint>"}`
    is resolved against `shell.repository`'s GitHub releases (default `grafida/shell`; public repositories only) into
    a committed `shell-package.lock`. The lock holds the seven files' URLs and SHA-256s, taken from GitHub's asset
    `digest`, never typed by hand. A build reads it and makes no API call. A stale lock (its `content-hash` is not
    the config's) fails the build. A lock whose repository, names or URLs are not that release's assets on GitHub is
    refused (`ShellLock::verify`), since the content hash does not cover the files. A missing lock is written first.
    `composer/semver` does not filter by stability (`^0.4` matches `0.4.3-beta1`), so `Resolver` applies Composer's
    minimum-stability rule itself. `shell.local` / `--local` files are announced UNPINNED on every use.
  - Every other runtime (SFX, AppImage runtime, nfpm) is pinned by `url` + `sha256`. The exception is a runtime the
    app sets to `{"latest": true}`: it is downloaded unpinned and announced as LATEST (with its URL and hash) on every
    use.

- **Every name comes from the app's manifest** (`id`, `title`, `worker.binary`), never from the
  packaging config, so the shell and the package cannot disagree. The desktop entry is
  `<id>.desktop`, with `Icon` and `StartupWMClass` forced to the id, because the shell's GTK
  program name (its Wayland `app_id`) is the id.
- Every PHP file uses the MIT docblock; PHPStan level max + strict rules (`composer linter:check`)
  and the suite (`composer test`) must pass, as must `shellcheck` on `scripts/` and `resources/`.

## Conventions

- Scripts are POSIX `sh`/`bash` that pass `shellcheck`, or PHP ^8.4 with `declare(strict_types=1)`.
  Every PHP file starts with the MIT docblock used in `bin/shell-packager`.
- Keep a terse `CHANGELOG.md` entry per change (Grafida's `!` `+` `-` `~` `# [HIGH|MEDIUM|LOW]` style).
- Commit directly on `main`.

## Project memory

Project memory lives in `.claude/memory/`. Read the matching file before starting that kind of work.

| File | Read it when… |
|------|---------------|
| [`phar-and-worker-check.md`](.claude/memory/phar-and-worker-check.md) | Before you touch `Phar\PharBuilder`, `Phar\WorkerCheck`, or run the worker on a Windows host |
| [`linux-packaging.md`](.claude/memory/linux-packaging.md) | Before you touch `src/Linux/` (AppImage, squashfs, PKGBUILD, nfpm `.deb`/`.rpm`, `linux.replaces`) |
| [`macos-packaging.md`](.claude/memory/macos-packaging.md) | Before you touch `src/Macos/`: Mach-O checks, signing / notarisation, the DMG |
| [`windows-packaging.md`](.claude/memory/windows-packaging.md) | Before you touch `src/Windows/`: PE resources, Jsign signing, the NSIS installer |
| [`runtimes-config.md`](.claude/memory/runtimes-config.md) | Before you change how `runtimes`, `shell` or `launcher` config entries are parsed |
| [`determinism-and-e2e-tests.md`](.claude/memory/determinism-and-e2e-tests.md) | Before you change archive creation, the fixture app, or the `scripts/e2e-*.sh` scripts |
