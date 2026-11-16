<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Shell;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Grafida\ShellPackager\PackagerException;

/**
 * Resolves `shell.version` against a repository's releases, as Composer would: the highest
 * version that satisfies the constraint and is at least as stable as the constraint allows.
 *
 * composer/semver matches constraints only; it does not filter by stability (`^0.4` matches
 * `0.4.3-beta1`), which Composer does separately. So does this class: the minimum stability is
 * `stable`, lowered by a `@flag` on the constraint or by a pre-release version written in it.
 * A tag that is not a version is ignored, a draft is never used, and GitHub's own pre-release
 * flag is not consulted: the version decides.
 */
final class Resolver
{
    /** Composer's order (BasePackage::STABILITIES): the higher, the less stable. */
    private const array ORDER = ['stable' => 0, 'RC' => 5, 'beta' => 10, 'alpha' => 15, 'dev' => 20];

    public static function minimumStability(string $constraint): string
    {
        $least = 'stable';

        $tokens = preg_split('/[\s,|]+/', trim($constraint));

        foreach ($tokens === false ? [] : $tokens as $token) {
            $stability = 'stable';

            if (preg_match('/^(.*)@(stable|rc|beta|alpha|dev)$/i', $token, $m) === 1) {
                $stability = self::name($m[2]);
                $token     = $m[1];
            }

            $token = ltrim($token, '<>=!~^');

            if ($token !== '') {
                $explicit = VersionParser::parseStability($token);

                if (self::ORDER[$explicit] > self::ORDER[$stability]) {
                    $stability = $explicit;
                }
            }

            if (self::ORDER[$stability] > self::ORDER[$least]) {
                $least = $stability;
            }
        }

        return $least;
    }

    /** @param list<Release> $releases */
    public static function resolve(ShellConfig $shell, array $releases): ShellLock
    {
        $parser    = new VersionParser();
        $minimum   = self::minimumStability($shell->constraint);
        $versions  = [];
        $matching  = [];

        foreach ($releases as $release) {
            if ($release->draft) {
                continue;
            }

            $version = self::versionOf($release->tag);

            try {
                $parser->normalize($version);
            } catch (\UnexpectedValueException) {
                continue;
            }

            $versions[$version] ??= $release;

            if (self::allows($shell->constraint, $version)) {
                $matching[$version] ??= $release;
            }
        }

        if ($matching === []) {
            $newest = \array_slice(Semver::rsort(array_map('strval', array_keys($versions))), 0, 5);

            throw new PackagerException(
                "No release of {$shell->repository} satisfies shell.version '{$shell->constraint}' (minimum stability {$minimum}). "
                . ($newest === [] ? 'It has no release tagged with a version.' : 'Its newest: ' . implode(', ', $newest) . '.')
            );
        }

        $version = Semver::rsort(array_map('strval', array_keys($matching)))[0];
        $release = $matching[$version];
        $files   = [];

        foreach ($shell->assets as $platform => $components) {
            foreach ($components as $component => $byArch) {
                foreach ($byArch as $arch => $name) {
                    $asset = $release->assets[$name]
                        ?? throw new PackagerException("Release {$release->tag} of {$shell->repository} has no asset {$name} (the {$platform} {$component} for {$arch}).");

                    if ($asset->sha256 === null) {
                        throw new PackagerException("Asset {$name} of release {$release->tag} of {$shell->repository} has no digest on GitHub, so it cannot be verified.");
                    }

                    $files[$platform][$component][$arch] = new LockedFile($name, $asset->url, $asset->sha256);
                }
            }
        }

        return new ShellLock($shell->contentHash(), $shell->repository, $shell->constraint, $version, $release->tag, $files);
    }

    /**
     * Whether $constraint takes $version: it satisfies the constraint and is at least as stable
     * as the constraint allows. The one test for both resolving and a lock read back.
     */
    public static function allows(string $constraint, string $version): bool
    {
        try {
            $stability = VersionParser::parseStability($version);

            return self::ORDER[$stability] <= self::ORDER[self::minimumStability($constraint)] && Semver::satisfies($version, $constraint);
        } catch (\UnexpectedValueException) {
            return false;
        }
    }

    /** The version a release tag stands for: the tag without a leading `v`. */
    public static function versionOf(string $tag): string
    {
        return (string) preg_replace('/^[vV]/', '', $tag);
    }

    /** @return 'stable'|'RC'|'beta'|'alpha'|'dev' */
    private static function name(string $flag): string
    {
        return match (strtolower($flag)) {
            'rc'    => 'RC',
            'beta'  => 'beta',
            'alpha' => 'alpha',
            'dev'   => 'dev',
            default => 'stable',
        };
    }
}
