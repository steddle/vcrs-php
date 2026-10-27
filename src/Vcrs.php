<?php

namespace Steddle\Vcrs;

use Composer\InstalledVersions;

final class Vcrs
{
    public const VERSION = '0.1.0-beta.6';

    /**
     * Installed as a package, the standard's folder is where Composer put `steddle/vcrs`, which is not beside this
     * package in a clone of its own repository. In this repository it is beside it.
     */
    public static function standard(string $path): string
    {
        $root = class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('steddle/vcrs')
            ? InstalledVersions::getInstallPath('steddle/vcrs')
            : dirname(__DIR__, 2).'/vcrs';

        return "{$root}/{$path}";
    }
}
