<?php

declare(strict_types=1);

namespace JkBoost\Install;

use JsonException;
use RuntimeException;

/**
 * A rule package: one master rule (rule.md) + its satellite skills (skills/<name>/SKILL.md),
 * described by a manifest.json. Lives under resources/rules/<name>/.
 */
final class RulePackage
{
    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $description,
        public readonly bool $alwaysApply,
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
            alwaysApply: (bool) ($manifest['always_apply'] ?? true),
            dir: $dir,
        );
    }

    public function ruleBody(): string
    {
        $path = $this->dir.'/rule.md';

        if (! is_file($path)) {
            throw new RuntimeException("Rule package [{$this->name}] is missing rule.md at {$path}");
        }

        return rtrim((string) file_get_contents($path))."\n";
    }

    /**
     * @return array<string, string> skill name => absolute skill directory
     */
    public function skills(): array
    {
        $skills = [];

        foreach (glob($this->dir.'/skills/*', GLOB_ONLYDIR) ?: [] as $skillDir) {
            if (is_file($skillDir.'/SKILL.md')) {
                $skills[basename($skillDir)] = $skillDir;
            }
        }

        ksort($skills);

        return $skills;
    }
}
