<?php

declare(strict_types=1);

namespace JkBoost\Install\Agents;

use JkBoost\Install\RulePackage;

/**
 * Cursor: rule as .cursor/rules/<name>.mdc (with YAML frontmatter) + skills in .cursor/skills/.
 */
final class Cursor extends Agent
{
    public function name(): string
    {
        return 'cursor';
    }

    public function displayName(): string
    {
        return 'Cursor';
    }

    public function skillsPath(): string
    {
        return '.cursor/skills';
    }

    public function detectionPaths(): array
    {
        return ['.cursor'];
    }

    public function installRules(RulePackage $package): array
    {
        $frontmatter = "---\n"
            ."description: {$package->description}\n"
            .'alwaysApply: '.($package->alwaysApply ? 'true' : 'false')."\n"
            ."---\n\n";

        $relative = ".cursor/rules/{$package->name}.mdc";
        $status = $this->writeFile($relative, $frontmatter.$package->ruleBody());

        return [$relative => $status] + $this->installSkills($package);
    }
}
