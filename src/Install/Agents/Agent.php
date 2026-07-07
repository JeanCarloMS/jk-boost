<?php

declare(strict_types=1);

namespace JkBoost\Install\Agents;

use FilesystemIterator;
use JkBoost\Install\RulePackage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * An AI agent/IDE target (Cursor, Claude Code, Codex).
 * Knows where its rules and skills live inside a project and how to write them.
 */
abstract class Agent
{
    public function __construct(
        protected readonly string $basePath,
    ) {}

    abstract public function name(): string;

    abstract public function displayName(): string;

    /**
     * Relative directory where SKILL.md skills are installed for this agent.
     */
    abstract public function skillsPath(): string;

    /**
     * Paths/files (relative to base path) whose existence suggests the agent is used in this project.
     *
     * @return array<string>
     */
    abstract public function detectionPaths(): array;

    /**
     * Install/update the rule package for this agent.
     *
     * @return array<string, string> relative file path => created|updated
     */
    abstract public function installRules(RulePackage $package): array;

    public function isDetected(): bool
    {
        foreach ($this->detectionPaths() as $path) {
            if (file_exists($this->basePath.'/'.$path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Copy every skill of the package into this agent's skills path (recursively,
     * so skills can ship references/ subfolders).
     *
     * @return array<string, string> relative file path => created|updated
     */
    protected function installSkills(RulePackage $package): array
    {
        $written = [];

        foreach ($package->skills() as $skillName => $sourceDir) {
            $targetDir = $this->basePath.'/'.$this->skillsPath().'/'.$skillName;

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            /** @var SplFileInfo $item */
            foreach ($iterator as $item) {
                $relative = substr($item->getPathname(), strlen($sourceDir) + 1);
                $target = $targetDir.'/'.$relative;

                if ($item->isDir()) {
                    $this->ensureDirectory($target);

                    continue;
                }

                $written[$this->skillsPath().'/'.$skillName.'/'.$relative] = $this->copyFile($item->getPathname(), $target);
            }
        }

        return $written;
    }

    /**
     * @return string created|updated
     */
    protected function copyFile(string $source, string $target): string
    {
        $this->ensureDirectory(dirname($target));

        $status = is_file($target) ? 'updated' : 'created';

        copy($source, $target);

        return $status;
    }

    protected function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Failed to create directory: {$directory}");
        }
    }

    protected function writeFile(string $relativePath, string $content): string
    {
        $target = $this->basePath.'/'.$relativePath;

        $this->ensureDirectory(dirname($target));

        $status = is_file($target) ? 'updated' : 'created';

        file_put_contents($target, $content);

        return $status;
    }
}
