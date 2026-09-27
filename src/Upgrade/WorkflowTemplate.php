<?php

namespace Gadya\Cms\Upgrade;

/**
 * Gadya's update workflow: the template this release ships, and the one
 * the site's repository holds. The first line says which it is:
 *
 *     # gadya-update-template: 2
 *
 * The first template said nothing, so a file without the line is 1.
 */
final class WorkflowTemplate
{
    public const PATH = '.github/workflows/gadya-update.yml';

    public static function templatePath(): string
    {
        return dirname(__DIR__, 2).'/resources/github/gadya-update.yml';
    }

    public static function shipped(): int
    {
        return self::number((string) file_get_contents(self::templatePath())) ?? 1;
    }

    /** Null when the repository has no update workflow. */
    public static function installed(): ?int
    {
        $path = base_path(self::PATH);

        return is_file($path) ? (self::number((string) file_get_contents($path)) ?? 1) : null;
    }

    public static function number(string $workflow): ?int
    {
        return preg_match('/^#\s*gadya-update-template:\s*(\d+)/', ltrim($workflow), $matches) === 1 ? (int) $matches[1] : null;
    }
}
