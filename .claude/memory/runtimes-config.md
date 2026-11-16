# The `runtimes` configuration

Read before changing how `runtimes`, `shell` or `launcher` entries are parsed or validated.

- **`runtimes` is grouped by platform** (`linux`, `macos`, `windows`); a flat one is Linux's, and a
  mix is an error. A runtime's `url` is one template (`{arch}`) or an object of one URL per architecture, for pins of
  one platform verified on different releases (the macOS SFX). `shell` and `launcher` are no longer runtimes: an entry
  there is refused, with how to move to the `shell` block.
