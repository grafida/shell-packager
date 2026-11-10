<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Macos;

/**
 * The `macos` section of an app's packaging configuration (grafida/grafida#88). Paths are absolute
 * (resolved against the configuration's directory by {@see \Grafida\ShellPackager\Config}).
 *
 * ```jsonc
 * "macos": {
 *   "name": "Grafida",                         // Grafida.app, Contents/MacOS/Grafida (default: the manifest title)
 *   "icon": "assets/logo/Grafida.icns",
 *   "infoPlist": {"NSAppTransportSecurity": {"NSAllowsArbitraryLoadsInWebContent": true}},
 *   "entitlements": {"shell": "build/macos/shell.entitlements", "worker": "…"},   // absent = none
 *   "resources": {"LICENSE.txt": "LICENSE.txt"},                                   // into Contents/Resources
 *   "dmg": {
 *     "background": "assets/logo/dmg-background.tiff",
 *     "window": [640, 400], "iconSize": 128, "textSize": 13,
 *     "positions": {"app": [160, 210], "applications": [480, 210]},
 *     "volumeIcon": "assets/logo/Grafida.icns", "volumeName": "Grafida"
 *   },
 *   "app": "build/shell-macos/{name}.app",
 *   "output": "build/dist/{name}-{version}-macos-{debarch}.dmg"
 * }
 * ```
 */
final readonly class MacosConfig
{
    /**
     * @param array<string, mixed>                 $infoPlist   extra Info.plist keys
     * @param array{shell: ?string, worker: ?string} $entitlements
     * @param array<string, string>                $resources   name in Contents/Resources => source
     * @param array{int, int}                      $window      the DMG window's width and height
     * @param array{int, int}                      $appPosition
     * @param array{int, int}                      $applicationsPosition
     */
    public function __construct(
        public string $name,
        public ?string $icon,
        public array $infoPlist,
        public array $entitlements,
        public array $resources,
        public ?string $dmgBackground,
        public array $window,
        public int $iconSize,
        public int $textSize,
        public array $appPosition,
        public array $applicationsPosition,
        public ?string $volumeIcon,
        public string $volumeName,
        public string $appOutput,
        public string $dmgOutput,
    ) {
    }
}
