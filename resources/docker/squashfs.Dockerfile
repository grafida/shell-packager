# The image `linux-appimage` runs mksquashfs in when the build host has none (linux.appimage.squashfs
# "docker", or "auto" without a host mksquashfs; grafida/shell-packager#1). The packager builds it
# locally, once, from this file, and tags it with this file's SHA-256 (Linux\Squashfs::localImage()),
# so any change here makes a new image. Nothing is published.
#
# Pinned: the base by its multi-arch index digest (alpine:3.24), and the two packages that decide the
# squashfs bytes by exact version (apk checks them against the signed index of that base). They are
# the versions Homebrew had when this was written, so a Mac with `brew install squashfs` and this
# image make the same AppImage. When Alpine replaces either version, `docker build` fails here:
# update the versions (or the base) together.
FROM alpine:3.24@sha256:f59abe656b692d901e184be3445e084786602108599d2a51d229c0f5a48c71bf
RUN apk add --no-cache squashfs-tools=4.7.5-r0 zstd-libs=1.5.7-r2
