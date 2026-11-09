# grafida/shell-packager

Build and packaging tooling for applications built on the
[Grafida desktop shell](https://github.com/grafida/shell), extracted from and parameterised out of
[Grafida](https://github.com/grafida/grafida)'s own build scripts.

> **Status: skeleton.** Nothing here is usable yet. The design is tracked in
> [grafida/grafida#82](https://github.com/grafida/grafida/issues/82); the tooling is filled in by
> platform in Phases 3–5.

Planned contents (Composer dev package, `bin/` scripts, plus reusable GitHub Actions workflows):

- a PHAR builder, including the INI block the phpmicro SFX reads
- fetch-and-verify (SHA-256) for the prebuilt shell binaries and the
  [phpmicro](https://github.com/grafida/phpmicro) `sibling-phar` worker runtime
- macOS: bundle → sign → notarise → staple → DMG
- Linux: tarball + installer
- Windows: NSIS installer + Jsign / Azure Trusted Signing

It must not depend on any one application's layout: everything app-specific comes from the
consumer's manifest and options.

## Licence

MIT — see [LICENSE](LICENSE).
