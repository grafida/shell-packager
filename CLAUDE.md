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
  the app-agnostic proof that a payload boots. Both need a Linux host of the target architecture;
  elsewhere they are skipped with a note, never faked.
- **Determinism:** sorted PHAR entries, `seld/phar-utils` timestamps, `SOURCE_DATE_EPOCH`, and our
  own `Archive\TarWriter` (ustar, uid/gid 0, fixed mtime, streamed gzip). PharData cannot set modes
  and times, and BSD tar on macOS has no `--sort`/`--mtime`, while Linux packages are also built on
  macOS.
- **The fixture app (`tests/fixtures/app`) carries a minimal, independent protocol v1 worker** (its
  `bin/worker.php`), and its `shell-package.json` pins the **real** shell and SFX releases.
  `scripts/e2e-linux.sh` packages it, installs it and runs it headless (CI does, on both arches).
  Bump those pins when a new shell or SFX should be covered.
- Every PHP file uses the MIT docblock; PHPStan level max + strict rules (`composer linter:check`)
  and the suite (`composer test`) must pass, as must `shellcheck` on `scripts/` and `resources/`.

## Conventions

- Scripts are POSIX `sh`/`bash` that pass `shellcheck`, or PHP ^8.4 with `declare(strict_types=1)`.
  Every PHP file starts with the MIT docblock used in `bin/shell-packager`.
- Keep a terse `CHANGELOG.md` entry per change (Grafida's `!` `+` `-` `~` `# [HIGH|MEDIUM|LOW]` style).
- Commit directly on `main`.
