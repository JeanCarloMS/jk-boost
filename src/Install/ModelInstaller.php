<?php

declare(strict_types=1);

namespace JkBoost\Install;

use RuntimeException;

/**
 * Copies model stubs into the host app, replacing the {{ namespace }} placeholder.
 */
final class ModelInstaller
{
    public function __construct(
        private readonly string $basePath,
    ) {}

    /**
     * @return array<string, string> relative file path => created|updated|skipped
     */
    public function install(ModelPackage $package, bool $overwrite = false): array
    {
        $written = [];

        foreach ($package->stubs() as $stub) {
            $class = basename($stub, '.php.stub');
            $relative = "{$package->targetPath}/{$class}.php";
            $target = $this->basePath.'/'.$relative;

            if (is_file($target) && ! $overwrite) {
                $written[$relative] = 'skipped';

                continue;
            }

            $directory = dirname($target);

            if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
                throw new RuntimeException("Failed to create directory: {$directory}");
            }

            $status = is_file($target) ? 'updated' : 'created';

            $content = str_replace(
                '{{ namespace }}',
                $package->namespace,
                (string) file_get_contents($stub),
            );

            file_put_contents($target, $content);

            $written[$relative] = $status;
        }

        return $written;
    }

    /**
     * @return array<string> relative paths of stubs that already exist in the host app
     */
    public function existingTargets(ModelPackage $package): array
    {
        $existing = [];

        foreach ($package->stubs() as $stub) {
            $class = basename($stub, '.php.stub');
            $relative = "{$package->targetPath}/{$class}.php";

            if (is_file($this->basePath.'/'.$relative)) {
                $existing[] = $relative;
            }
        }

        return $existing;
    }
}
