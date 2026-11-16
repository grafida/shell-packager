# Shell version pinning Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pin the Grafida shell and launcher by a Composer version constraint in a top-level `shell` block. The
constraint is resolved against the repository's GitHub releases into a committed `shell-package.lock`. Then
document it in `grafida/shell` and adopt it in `grafida/grafida`.

**Architecture:** New `src/Shell/` namespace:
- `ShellConfig` parses the block, with `ShellAssets` holding the asset-name defaults.
- A `ReleaseSource` interface lists releases. It has a `gh` implementation and a curl implementation.
- The pure `Resolver` turns config + releases into a `ShellLock`.
- `ShellFiles` decides, per file, whether it comes from a local path or from the lock, writing the lock when there is
  none. It downloads through a new `Fetcher::fetchLocked()`.
- The CLI's build commands ask `ShellFiles` for paths instead of `Config::runtime('shell'|'launcher')`, so the
  packaging classes are unchanged.

**Tech Stack:** PHP ^8.4 (strict types), `composer/semver` ^3.4, ext-curl, PHPUnit 12, PHPStan level max + strict
rules, `gh` CLI (optional).

**Spec:** `docs/superpowers/specs/2026-11-16-shell-version-pinning-design.md`. Read it before any task. This plan
argues from it and doesn't repeat it.

## Global Constraints

- Every PHP file starts with the MIT docblock from `bin/shell-packager` and has `declare(strict_types=1)`.
  Namespace `Grafida\ShellPackager\Shell` for new classes; tests in `tests/`, namespace `Grafida\ShellPackager\Tests`.
- `composer linter:check` (PHPStan max + strict rules) and `composer test` pass after every task.
- `repository` default: `grafida/shell`. Public repositories only. A token (`GH_TOKEN`, then `GITHUB_TOKEN`) is
  used only to list releases. Downloads never carry credentials.
- The fixed file set: Linux `shell` + `launcher` on `x86_64` + `aarch64`, macOS `shell` on `aarch64` + `x86_64`,
  Windows `shell` on `x86_64`. Seven files.
- Default asset names: Linux `grafida-shell-linux-{arch}`, `grafida-launcher-linux-{arch}`. macOS
  `{"aarch64": "grafida-shell-macos-arm64", "x86_64": "grafida-shell-macos-x86_64"}`. Windows
  `grafida-shell-windows-{arch}.exe`.
- Lock: `shell-package.lock` beside the config (the `.json` suffix becomes `.lock`, or `.lock` is appended). The
  format is exactly the spec's: keys in the spec's order, files sorted platform → component → arch,
  `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES`, trailing newline.
- Every shell file from a release is checked against the lock's SHA-256 (from GitHub's `digest`). Every local file
  is announced `UNPINNED` with path and SHA-256 on every use.
- A build from a lock makes no GitHub API call.
- CHANGELOG entries go in the unreleased `shell-packager 0.6.0` section of `CHANGELOG.md`. Each is at most 120
  characters, sorted `!`, `+`, `~`.
- Commits: directly on `main`, ending with `Signed-off-by: Nicholas K. Dionysopoulos <nicholas@akeeba.com>`. Run
  `git commit` with the sandbox disabled, and never with `--no-gpg-sign`.

## Review Focus

1. **`gh` installed but not logged in, or logged in but printing a notice:** listing falls back to curl when `gh auth
   status` fails, and `gh`'s stderr never reaches the JSON parser. Tests in Task 2.
2. **The unauthenticated rate limit:** HTTP 403/429 with `X-RateLimit-Remaining: 0` gives an error telling the user
   to log in to `gh` or set `GH_TOKEN`, not a bare "HTTP 403". Test in Task 2.
3. **A lock edited by hand, or left with merge-conflict markers:** it is refused with the lock's path and the
   reason, never half-read. Test in Task 3.
4. **`v`-prefixed tags:** `1.2.3` resolves to tag `v1.2.3`. The lock records `"version": "1.2.3"` and
   `"tag": "v1.2.3"`, and the cache directory is `1.2.3`. Test in Task 3.
5. **Two builds sharing a cache at once** (the Linux and macOS jobs on one Mac): each download goes to its own
   `.part` file (pid + random suffix) and is renamed into place, so neither sees a half-written file. Test in Task 4.

---

### Task 0: Baseline tarballs at shell 0.4.2 (before any code change)

The project rule requires Linux output to be byte-identical across a refactor. The comparison is done at 0.4.2, so
first pin the fixture's Linux shell and launcher to 0.4.2 the old way.

**Files:**
- Modify: `tests/fixtures/app/shell-package.json` (only `runtimes.linux.shell` and `runtimes.linux.launcher`)

- [ ] **Step 1: Pin the Linux shell and launcher to 0.4.2 with `url` + `sha256`**

URLs: `https://github.com/grafida/shell/releases/download/0.4.2/grafida-shell-linux-{arch}` and
`…/0.4.2/grafida-launcher-linux-{arch}`. Hashes:

| file | x86_64 | aarch64 |
|---|---|---|
| shell | `432b734f15febd3964064f585e2e2998706d24843c1febbc9f11d3be23da9340` | `6ff270aa129e1ee69e920cdd03d1929d15bf9f97012f5973c3fb1a06fd6f2fb9` |
| launcher | `4ad3aa974895c2b9ca3559d47094f87ca616ea458fd7988ec59e57f58df5338e` | `0caa881bff8076e27772b060311099c7d431bd310ca8840108647c257f64983d` |

- [ ] **Step 2: Build both tarballs and record their hashes**

```bash
S=<scratchpad>
for a in x86_64 aarch64; do
  SOURCE_DATE_EPOCH=1700000000 bin/shell-packager linux-tarball --config=tests/fixtures/app/shell-package.json \
    --arch=$a --app-version=1.0.0 --no-check --output="$S/before-$a.tar.gz"
done
shasum -a 256 "$S"/before-*.tar.gz | tee "$S/before.sha256"
```
Expected: two hashes. Both go in this task's commit message, since Task 6 compares against them.

- [ ] **Step 3: Run `composer test` and commit** (`Fixture: Linux shell and launcher pinned to 0.4.2`)

---

### Task 1: `ShellConfig` and `ShellAssets`

**Files:**
- Modify: `composer.json` (`composer require composer/semver:^3.4`)
- Create: `src/Shell/ShellAssets.php`, `src/Shell/ShellConfig.php`
- Modify: `src/Config.php` (new optional `shell` and `path` properties; parse `shell`)
- Test: `tests/ShellConfigTest.php`

**Interfaces:**
- Produces:
  - `ShellAssets::SLOTS`: `array<string, array<string, list<string>>>`, platform value => component => arch values,
    sorted. That is `['linux' => ['launcher' => ['aarch64','x86_64'], 'shell' => ['aarch64','x86_64']],
    'macos' => ['shell' => ['aarch64','x86_64']], 'windows' => ['shell' => ['x86_64']]]`.
  - `ShellAssets::DEFAULTS`: the default templates (Global Constraints).
  - `ShellAssets::expand(array $raw, string $key, bool $partial): array<string, array<string, array<string, string>>>`
    validates a `{platform: {component: string|{arch: string}}}` map against `SLOTS` and expands `{arch}`. With
    `$partial` false, an object value must name every arch of its slot. With `$partial` true (for `local`), any subset
    is allowed.
  - `final readonly class ShellConfig`, with:
    - `string $repository`
    - `string $constraint`
    - `array $assets`: platform => component => arch => asset name, all seven, defaults merged per platform+component
    - `array $local`: platform => component => arch => absolute path; may be sparse
  - `ShellConfig::fromArray(array $data, string $root): self`, `ShellConfig::contentHash(): string`,
    `ShellConfig::asset(Platform, string $component, Arch): string`,
    `ShellConfig::local(Platform, string $component, Arch): ?string`.
  - `Config`: `public ?ShellConfig $shell = null`, `public string $path = ''` (the config file's real path;
    both are new trailing constructor parameters), and `Config::shell(): ShellConfig`, which throws when there is no
    block.

- [ ] **Step 1: Write the failing tests** in `tests/ShellConfigTest.php`:
  - `testDefaultsNameWhatGrafidaShellPublishes`: `ShellConfig::fromArray(['version' => '^0.4'], $root)`.
    `repository` is `grafida/shell`. `asset(Platform::Macos, 'shell', Arch::Aarch64)` is
    `grafida-shell-macos-arm64`, `asset(Platform::Linux, 'launcher', Arch::X86_64)` is
    `grafida-launcher-linux-x86_64`, and `asset(Platform::Windows, 'shell', Arch::X86_64)` is
    `grafida-shell-windows-x86_64.exe`.
  - `testAnOverrideReplacesOnlyItsComponent`: `assets.linux.shell = "foo-{arch}"` gives `foo-aarch64`, and
    `asset(Platform::Linux, 'launcher', Arch::Aarch64)` stays `grafida-launcher-linux-aarch64`.
  - `testUnknownSlotsAreRefused`: `assets.freebsd`, `assets.macos.launcher`, `assets.windows.shell.aarch64`, and an
    object missing `x86_64` for `macos.shell` each throw `PackagerException`, with the message naming the key path.
  - `testLocalIsRelativeToTheConfigAndMayBeSparse`: `local.macos.shell.aarch64 = "bin/worker.php"` (the fixture
    file exists) becomes `realpath(Fixture::APP) . '/bin/worker.php'`. `local(Platform::Linux, 'shell', Arch::X86_64)`
    is `null`. A missing file throws.
  - `testTheContentHashIgnoresLocal`: the same `version` and `assets`, with and without `local`, give equal
    `contentHash()`. Changing `version`, `repository` or one asset name changes it.
  - `testBadValuesAreRefused`: a missing `version`, an unparsable constraint (`"^^1"`), `repository: "nope"`, an asset
    name with `/`, and an unknown key `"url"` each throw.
  - `testConfigReadsTheShellBlock`: `Fixture::config()` with a `shell` block added has a non-null
    `$config->shell`, and `$config->path` ends in `.json`. `Config::shell()` without a block throws, and the message
    mentions `shell.version`. Extend `Fixture::config()` with a `?array $shell = null` parameter that sets
    `$data['shell']` when given.

- [ ] **Step 2:** Run `vendor/bin/phpunit tests/ShellConfigTest.php`. Expected: FAIL (classes missing).
- [ ] **Step 3: Implement.** Validate the constraint with `(new VersionParser())->parseConstraints()`, catching
  `UnexpectedValueException` into `PackagerException`. `repository` must match
  `^[A-Za-z0-9-]+/[A-Za-z0-9._-]+$`. Asset names must match `^[A-Za-z0-9._-]+$` after expansion. `contentHash()` is
  `hash('sha256', json_encode(['repository' => …, 'version' => constraint, 'assets' => sorted assets],
  JSON_UNESCAPED_SLASHES))`.
- [ ] **Step 4:** Run `composer test && composer linter:check`. Expected: PASS.
- [ ] **Step 5: Commit** (`Shell: the shell block, its asset names and local files`)

---

### Task 2: Listing releases (`gh` or the REST API)

**Files:**
- Create: `src/Shell/Release.php`, `src/Shell/ReleaseAsset.php`, `src/Shell/ReleaseSource.php`,
  `src/Shell/GhReleaseSource.php`, `src/Shell/HttpReleaseSource.php`, `src/Shell/ReleaseSources.php`
- Create: `tests/fixtures/releases/grafida-shell-0.4.2.json`, captured with
  `gh api repos/grafida/shell/releases/tags/0.4.2 --jq '{tag_name, draft, assets: [.assets[] | {name, browser_download_url, digest}]}'`
  and wrapped in a JSON array
- Test: `tests/ReleaseSourceTest.php`

**Interfaces:**
- Produces:
  - `final readonly class ReleaseAsset(string $name, string $url, ?string $sha256)`. `$sha256` is the digest without
    `sha256:`, or `null` when GitHub gives none.
  - `final readonly class Release(string $tag, bool $draft, array<string, ReleaseAsset> $assets)` (keyed by name), with
    `Release::fromApi(array $data): self`. It reads `tag_name`, `draft`, `assets[].name`,
    `assets[].browser_download_url` and `assets[].digest`, and throws `PackagerException` on a wrong shape.
  - `interface ReleaseSource { /** @return list<Release> */ public function releases(string $repository): array; public function describe(): string; }`
  - `GhReleaseSource::parse(string $ndjson): list<Release>`. Its `releases()` runs
    `gh api --paginate repos/{repo}/releases?per_page=100 --jq '.[] | {tag_name, draft, assets: [.assets[] | {name, browser_download_url, digest}]}'`
    through `Process::run`, parses stdout only, and on a non-zero exit throws with `gh`'s stderr.
  - `HttpReleaseSource::parse(string $json): list<Release>` and `HttpReleaseSource::nextPage(list<string> $headerLines): ?string`
    (the `rel="next"` URL of `Link`). Also
    `HttpReleaseSource::failure(string $repository, int $status, list<string> $headerLines, string $body): PackagerException`.
    The constructor is `__construct(?string $token)`. `releases()` starts at
    `https://api.github.com/repos/{repo}/releases?per_page=100` and sends `Accept: application/vnd.github+json`,
    `User-Agent: grafida-shell-packager`, and `Authorization: Bearer <token>` only when the token is non-empty.
  - `ReleaseSources::detect(?\Closure $ghUsable = null): ReleaseSource`. The default probe is
    `Process::exists('gh') && Process::run(['gh', 'auth', 'status'])[0] === 0`. The token is
    `getenv('GH_TOKEN')`, then `getenv('GITHUB_TOKEN')`.

- [ ] **Step 1: Write the failing tests:**
  - `testTheReal042ReleaseParses`: `HttpReleaseSource::parse()` of the fixture gives one `Release`, tag `0.4.2`, not
    a draft, with the 7 shell/launcher assets plus their `.sha256` sidecars. Asset `grafida-shell-macos-x86_64` has
    sha256 `cc4dfcfed58f04bc55751384b66220c7f73d29d72b5724642767220ffee1c855` and URL
    `https://github.com/grafida/shell/releases/download/0.4.2/grafida-shell-macos-x86_64`.
  - `testGhOutputIsOneReleasePerLine`: `GhReleaseSource::parse()` of two NDJSON lines (one with `"draft": true`) gives
    two releases, the second a draft. A trailing empty line is ignored.
  - `testAnAssetWithoutADigestHasNone`: `"digest": null` gives `sha256 === null`. `"digest": "md5:…"` throws.
  - `testNextPageFollowsTheLinkHeader`:
    `['link: <https://api.github.com/repositories/1/releases?per_page=100&page=2>; rel="next", <…&page=5>; rel="last"']`
    gives the page-2 URL. With no `Link`, or no `next`, it gives `null`. The header name is case-insensitive.
  - `testRateLimitSaysWhatToDo` (Review Focus 2): `failure('grafida/shell', 403, ['x-ratelimit-remaining: 0'], '{}')`
    mentions `GH_TOKEN` and `gh auth login`. `failure(…, 404, [], '')` mentions the repository and that it must exist
    and be public.
  - `testDetectFallsBackToCurlWhenGhCannotBeUsed` (Review Focus 1): `ReleaseSources::detect(fn () => false)` is a
    `HttpReleaseSource`, and `detect(fn () => true)` is a `GhReleaseSource`.
  - `testTheRealRepository` (network): skipped unless `SHELL_PACKAGER_TEST_GITHUB=1`. Both `new GhReleaseSource()`
    (skipped if `gh` is unusable) and `new HttpReleaseSource(null)` list `grafida/shell`, and the release `0.4.2` equals
    the fixture's assets (names, URLs, sha256).
- [ ] **Step 2:** Run `vendor/bin/phpunit tests/ReleaseSourceTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement.** The HTTP source uses curl with `CURLOPT_HEADERFUNCTION` to collect header lines,
  `CURLOPT_PROTOCOLS` HTTPS only, and the same timeouts as `Fetcher::download`.
- [ ] **Step 4:** Run `composer test && composer linter:check`, and once with `SHELL_PACKAGER_TEST_GITHUB=1`.
  Expected: PASS.
- [ ] **Step 5: Commit** (`Shell: list a repository's releases through gh or the GitHub API`)

---

### Task 3: `Resolver` and `ShellLock`

**Files:**
- Create: `src/Shell/Resolver.php`, `src/Shell/ShellLock.php`, `src/Shell/LockedFile.php`
- Test: `tests/ResolverTest.php`, `tests/ShellLockTest.php`

**Interfaces:**
- Consumes: `ShellConfig`, `ShellAssets::SLOTS`, `Release`, `ReleaseAsset`.
- Produces:
  - `final readonly class LockedFile(string $name, string $url, string $sha256)`.
  - `final readonly class ShellLock`, with:
    - fields `(string $contentHash, string $repository, string $constraint, string $version, string $tag, array $files)`,
      where `$files` is platform => component => arch => `LockedFile`
    - `ShellLock::pathFor(string $configPath): string`
    - `ShellLock::read(string $path): ?self`, which is `null` when the file is absent
    - `ShellLock::fromJson(string $json, string $path): self`
    - `toJson(): string`, `write(string $path): void`
    - `file(Platform, string $component, Arch): LockedFile`
  - `Resolver::minimumStability(string $constraint): string`, one of `dev|alpha|beta|RC|stable`.
  - `Resolver::resolve(ShellConfig $shell, list<Release> $releases): ShellLock`.

- [ ] **Step 1: Write the failing tests:**
  - `ResolverTest::testMinimumStability`: `^0.4` → `stable`, `^0.4@beta` → `beta`, `1.0.0-beta1` → `beta`,
    `>=1.0.0-alpha2 <2` → `alpha`, `^1.0@RC || ^2.0@dev` → `dev`, `~1.2.3` → `stable`.
  - `ResolverTest::testPicksTheHighestStableMatch`: the helper `releases(...)` builds each release with all seven
    default asset names, `file:///x/<name>` URLs and a distinct digest. The tags are `0.4.0`, `0.4.2`, `v0.4.1`,
    `0.4.3-beta1`, `0.5.0`, `nightly`, plus a draft `0.4.9`. `^0.4` → version `0.4.2`. `^0.4@beta` → `0.4.3-beta1`.
    `0.4.1` → version `0.4.1`, tag `v0.4.1` (Review Focus 4). `*` → `0.5.0`. The draft is never picked.
  - `ResolverTest::testTheLockNamesEverySlot`: the `^0.4` result has `files` keys exactly equal to `ShellAssets::SLOTS`,
    each with the asset's name, URL and digest, and `contentHash === $shell->contentHash()`.
  - `ResolverTest::testNothingMatchingIsAnErrorListingWhatThereIs`: `^9` throws, and the message contains `^9`,
    `grafida/shell` and `0.5.0`.
  - `ResolverTest::testAMissingAssetOrDigestIsAnError`: dropping `grafida-shell-windows-x86_64.exe` from `0.4.2` throws,
    naming the tag and the asset. A `null` digest throws the same way. There is no fallback to `0.4.0`.
  - `ShellLockTest::testRoundTripIsByteIdentical`: `fromJson($lock->toJson(), 'x')->toJson() === $lock->toJson()`. The
    text starts with `{\n    "_readme": ` and ends with `}\n`. `pathFor('/a/shell-package.json')` is
    `/a/shell-package.lock`, and `pathFor('/a/pkg')` is `/a/pkg.lock`.
  - `ShellLockTest::testABrokenLockIsRefused` (Review Focus 3): text with `<<<<<<< HEAD`, a lock missing
    `windows.shell.x86_64`, one with an extra `linux.extra`, and one with a non-hex sha256 each throw
    `PackagerException`, the message containing the path given.
- [ ] **Step 2:** Run the two test files. Expected: FAIL.
- [ ] **Step 3: Implement.** Use `VersionParser::normalize()` on each tag in a try/catch, skipping tags that don't
  parse. The lock's `version` is the tag with a leading `v`/`V` removed. Use `VersionParser::parseStability()` per tag
  against the order `stable` > `RC` > `beta` > `alpha` > `dev`, and `Semver::satisfies` + `Semver::rsort` to choose.
  `minimumStability()` takes the least stable of every `@flag`, and of every explicit pre-release version token in the
  constraint (split on `[\s,|]+`, strip the leading `<>=!~^` and any `@flag`, `parseStability()`). The "nothing
  matches" error lists the five highest parsed non-draft versions.
- [ ] **Step 4:** Run `composer test && composer linter:check`. Expected: PASS.
- [ ] **Step 5: Commit** (`Shell: resolve the version constraint into a lock`)

---

### Task 4: `Fetcher::fetchLocked()` and `ShellFiles`

**Files:**
- Modify: `src/Fetcher.php`
- Create: `src/Shell/ShellFiles.php`
- Test: `tests/ShellFilesTest.php`, `tests/FetcherTest.php`

**Interfaces:**
- Consumes: `ShellConfig`, `ShellLock`, `LockedFile`, `Resolver`, `ReleaseSource`, `Config::$shell`,
  `Config::$path`.
- Produces:
  - `Fetcher::fetchLocked(LockedFile $file, string $repository, string $version): string`. The cache path is
    `<cacheDir>/shell/<owner>/<repo>/<version>/<name>`. It re-hashes on every use. A mismatch deletes the file and
    downloads again, and a second mismatch throws with the URL, expected and actual hash.
  - `Fetcher::local(string $runtime): string`, the `--local` path, announced; `fetch()`'s local branch uses it.
  - `Fetcher::unpinned(string $name, string $path): string` (static), which writes the existing `UNPINNED` warning
    and returns `$path`.
  - `Fetcher::download()` and `fetchLocked()` write to `<target>.<pid>-<8 hex>.part` and `rename()`.
  - `final class ShellFiles`, `__construct(Config $config, Fetcher $fetcher, \Closure $source, \Closure $say)`. The
    `$source` closure returns a `ReleaseSource` and is called only when resolving. `$say` takes a string and returns
    nothing.
  - `ShellFiles::path(Platform $platform, string $component, Arch $arch): string`.
  - `ShellFiles::update(): ShellLock`.

- [ ] **Step 1: Write the failing tests** in `tests/ShellFilesTest.php`. Copy the fixture's config into a temp
  directory with `bin/` and the manifest. A fake `ReleaseSource` counts its calls and serves one release, `0.4.2`,
  whose seven assets are `file://` files in the temp directory with their real hashes. Tests:
  - `testNoLockResolvesWritesTheLockAndContinues`: `path(Platform::Linux, 'shell', Arch::X86_64)` returns a cache
    path with the right bytes. `shell-package.lock` now exists, and the source was called once. `$say` received a
    line containing `commit it`.
  - `testABuildFromTheLockMakesNoApiCall`: with the lock written, a fresh `ShellFiles` whose source closure throws
    still returns all seven paths.
  - `testAStaleLockFails`: after the config's `version` changes to `^0.5`, `path()` throws a message containing
    `shell-packager update`.
  - `testLocalFilesNeedNoLock`: `local.linux.shell` and `local.linux.launcher` (both arches) set, no lock, and a source
    that throws. The four Linux paths are the local files, and no lock is created. A macOS `path()` in the same setup
    resolves and writes the lock.
  - `testCliLocalWinsOverConfigLocal`: `$fetcher->useLocal('shell', $a)` with `local.linux.shell = $b` gives `$a`.
  - `testUpdateDownloadsAllSevenAndChecksTheDigest`: `update()` caches all seven. One asset served with a wrong digest
    makes `update()` throw naming that asset, and the lock is not written.
  - `testUpdateRewritesAnUnchangedLockByteForByte`: `update()` twice gives an identical file, and the second run's
    `$say` reports `unchanged`.
  - `FetcherTest::testALockedFileIsCachedByVersionAndRehashed`: the path is
    `<cache>/shell/grafida/shell/0.4.2/<name>`. A tampered cache file is downloaded again. A source with the wrong
    bytes throws.
  - `FetcherTest::testDownloadsGoThroughAUniquePartFile` (Review Focus 5): after a `fetchLocked()` the cache
    directory holds no `*.part`, and two `Fetcher` instances fetching the same file in turn, with a stale
    `<name>.part` left in place, both succeed and leave it untouched.
- [ ] **Step 2:** Run the test files. Expected: FAIL.
- [ ] **Step 3: Implement.** For `path()`, check sources in order:
  1. `$fetcher->hasLocal($component)`: return `$fetcher->local()`.
  2. `$shell->local(...)`: return `Fetcher::unpinned("{$component} ({$platform->value} {$arch->value})", $path)`.
  3. The lock, loaded once and checked against `contentHash()`. With no lock, call `update()` and say
     `wrote <path>: commit it`.

  Then call `fetchLocked()`. `update()` calls `$source()`, then `releases()` and `Resolver::resolve()`, then
  `fetchLocked()` for all seven files, and only then writes the lock. It says `shell <old> → <new>` or
  `shell <version> (unchanged)`, then one `name sha256` line per file.
- [ ] **Step 4:** Run `composer test && composer linter:check`. Expected: PASS.
- [ ] **Step 5: Commit** (`Shell: files from the lock or local paths, and update`)

---

### Task 5: Wire the CLI; refuse the old pins; move the fixture to `shell.version`

**Files:**
- Modify: `src/Cli.php`, `src/Config.php`, `src/Runtime.php`, `tests/fixtures/app/shell-package.json`,
  `tests/ConfigTest.php`, `tests/RuntimeTest.php`
- Create: `tests/fixtures/app/shell-package.lock` (generated, committed)
- Test: `tests/ConfigTest.php`, `tests/RuntimeTest.php`, `tests/CliTest.php` (new)

**Interfaces:**
- Consumes: `ShellFiles`, `ReleaseSources::detect()`.
- Produces: the CLI command `shell-packager update --config=FILE`. `fetch` prints `shell: PATH` (and on Linux
  `launcher: PATH`) as before, because CI greps those lines.

- [ ] **Step 1: Write the failing tests:**
  - `ConfigTest::testOldShellPinsAreRefusedWithTheWayOut`: `Fixture::config(['linux' => ['shell' => ['url' => 'https://x/{arch}', 'sha256' => []]]])`
    throws, and the message contains `runtimes.linux.shell`, `"shell": {"version"` and `shell-packager update`. The
    same holds for `launcher` under `macos`, and for the flat (ungrouped) form.
  - `ConfigTest::testAMissingRuntimeIsNamed`: change it to `runtime('sfx')` with the message
    `pins no linux 'sfx' runtime`.
  - `RuntimeTest`: `latestUrls('shell', …)` and `latestUrls('launcher', 'linux')` now throw. Update
    `testLatestNamesTheNewestReleasePerPlatformAndArchitecture` and `testLatestWorksInAConfiguredRuntimesSection` to
    use `sfx` and `appimage-runtime` only.
  - `CliTest::testUpdateNeedsAConfig`: `Cli::main(['x', 'update'])` returns 1.
- [ ] **Step 2:** Run them. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - `Config::runtimes()` throws for `shell`/`launcher` with the migration message.
  - `Config::load()` passes `path: $real`.
  - `Runtime::latestUrls()` drops the shell and launcher entries.
  - The docblocks of `Runtime` (the "never consulted" sentence now covers only `url` + `sha256` runtimes) and
    `Config` (the example shows the `shell` block; `runtimes` keeps `sfx` and `appimage-runtime`) are rewritten.
  - In `Cli`:
    - Add `private static function shellFiles(Config $config, Fetcher $fetcher): ShellFiles`, with the source
      `static fn (): ReleaseSource => ReleaseSources::detect()` and `self::say(...)`.
    - `linuxTarball`, `linuxPackage`, `linuxAppImage`, `buildApp`, `buildTree` and `fetch` take `shell`/`launcher`
      from it, and `sfx` (and `appimage-runtime`) from `$config->runtime()` as now.
    - Add the `update` command, and update `USAGE`: the command, `--local` naming the `shell` block's `local`, and
      the cache sentence.
- [ ] **Step 4: Move the fixture.** In `tests/fixtures/app/shell-package.json`, delete every `runtimes.*.shell` and
  `runtimes.*.launcher` and add `"shell": {"version": "0.4.2"}`. Then run
  `bin/shell-packager update --config=tests/fixtures/app/shell-package.json` (network). Expected: seven lines whose
  hashes equal Task 0's table and the macOS/Windows hashes in Task 2's fixture.
- [ ] **Step 5:** Run `composer test && composer linter:check && shellcheck scripts/*.sh resources/linux/*.sh`.
  Expected: PASS.
- [ ] **Step 6: Commit** (`Pin the shell by version: shell-packager update and shell-package.lock`), with the lock.
  From now on, CI's macOS and Windows jobs fetch the 0.4.2 binaries through the lock, and `MachOTest` checks that
  the macOS ones end at their last segment. Report a CI failure there to the operator rather than working around it.

---

### Task 6: Byte-for-byte check, docs, CHANGELOG

**Files:**
- Modify: `AGENTS.md`, `README.md` (only if it names the pins), `CHANGELOG.md`

- [ ] **Step 1: Rebuild both tarballs** with the Task 0 command (output `after-$a.tar.gz`). Run
  `shasum -a 256 "$S"/after-*.tar.gz`. Expected: the same hashes as `before.sha256`. If they differ, stop and find out
  why. Do not continue.
- [ ] **Step 2: Rewrite `AGENTS.md`'s rules:**
  - The SHA-256 rule: the shell and launcher are pinned by `shell.version`, resolved into `shell-package.lock` from
    GitHub's digests, public repositories only, and builds from the lock make no API call. Other runtimes keep
    `url` + `sha256` or `{"latest": true}`.
  - The fixture paragraph: the shell comes from `shell.version` + the lock, and the SFX keeps real pins.
  - The `runtimes` paragraph: shell and launcher are no longer there.
- [ ] **Step 3: Add the CHANGELOG lines** to the 0.6.0 section, placed by prefix among the existing entries (`!`
  first, then `+`, then `~`):

```text
! The shell and launcher are pinned by version (`shell.version`, Composer syntax) in a committed `shell-package.lock`
+ `shell-packager update` resolves `shell.version` against the GitHub releases and rewrites the lock
+ `shell.local` lets local files stand in for any shell or launcher file, announced as UNPINNED
+ `shell.repository` and `shell.assets` for a fork of the shell that publishes elsewhere or under other names
~ `runtimes.*.shell` and `runtimes.*.launcher` pins are refused, with how to move to the `shell` block
```
- [ ] **Step 4:** Run `composer test && composer linter:check`. Expected: PASS.
- [ ] **Step 5: Commit** (`AGENTS.md, CHANGELOG: the shell is pinned by version`), with the before/after hashes in
  the message.

---

### Task 7: `grafida/shell` documentation

Repository: `../shell`. Read its `AGENTS.md` first and follow it (CHANGELOG policy, commit style).

**Files:**
- Modify: `../shell/docs/user/packaging.md` (and any other file under `docs/user/` that shows
  `runtimes.*.shell`/`launcher` pins: `grep -rn 'grafida-shell-\|runtimes' docs/user README.md`)

- [ ] **Step 1:** Replace the shell/launcher pinning documentation with:
  - `shell.version` (Composer constraint, link to <https://getcomposer.org/doc/articles/versions.md>, stability rule)
  - `shell.repository` (public repositories only)
  - `shell.assets` with the default names
  - `shell.local`
  - `shell-package.lock` (commit it; a stale lock fails the build; no lock → written on the first build)
  - `shell-packager update`
  - `--local`
  - the removal of `runtimes.*.shell`/`launcher` and `{"latest": true}` for them
- [ ] **Step 2:** Add a "Release contract" section. One tag per version, semantic versioning, every release
  carries all seven assets under those names, and GitHub's digest is the hash the packager uses (the `.sha256`
  sidecars are for manual downloads). Check the names against what the repository's release script uploads
  (`grep -rn 'grafida-shell-\|grafida-launcher-' scripts`).
- [ ] **Step 3: Commit** in `../shell`.

---

### Task 8: `grafida/grafida` adopts `shell.version`

Repository: `../grafida`. Read its `AGENTS.md` first. **Blocked until the operator has released the packager
version containing Tasks 1–6.** Ask the operator for the version, and do not tag it yourself.

**Files:**
- Modify: `../grafida/composer.json` (`grafida/shell-packager` to the released version), `../grafida/shell-package.json`
- Create: `../grafida/shell-package.lock`
- Modify: any Grafida doc that describes the shell pins (`grep -rn 'grafida-shell-\|runtimes' AGENTS.md build/readme docs`)

- [ ] **Step 1:** Run `composer update grafida/shell-packager` after bumping the requirement.
- [ ] **Step 2:** Replace `runtimes.{linux,macos,windows}.shell` and `runtimes.linux.launcher` with
  `"shell": {"version": "^0.4.1"}`, then run `vendor/bin/shell-packager update --config=shell-package.json`.
  Expected: a lock at the newest 0.4.x.
- [ ] **Step 3:** Run `vendor/bin/shell-packager fetch --config=shell-package.json --arch=aarch64 --platform=macos`
  and the same for `--platform=linux` and `--platform=windows --arch=x86_64`. Expected: `shell:` lines from the
  cache, no API call.
- [ ] **Step 4:** Run Grafida's `composer test`. Expected: PASS.
- [ ] **Step 5: Commit** in `../grafida`. This is a build change, so it gets no CHANGELOG entry.
