<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Linux;

use Grafida\ShellPackager\PackagerException;

/**
 * `linux.package`: what a `.deb` or `.rpm` says about the app that the shell manifest does not.
 *
 * ```jsonc
 * "package": {
 *   "maintainer": "Jane Doe <jane@example.com>",   // required
 *   "license": "GPL-3.0-or-later",                 // required
 *   "homepage": "https://example.com",
 *   "description": "One paragraph.",               // default: the manifest title
 *   "section": "utils",                            // .deb only; default utils
 *   "depends": {"deb": ["xdg-utils"], "rpm": ["xdg-utils"]},  // in addition to the shell's own
 *   "output": {"deb": "build/dist/{name}-{version}-linux-{debarch}.deb", "rpm": "…-{arch}.rpm"}
 * }
 * ```
 *
 * The system libraries the shell needs (WebKitGTK 4.1, GTK 3) are the shell's, not the app's, so
 * they are the packager's defaults ({@see LinuxPackage::SHELL_DEPENDS}); `depends` only adds.
 */
final readonly class PackageConfig
{
    /**
     * @param array{deb: list<string>, rpm: list<string>}  $depends
     * @param array{deb: string, rpm: string}              $output
     */
    public function __construct(
        public string $maintainer,
        public string $license,
        public ?string $homepage,
        public ?string $description,
        public string $section,
        public array $depends,
        public array $output,
    ) {
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $text = static function (string $key, bool $required) use ($data): ?string {
            $value = $data[$key] ?? null;

            if ($value === null && !$required) {
                return null;
            }

            if (!\is_string($value) || trim($value) === '') {
                throw new PackagerException("linux.package.{$key} " . ($required ? 'is required and' : '') . ' must be a non-empty string.');
            }

            return $value;
        };

        $depends = ['deb' => [], 'rpm' => []];
        $raw     = $data['depends'] ?? [];

        if (!\is_array($raw) || array_diff(array_keys($raw), ['deb', 'rpm']) !== []) {
            throw new PackagerException('linux.package.depends is an object with the members deb and rpm, each a list of package names.');
        }

        foreach (['deb', 'rpm'] as $format) {
            $names = $raw[$format] ?? [];

            if (!\is_array($names) || !array_is_list($names)) {
                throw new PackagerException("linux.package.depends.{$format} is a list of package names.");
            }

            foreach ($names as $name) {
                if (!\is_string($name) || preg_match('/^[A-Za-z0-9][A-Za-z0-9.+_:()\/ <>=~-]*$/', $name) !== 1) {
                    throw new PackagerException("linux.package.depends.{$format} lists package names; got " . json_encode($name) . '.');
                }

                $depends[$format][] = $name;
            }
        }

        $deb    = 'build/dist/{name}-{version}-linux-{debarch}.deb';
        $rpm    = 'build/dist/{name}-{version}-linux-{arch}.rpm';
        $given  = $data['output'] ?? [];

        if (!\is_array($given) || array_diff(array_keys($given), ['deb', 'rpm']) !== []) {
            throw new PackagerException('linux.package.output is an object with the members deb and rpm, each a path template.');
        }

        foreach ($given as $format => $path) {
            if (!\is_string($path) || $path === '') {
                throw new PackagerException("linux.package.output.{$format} must be a path.");
            }

            if ($format === 'deb') {
                $deb = $path;
            } else {
                $rpm = $path;
            }
        }

        $output = ['deb' => $deb, 'rpm' => $rpm];

        $maintainer = $text('maintainer', true);
        $license    = $text('license', true);
        $section    = $text('section', false) ?? 'utils';

        \assert($maintainer !== null && $license !== null);

        return new self($maintainer, $license, $text('homepage', false), $text('description', false), $section, $depends, $output);
    }
}
