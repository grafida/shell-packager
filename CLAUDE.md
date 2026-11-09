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

## Conventions

- Scripts are POSIX `sh`/`bash` that pass `shellcheck`, or PHP ^8.4 with `declare(strict_types=1)`.
  Every PHP file starts with the MIT docblock used in `bin/shell-packager`.
- Keep a terse `CHANGELOG.md` entry per change (Grafida's `!` `+` `-` `~` `# [HIGH|MEDIUM|LOW]` style).
- Commit directly on `main`.
