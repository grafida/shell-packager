<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Macos;

use Grafida\ShellPackager\Config;
use Grafida\ShellPackager\PackagerException;

/**
 * The bundle's `Info.plist` (grafida/grafida#88). The packager computes every key the shell and the
 * package must agree on, from the manifest (`id`, `title`), the app's version and the shell's
 * macOS floor; the app adds anything else as `macos.infoPlist` (ATS, the copyright, the category,
 * …). A computed key among the extras is an error: the app cannot, say, give the bundle an
 * identifier its manifest does not have.
 */
final class InfoPlist
{
    /** The shell's floor (ADR 0001): the release binary is linked for it (`check-macos-binaries.sh`). */
    public const MINIMUM_SYSTEM_VERSION = '12.0';

    /** @return array<string, mixed> */
    public static function build(Config $config, string $version, bool $hasIcon): array
    {
        if (preg_match('/^\d+(\.\d+){0,2}$/', $version) !== 1) {
            throw new PackagerException(
                "--app-version '{$version}': macOS needs one to three dot-separated integers (CFBundleShortVersionString)."
            );
        }

        $macos    = $config->macos();
        $computed = [
            'CFBundleIdentifier'            => $config->appId,
            'CFBundleName'                  => self::bundleName($config->title),
            'CFBundleDisplayName'           => $config->title,
            'CFBundleExecutable'            => $macos->name,
            'CFBundleVersion'               => $version,
            'CFBundleShortVersionString'    => $version,
            'CFBundlePackageType'           => 'APPL',
            'CFBundleInfoDictionaryVersion' => '6.0',
            'LSMinimumSystemVersion'        => self::MINIMUM_SYSTEM_VERSION,
            'NSHighResolutionCapable'       => true,
        ];

        if ($hasIcon) {
            $computed['CFBundleIconFile'] = $macos->name;
        }

        $clash = array_intersect_key($macos->infoPlist, $computed + ['CFBundleIconFile' => true]);

        if ($clash !== []) {
            throw new PackagerException(
                'macos.infoPlist sets ' . implode(', ', array_keys($clash))
                . ', which the packager computes (from the manifest, --app-version, macos.name/icon and the shell\'s floor).'
            );
        }

        $plist = $computed + $macos->infoPlist;
        ksort($plist);

        return $plist;
    }

    /** CFBundleName is limited to 15 characters by Apple's guidelines; the display name is not. */
    private static function bundleName(string $title): string
    {
        return preg_match('/^.{0,15}/u', $title, $m) === 1 ? $m[0] : $title;
    }

    /** @param array<string, mixed> $plist */
    public static function xml(array $plist): string
    {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<!DOCTYPE plist PUBLIC \"-//Apple//DTD PLIST 1.0//EN\" \"http://www.apple.com/DTDs/PropertyList-1.0.dtd\">\n"
            . "<plist version=\"1.0\">\n" . self::value($plist, '', 'Info.plist') . "</plist>\n";
    }

    private static function value(mixed $value, string $indent, string $where): string
    {
        if (\is_array($value)) {
            // JSON's {} and [] both decode to an empty array: an empty one is a dict.
            return $value !== [] && array_is_list($value) ? self::array($value, $indent, $where) : self::dict($value, $indent, $where);
        }

        return match (true) {
            \is_bool($value)   => $indent . ($value ? '<true/>' : '<false/>') . "\n",
            \is_int($value)    => "{$indent}<integer>{$value}</integer>\n",
            \is_float($value)  => "{$indent}<real>" . var_export($value, true) . "</real>\n",
            \is_string($value) => "{$indent}<string>" . self::escape($value) . "</string>\n",
            default            => throw new PackagerException("{$where}: a plist value must be a string, number, boolean, object or list."),
        };
    }

    /** @param array<mixed> $dict */
    private static function dict(array $dict, string $indent, string $where): string
    {
        $out = "{$indent}<dict>\n";

        foreach ($dict as $key => $item) {
            $out .= "{$indent}    <key>" . self::escape((string) $key) . "</key>\n" . self::value($item, $indent . '    ', "{$where}.{$key}");
        }

        return $out . "{$indent}</dict>\n";
    }

    /** @param list<mixed> $list */
    private static function array(array $list, string $indent, string $where): string
    {
        $out = "{$indent}<array>\n";

        foreach ($list as $i => $item) {
            $out .= self::value($item, $indent . '    ', "{$where}[{$i}]");
        }

        return $out . "{$indent}</array>\n";
    }

    private static function escape(string $text): string
    {
        if (preg_match('//u', $text) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text) === 1) {
            throw new PackagerException('An Info.plist string must be valid UTF-8 without control characters.');
        }

        return htmlspecialchars($text, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
    }
}
