<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Windows;

/**
 * The `windows` section of an app's packaging configuration (grafida/grafida#91). Paths are
 * absolute (resolved against the configuration's directory by {@see \Grafida\ShellPackager\Config}).
 *
 * ```jsonc
 * "windows": {
 *   "name": "Grafida",                    // <name>.exe, the tree's folder (default: the manifest title)
 *   "icon": "assets/logo/Grafida.ico",    // into both executables, and beside them as <name>.ico
 *   "version": {                          // the version resource of both executables
 *     "companyName": "…", "legalCopyright": "…",
 *     "productName": "Grafida",           // default: name
 *     "shellDescription": "Grafida",      // FileDescription: what Task Manager calls the process (default: name)
 *     "workerDescription": "Grafida worker"   // default: "<name> worker"
 *   },
 *   "files": {"LICENSE.txt": "LICENSE.txt"},                  // into the tree
 *   "installer": {
 *     "script": "build/windows/shell-installer.nsi",          // the app's own NSIS script
 *     "defines": {"PUBLISHER": "…"}                           // passed to it as -D, besides the documented ones
 *   },
 *   "tree": "build/shell-windows/{name}",
 *   "output": "build/dist/{name}-{version}-windows-{debarch}-Setup.exe"
 * }
 * ```
 */
final readonly class WindowsConfig
{
    /**
     * @param array<string, string> $files   name in the tree => source
     * @param array<string, string> $defines extra makensis defines
     */
    public function __construct(
        public string $name,
        public ?string $icon,
        public string $companyName,
        public string $legalCopyright,
        public string $productName,
        public string $shellDescription,
        public string $workerDescription,
        public array $files,
        public ?string $installerScript,
        public array $defines,
        public string $treeOutput,
        public string $installerOutput,
    ) {
    }
}
