<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\VersionConstraint;

/**
 * A hibernating app-dev site renders `log_skip`, which the Caddy project added in 2.8.0, so every
 * Caddy publication fails on a Node below that release. ADR 0100 records the floor and the pinned
 * package source that keeps a Node above it.
 */
final readonly class CaddyRelease
{
    public const string MINIMUM = '2.8.0';

    public static function constraint(): string
    {
        return '>='.self::MINIMUM;
    }

    /**
     * Reads the release from `caddy version`, which reports `2.6.2` on the Ubuntu archive build and
     * `v2.11.4 h1:...` on the Caddy project's own.
     */
    public static function reported(string $output): ?string
    {
        $token = strtok(trim($output), " \t\n\r");

        if ($token === false) {
            return null;
        }

        return new SemverVersionNormalizer()->normalize($token);
    }

    public static function supports(string $output): bool
    {
        $version = self::reported($output);

        return $version !== null && new VersionConstraint()->allows($version, self::constraint());
    }
}
