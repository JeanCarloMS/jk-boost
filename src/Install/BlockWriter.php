<?php

declare(strict_types=1);

namespace JkBoost\Install;

use RuntimeException;

/**
 * Writes content into a managed block inside a markdown file (CLAUDE.md, AGENTS.md).
 * The block is delimited by HTML comments so re-running install replaces it in place
 * without touching anything the user wrote around it.
 */
final class BlockWriter
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public function write(string $filePath, string $blockName, string $content): string
    {
        $start = "<!-- jk-boost:{$blockName}:start -->";
        $end = "<!-- jk-boost:{$blockName}:end -->";
        $block = $start."\n".rtrim($content)."\n".$end;

        $directory = dirname($filePath);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true)) {
            throw new RuntimeException("Failed to create directory: {$directory}");
        }

        if (! is_file($filePath)) {
            file_put_contents($filePath, $block."\n");

            return self::CREATED;
        }

        $existing = (string) file_get_contents($filePath);
        $pattern = '/'.preg_quote($start, '/').'.*?'.preg_quote($end, '/').'/s';

        if (preg_match($pattern, $existing)) {
            $new = (string) preg_replace($pattern, $block, $existing, 1);
        } else {
            $new = rtrim($existing)."\n\n".$block."\n";
        }

        file_put_contents($filePath, $new);

        return self::UPDATED;
    }
}
