<?php

declare(strict_types=1);

namespace App\E2E\Value;

/**
 * The one rule every host path that reaches an Incus disk device must satisfy.
 *
 * Incus receives mount sources and guest paths verbatim inside `key=value` and
 * comma-separated device configuration, so the separators are refused outright.
 */
final class MountPath
{
    public static function isSafe(string $path): bool
    {
        return (
            str_starts_with($path, '/')
            && ! str_contains($path, "\0")
            && ! str_contains($path, "\n")
            && ! str_contains($path, ',')
            && ! str_contains($path, '=')
            && $path === rtrim($path, characters: '/')
        );
    }

    /** A mount source must be a real directory: symlinks would let Incus expose another tree. */
    public static function isMountableDirectory(string $path): bool
    {
        return self::isSafe($path) && ! is_link($path) && is_dir($path);
    }
}
