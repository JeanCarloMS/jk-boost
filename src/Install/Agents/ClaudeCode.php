<?php

declare(strict_types=1);

namespace JkBoost\Install\Agents;

use JkBoost\Install\BlockWriter;
use JkBoost\Install\RulePackage;

/**
 * Claude Code: rule inside a managed block in CLAUDE.md + skills in .claude/skills/
 * (auto-discovered by Claude Code).
 */
final class ClaudeCode extends Agent
{
    private readonly BlockWriter $blockWriter;

    public function __construct(string $basePath)
    {
        parent::__construct($basePath);

        $this->blockWriter = new BlockWriter();
    }

    public function name(): string
    {
        return 'claude_code';
    }

    public function displayName(): string
    {
        return 'Claude Code';
    }

    public function skillsPath(): string
    {
        return '.claude/skills';
    }

    public function detectionPaths(): array
    {
        return ['.claude', 'CLAUDE.md'];
    }

    public function installRules(RulePackage $package): array
    {
        $status = $this->blockWriter->write(
            $this->basePath.'/CLAUDE.md',
            $package->name,
            $package->ruleBody(),
        );

        return ['CLAUDE.md' => $status] + $this->installSkills($package);
    }
}
