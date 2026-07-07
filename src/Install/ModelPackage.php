<?php

declare(strict_types=1);

namespace JkBoost\Install;

use JsonException;

/**
 * A model package: a set of Eloquent model stubs (models/<Class>.php.stub) described
 * by a manifest.json. Lives under resources/models/<name>/.
 */
final class ModelPackage
{
    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $description,
        public readonly string $namespace,
        public readonly string $targetPath,
        public readonly string $dir,
    ) {}

    /**
     * @throws JsonException
     */
    public static function fromManifest(string $dir): self
    {
        $manifest = json_decode(
            (string) file_get_contents($dir.'/manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return new self(
            name: $manifest['name'] ?? basename($dir),
            title: $manifest['title'] ?? basename($dir),
            description: $manifest['description'] ?? '',
            namespace: $manifest['namespace'] ?? 'App\\Models',
            targetPath: rtrim($manifest['target_path'] ?? 'app/Models', '/'),
            dir: $dir,
        );
    }

    /**
     * @return array<string> absolute stub paths
     */
    public function stubs(): array
    {
        $stubs = glob($this->dir.'/models/*.php.stub') ?: [];

        sort($stubs);

        return $stubs;
    }
}
