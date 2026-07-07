<?php

declare(strict_types=1);

namespace JkBoost\Install\Agents;

use JkBoost\Install\BlockWriter;
use JkBoost\Install\RulePackage;

/**
 * Codex: rule inside a managed block in AGENTS.md + skills copied to .agents/skills/.
 * Codex has no skill auto-loading, so the block ends with an index pointing to the
 * skill files it should read on demand.
 */
final class Codex extends Agent
{
    private readonly BlockWriter $blockWriter;

    public function __construct(string $basePath)
    {
        parent::__construct($basePath);

        $this->blockWriter = new BlockWriter();
    }

    public function name(): string
    {
        return 'codex';
    }

    public function displayName(): string
    {
        return 'Codex';
    }

    public function skillsPath(): string
    {
        return '.agents/skills';
    }

    public function detectionPaths(): array
    {
        return ['.codex', 'AGENTS.md'];
    }

    public function installRules(RulePackage $package): array
    {
        $body = $package->ruleBody();

        $skills = array_keys($package->skills());

        if ($skills !== []) {
            $body .= "\n## Satellite skill files (read on demand)\n\n";

            foreach ($skills as $skill) {
                $body .= "- `{$this->skillsPath()}/{$skill}/SKILL.md`\n";
            }
        }

        $status = $this->blockWriter->write(
            $this->basePath.'/AGENTS.md',
            $package->name,
            $body,
        );

        return ['AGENTS.md' => $status] + $this->installSkills($package);
    }
}
