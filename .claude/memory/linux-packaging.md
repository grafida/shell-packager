# Linux packaging (AppImage, squashfs, PKGBUILD, deb/rpm)

Read before touching `src/Linux/`, the AppImage assembly, `mksquashfs`/Docker handling, nfpm, `linux.replaces` or the PKGBUILD template.

- **The AppImage (`Linux\AppImage`) is `runtime ++ squashfs`, assembled in PHP with `mksquashfs`**,
  not appimagetool (a Linux ELF; the packages are built on a Mac). The launcher is `AppRun`; the desktop entry and
  largest icon sit at the AppDir root. Reproducibility depends on `env -u SOURCE_DATE_EPOCH mksquashfs … -mkfs-time/-all-time/-root-time
  <mtime> -all-root -no-xattrs`: mksquashfs refuses explicit times together with that variable (`-repro-time` is 4.6+, absent from Ubuntu 22.04's 4.5). Update information is
  written into the runtime's `.upd_info` section, located through the ELF section headers, never by offset. The
  pinned runtime's compressors are zstd and zlib (checked in its binary): do not switch to xz. WebKitGTK is never
  bundled. `AppImageTest` checks the structure with `unsquashfs -o <runtime size>`; `SHELL_PACKAGER_TEST_APPIMAGE_RUNTIME`
  adds a run with the real runtime. A real mount/run needs a Linux machine with FUSE; `--appimage-extract-and-run` in an
  `ubuntu:22.04` container (arm64 natively on the Mac) runs the real runtime and the launcher without one.
- **`Linux\Squashfs` (grafida/shell-packager#1): `mksquashfs` on the host, or in Docker** (`linux.appimage.squashfs`
  `auto`/`host`/`docker`). The AppDir is described as `AppDirEntry`s and fed to mksquashfs **4.6+ as a plain tar on
  stdin** (`-tar`), from `TarWriter` with fixed modes: a staged directory leaked the host (a symlink is 0755 on macOS,
  0777 on Linux; NTFS has no modes). Only a host mksquashfs < 4.6 still gets a directory. Docker: no bind mount
  (the tar goes in on stdin, the image out on stdout), `--network none`, and the image is built locally from
  `resources/docker/squashfs.Dockerfile` (alpine by index digest, `squashfs-tools` and `zstd-libs` at exact versions,
  tagged by the file's hash) or is `linux.appimage.docker.image`, which must be `@sha256:`. Keep the Dockerfile's
  versions equal to Homebrew's: then host and Docker give the same bytes (`SHELL_PACKAGER_TEST_DOCKER=1` checks it).
  `auto` treats a `docker` whose daemon does not answer as absent, with its own message.
- **`Linux\Pkgbuild` fills a text template** (hashes computed from the built tarballs, shell-quoted values, `pkgver`
  with `-` turned into `_`); `options=('!strip' …)` is load-bearing (stripping rewrites the pinned binaries).
  Validate a change with `makepkg`/`namcap` in an `archlinux` container (`DisableSandbox` in pacman.conf under
  emulation, a non-root user for makepkg).
- **`.deb`/`.rpm` (`src/Linux/LinuxPackage.php`, grafida/grafida#87) come from a pinned nfpm** (`Linux\Nfpm`: tag 2.49.0, one
  archive per build host, SHA-256 of the downloaded bytes, never typed from the release page; `fetch --nfpm`
  proves it), with the tarball's file list under `/opt/<linux.name>` and `/usr/bin/<name>` an absolute symlink to
  the launcher. nfpm's config is JSON (valid YAML) built as data, and modes are **integers** (a string mode does not
  unmarshal into `fs.FileMode`); a `$` anywhere is refused because nfpm expands env vars in the whole file. The shell's
  system libraries (deb `libwebkit2gtk-4.1-0` + `libgtk-3-0`, rpm `webkit2gtk4.1` + `gtk3`) are packager defaults
  in `LinuxPackage::SHELL_DEPENDS`; `linux.package.depends` only adds. The maintainer scripts never touch a home
  directory; post-removal deletes `/opt/<name>` on a final removal only. The tests read the packages back with
  `ar`/`tar`/`rpm` when `SHELL_PACKAGER_TEST_NFPM` names an nfpm (CI sets it from `fetch --nfpm`).
- **`linux.replaces`** (grafida/grafida#92) lets `install.sh` remove an earlier build's desktop
  entry and hicolor icons under another id, only when its main `Exec` (field codes dropped, quotes
  removed; an older installer wrote an unquoted path with spaces) ends in `/<linux.name>`. The ids are
  validated as desktop ids so the script can glob them; the app's own id is refused.
