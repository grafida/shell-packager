# Pinning the Grafida shell by version — design

Date: 2026-11-16. Repositories: `grafida/shell-packager` (implementation), `grafida/shell` (documentation),
`grafida/grafida` (adoption).

## Intent

Today the shell and launcher are pinned in `shell-package.json` as one URL and one SHA-256 for each of five
targets: seven files (Linux aarch64 and x86_64 shell + launcher, macOS arm64, macOS x86_64, Windows x86_64).
Upgrading the shell means copying seven URLs and seven hashes by hand. That is slow and easy to get wrong, and
it serves no purpose: every `grafida/shell` release is a tag with a GitHub release whose assets GitHub already
hashes. From now on every shell release carries every artefact for every platform and architecture, and the shell
follows semantic versioning.

So an app pins the shell by a **version constraint**, with Composer's semantics
(<https://getcomposer.org/doc/articles/versions.md>). The packager resolves it against the GitHub releases of
`grafida/shell` (or a configured repository), takes each asset's SHA-256 from GitHub, and records the result in a
committed **lock file**, as Composer does. An exact constraint (`1.2.3`) makes builds repeatable for anyone; a
range plus the lock makes them repeatable until the next `update`.

## Goals

- One place in `shell-package.json` names the shell version for every platform.
- `shell-packager update` resolves the constraint and rewrites the lock in one command.
- Builds from a lock are verified against the hashes in the lock, re-hashed on every use, as today.
- Local paths in the configuration can stand in for any shell or launcher file, for testing an unreleased
  shell with an app without publishing a release.
- The repository and the asset names are configurable (for a fork such as `foobar/shell`), with defaults that
  match what `grafida/shell` publishes.

## Non-goals

- The phpmicro SFX and the AppImage runtime keep their `url` + `sha256` pins and their `{"latest": true}` option,
  unchanged. The SFX will get versions later, so the release lookup is built as a reusable piece, not
  shell-specific code, but only the shell and launcher use it in this change.
- No support for the per-platform version split (`url` per architecture from different releases) for the shell:
  one constraint resolves to one release for every platform.
- No use of the `.sha256` sidecar files the shell publishes. GitHub's `digest` is the hash.
- No private repositories (the operator's decision). Downloads use the public `browser_download_url` without
  credentials; a token, when present, is used only to list releases (rate limits). The packager's README and
  `grafida/shell`'s packaging docs say "public repositories only". Supporting private ones later means a new lock
  field (the asset API URL), so every committed lock would be regenerated with `update`; that is accepted.

## Anti-goals

- A build that silently uses a file without checking a hash. Every downloaded shell file is checked against the
  SHA-256 recorded in the lock, which came from GitHub's `digest`. An asset that has no `digest` is an error.
  Local files are the only unhashed files, and each one is announced as UNPINNED on every use, as `--local` is
  today.
- Anything Grafida-specific hard-coded beyond the documented defaults (`grafida/shell`, its asset names): the
  packager stays app-agnostic.

## Approaches considered

- **A. One top-level `shell` block with fixed asset names.** All platforms resolve to one release. The asset names
  are packager constants.
- **B. `{"version": …}` in place of each `runtimes.<platform>.shell` / `launcher`.** Closest to today's shape, but
  five entries to bump, and the platforms can be pinned to different versions.
- **C. A, plus configurable asset-name templates.** Defaults equal the names `grafida/shell` publishes. A fork
  that names its files differently overrides them.

**Chosen: C** (the operator's choice).

Resolution timing: resolving the constraint on every build was rejected in favour of a Composer-style lock file.

## Configuration

A new top-level `shell` block in `shell-package.json`:

```jsonc
"shell": {
    "version": "^0.4.2",                 // required: a Composer version constraint
    "repository": "grafida/shell",       // optional, default grafida/shell: owner/name on github.com
    "assets": {                          // optional: merged over the defaults per platform and component
        "linux":   {"shell": "grafida-shell-linux-{arch}", "launcher": "grafida-launcher-linux-{arch}"},
        "macos":   {"shell": {"aarch64": "grafida-shell-macos-arm64", "x86_64": "grafida-shell-macos-x86_64"}},
        "windows": {"shell": "grafida-shell-windows-{arch}.exe"}
    },
    "local": {                           // optional: files that stand in for release assets
        "macos": {"shell": {"aarch64": "../shell/target/aarch64-apple-darwin/release/grafida-shell"}},
        "linux": {"shell": "../shell/dist/grafida-shell-linux-{arch}"}
    }
}
```

- The `assets` shown above **are the defaults**. An asset name is the same shape as a runtime `url` today: a
  string in which `{arch}` stands for `x86_64`/`aarch64`, or an object mapping each architecture to a name.
  The object shape covers macOS's `arm64` without a new placeholder.
- The set of platforms × components × architectures is fixed by the packager: Linux shell + launcher on x86_64
  and aarch64, macOS shell on aarch64 and x86_64, Windows shell on x86_64. `assets` can rename these. It cannot
  add or remove any (an unknown platform, component or architecture is an error).
- `local` has the same shape as `assets`, with paths relative to the configuration file (like every other path in
  it). A configured local file that does not exist is an error. The CLI's `--local shell=PATH` and
  `--local launcher=PATH` keep working and take precedence over `shell.local` for that build.
- `runtimes.<platform>.shell` and `runtimes.<platform>.launcher` (and their flat, Linux-only form) are now an
  error whose message says to move to the `shell` block. `{"latest": true}` no longer applies to `shell` or
  `launcher` (`Runtime::latestUrls` loses those entries). `"version": "*"` plus `update` replaces it.

## Lock file

`shell-package.lock`, next to the configuration file (the configuration's base name with `.json` replaced by
`.lock`; if the name doesn't end in `.json`, `.lock` is appended). It is JSON, pretty-printed, keys in a fixed
order, ending with a newline, so it diffs cleanly and an unchanged resolution rewrites it byte for byte. It is
meant to be committed.

```jsonc
{
    "_readme": "Written by shell-packager update. Commit it. Do not edit it by hand.",
    "content-hash": "<sha256>",
    "shell": {
        "repository": "grafida/shell",
        "constraint": "^0.4.2",
        "version": "0.4.2",
        "tag": "0.4.2",
        "files": {
            "linux":   {"launcher": {"aarch64": {"name": "…", "url": "…", "sha256": "…"}, "x86_64": {…}},
                        "shell":    {"aarch64": {…}, "x86_64": {…}}},
            "macos":   {"shell": {"aarch64": {…}, "x86_64": {…}}},
            "windows": {"shell": {"x86_64": {…}}}
        }
    }
}
```

- `content-hash` is the SHA-256 of a canonical JSON encoding of `repository`, `version` (the constraint) and the
  **effective** asset names, after the defaults are merged. `local` is excluded, so adding or removing a local
  path never makes the lock stale.
- `files` always lists all seven files, whatever platform is being built, so one lock serves every build host.
- `url` is the asset's `browser_download_url`. `sha256` is GitHub's `digest` without its `sha256:` prefix.

## Resolution (`update`, or a build with no lock)

1. **List releases** of the repository: `gh api --paginate repos/{owner}/{repo}/releases` when `gh` is on PATH
   and `gh auth status` succeeds. Otherwise call `https://api.github.com/repos/{owner}/{repo}/releases?per_page=100`
   directly with curl, following the `Link: rel="next"` header, with `Authorization: Bearer` from `GH_TOKEN` or
   `GITHUB_TOKEN` when either is set, and unauthenticated otherwise. A failed lookup is an error that names the
   repository and the HTTP status or `gh`'s message.
2. **Drafts are skipped** explicitly, since an authenticated maintainer sees them.
3. **Each tag is parsed** with `composer/semver`'s `VersionParser::normalize()`. A leading `v` is accepted. A tag
   that does not parse is ignored.
4. **Stability:** `composer/semver` does not filter by stability (`^0.4` matches `0.4.3-beta1`; verified against
   3.6.0), so the packager applies Composer's rule itself. The minimum stability is `stable`. A trailing
   `@dev|alpha|beta|RC|stable` on the constraint lowers it, and so does an explicit pre-release version inside
   the constraint (`1.0.0-beta1`): use `VersionParser::parseStability()` on that version. Each tag's stability
   comes from `VersionParser::parseStability()`. GitHub's `prerelease` flag is not consulted: the version
   decides, as in Composer.
5. **Pick the highest version** that satisfies the constraint (`Semver::satisfies`, `Semver::rsort`) and meets
   the stability. None satisfying it is an error that lists the newest few versions available.
6. **Match every effective asset name** against that release's assets. A missing asset, or one without a
   `digest`, is an error naming the release and the asset.
7. **Write the lock.** `update` also downloads all seven files into the cache, checking each against its
   `digest`, so a bad upload is caught when the lock is written, not at a later build.

`update` prints the old and new version (or "unchanged") and every file's name and hash.

## Builds

- **With a lock whose `content-hash` matches:** no GitHub API call. Each needed file is fetched from the lock's
  `url` and checked against the lock's `sha256`. CI's `fetch --platform=…` works the same way.
- **With a lock whose `content-hash` does not match** (the constraint, repository or asset names changed): the
  build fails and says to run `shell-packager update`. It does not re-resolve on its own.
- **With no lock:** the build does exactly what `update` does (resolves, downloads and checks all seven files,
  writes the lock), says so prominently ("wrote shell-package.lock: commit it"), and continues.
- **Local files:** a file given in `shell.local` or `--local` replaces that one file. Every other file still comes
  from the lock. A build whose needed shell files are *all* local needs no lock and no network for them, and
  does not create a lock. Each local file is announced as UNPINNED, with its path and SHA-256, on every use.
- The SFX, AppImage runtime and nfpm are fetched exactly as today.

## Cache

`<cache>/shell/<owner>/<repo>/<version>/<asset name>`, under the existing cache root (`SHELL_PACKAGER_CACHE`, or
`$XDG_CACHE_HOME/grafida-shell-packager`). A cached file is re-hashed against the lock on every use. A mismatch
deletes it and downloads it again, and a second mismatch is an error. Downloads go to a `.part` file and are
renamed into place, as now. The existing hash-keyed cache for `url` + `sha256` runtimes is unchanged.

## Code shape (builder's call within these lines)

- `composer/semver` (^3.4) becomes a `require` dependency.
- A small release source behind an interface (list releases of `owner/repo` → tag, draft flag, assets with name,
  URL, digest), with a `gh` implementation and a curl implementation, so the tests inject a fake and never touch
  the network. Resolution and stability filtering are a pure function over that list. These are the pieces the
  SFX will reuse.
- The lock is its own class (read, validate, write, content hash). A lock that is malformed or names files the
  configuration doesn't need is an error, never half-read.
- The resolved shell and launcher files become `Runtime`-like pins for one version, so the per-platform packaging
  code (`LinuxTarball`, `AppImage`, `LinuxPackage`, `MacApp`, `WindowsTree`) keeps receiving file paths as it does
  now.
- New CLI command: `shell-packager update --config=FILE`. `fetch`, `linux-*`, `macos-*` and `windows-*` read the
  lock as described above.

## Text that has to change with it

- `CLAUDE.md`: the rule "Every downloaded binary is pinned and verified by SHA-256 before use" (shell files are
  now verified against the lock's hashes, which come from GitHub's digest), and the fixture paragraph ("pins the
  **real** shell and SFX releases").
- `Runtime.php`'s docblock ("a hash published beside the download is never consulted") applies only to
  `url` + `sha256` runtimes now. Say so.
- `Config.php`'s docblock example, `README.md`'s configuration and command sections, and the `--local` help text.
- `CHANGELOG.md`: a `!` entry (the shell is pinned by version, with a lock file) and a `~` entry (the old
  `runtimes` shell and launcher pins are refused).

## Tests

- Unit: constraint resolution (ranges, exact versions, `v` tags, unparsable tags ignored, drafts skipped,
  stability default and flags, explicit pre-release in the constraint), missing asset, missing digest, the asset
  name defaults and overrides, lock round trip (byte-identical rewrite), content hash ignores `local`, stale lock
  refused, no lock → lock written, local override per file, all-local build without a lock, old `runtimes` shell
  pins refused with the migration message, cache re-hash and re-download.
- The fake release source carries the real 0.4.2 asset list (names and digests as GitHub reports them), so the
  default asset names are tested against what the shell actually publishes.
- An optional network test (environment variable, like the other `SHELL_PACKAGER_TEST_*` ones) runs `update`
  against the real `grafida/shell`, once through `gh` and once through curl.
- **Byte-for-byte Linux check** (project rule): pin the fixture's Linux shell and launcher to the 0.4.2 URLs and
  hashes the old way, build the tarball with a fixed `SOURCE_DATE_EPOCH`, then switch the fixture to
  `"shell": {"version": "0.4.2"}` with its lock, rebuild, and compare SHA-256s. They must be equal.
- The fixture's `shell-package.json` moves to `"shell": {"version": "0.4.2"}`, and its `shell-package.lock` is
  committed. The macOS and Windows end-to-end scripts then run against 0.4.2. They are run by hand, as now.

## Other repositories

Order: packager, then shell docs, then Grafida.

- **`grafida/shell` (documentation):** in `docs/user/packaging.md` (and the release notes in `README.md` if they
  cover releases), document how an app pins the shell (`shell.version`, the lock, `update`, `local`). Also
  document the release contract a fork has to keep or override through `shell.assets`: one tag per version,
  semantic versioning, all seven assets in every release, their names, and GitHub's digest being the hash the
  packager uses (the `.sha256` sidecars are for people downloading by hand).
- **`grafida/grafida`:** replace its `runtimes.*.shell`/`launcher` pins with `"shell": {"version": "^0.4.1"}`
  (its current pin is 0.4.1; the operator may prefer an exact version), run `update`, and commit the lock.
  Grafida requires `grafida/shell-packager` at exactly `0.5.1`, so this needs a packager release first. That
  release is the operator's step.

## Calls made by the designer (not discussed with the operator)

- A stale lock fails the build instead of warning (Composer warns).
- A build with no lock writes one and continues, like `composer install`.
- `update` downloads and checks all seven files, not only the host's.
- Pre-release handling follows Composer's minimum-stability rule, not GitHub's `prerelease` flag.
- The lock file name follows the configuration's base name.
- `--local` on the CLI wins over `shell.local`.

## Left to the builder

Class and method names, the exact error wording, how `gh` vs curl is chosen when `gh` is installed but not
logged in (fall back to curl), the README layout, and how many versions an "unsatisfiable constraint" error
lists.
