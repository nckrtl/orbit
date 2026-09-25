<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Resolves real host commands for fixture shims that record a call and then run the command.
 *
 * Ubuntu keeps coreutils in /usr/bin, but macOS keeps cat, chmod, ln, mv, and similar commands in /bin. A
 * shim that hard-codes /usr/bin/mv therefore fails with exit 127 on macOS. Shims name the command as
 * `{{host:mv}}` instead, and expand() replaces it with the command found on this process's PATH. The shim
 * directory is not on that PATH, so a shim never finds itself.
 */
final class HostBinary
{
    public static function path(string $name): string
    {
        $path = new ExecutableFinder()->find($name);

        if ($path === null) {
            throw new RuntimeException("The test host has no [{$name}] command on PATH.");
        }

        return $path;
    }

    /** Replaces each `{{host:name}}` in a shim script with the quoted path of that host command. */
    public static function expand(string $script): string
    {
        return preg_replace_callback(
            '/\{\{host:([a-z0-9_-]+)\}\}/',
            static fn (array $match): string => escapeshellarg(self::path($match[1])),
            $script,
        ) ?? throw new RuntimeException('Could not expand the host commands in a shim script.');
    }
}
