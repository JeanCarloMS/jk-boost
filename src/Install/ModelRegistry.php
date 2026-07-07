<?php

declare(strict_types=1);

namespace JkBoost\Install;

/**
 * Discovers model packages by scanning resources/models/<*>/manifest.json.
 * Adding a new model type (e.g. another system besides N4) = adding a folder.
 */
final class ModelRegistry
{
    public function __construct(
        private readonly string $resourcesPath,
    ) {}

    /**
     * @return array<string, ModelPackage> keyed by package name
     */
    public function all(): array
    {
        $packages = [];

        foreach (glob($this->resourcesPath.'/models/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (is_file($dir.'/manifest.json')) {
                $package = ModelPackage::fromManifest($dir);
                $packages[$package->name] = $package;
            }
        }

        ksort($packages);

        return $packages;
    }

    public function get(string $name): ?ModelPackage
    {
        return $this->all()[$name] ?? null;
    }
}
