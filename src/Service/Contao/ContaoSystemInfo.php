<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service\Contao;

use Composer\InstalledVersions;

/**
 * Versionen aus Composer (InstalledVersions) und die Bundles des Kernels – nur Namen und Versionen, keine Pfade.
 */
final class ContaoSystemInfo implements SystemInfoInterface
{
    private const PACKAGE_TYPES = ['contao-bundle', 'contao-module', 'contao-theme', 'symfony-bundle'];

    /** @param array<string, class-string> $kernelBundles Parameter kernel.bundles */
    public function __construct(private readonly array $kernelBundles) {}

    public function contaoVersion(): string
    {
        foreach (['contao/core-bundle', 'contao/contao'] as $package) {
            try {
                if (InstalledVersions::isInstalled($package)) {
                    return (string)InstalledVersions::getPrettyVersion($package);
                }
            } catch (\Throwable) {
            }
        }
        return '';
    }

    public function bundles(): array
    {
        $names = array_map('strval', array_keys($this->kernelBundles));
        sort($names);
        return $names;
    }

    public function packages(): array
    {
        $result = [];
        foreach (self::PACKAGE_TYPES as $type) {
            foreach (InstalledVersions::getInstalledPackagesByType($type) as $name) {
                $result[$name] = ['name' => $name, 'type' => $type, 'version' => (string)InstalledVersions::getPrettyVersion($name)];
            }
        }
        ksort($result);
        return array_values($result);
    }
}
