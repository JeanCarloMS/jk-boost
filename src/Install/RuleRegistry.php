<?php

declare(strict_types=1);

namespace JkBoost\Install;

/**
 * Discovers rule packages by scanning resources/rules/<*>/manifest.json.
 * Adding a new rule package = adding a folder — no code changes needed.
 */
final class RuleRegistry
{
    public function __construct(
        private readonly string $resourcesPath,
    ) {}

    /**
     * @return array<string, RulePackage> keyed by package name
     */
    public function all(): array
    {
        $packages = [];

        foreach (glob($this->resourcesPath.'/rules/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (is_file($dir.'/manifest.json')) {
                $package = RulePackage::fromManifest($dir);
                $packages[$package->name] = $package;
            }
        }

        ksort($packages);

        return $packages;
    }

    public function get(string $name): ?RulePackage
    {
        return $this->all()[$name] ?? null;
    }
}
