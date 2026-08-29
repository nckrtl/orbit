<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Tracks temporary paths created by tests so they are removed after each test.
 * Without this, parallel suite runs exhaust the tmpfs inode budget on /tmp.
 */
final class TemporaryPaths
{
    /** @var list<string> */
    private static array $paths = [];

    public static function path(string $prefix, int $randomBytes = 8): string
    {
        $path = rtrim(sys_get_temp_dir(), '/').'/'.$prefix.bin2hex(random_bytes($randomBytes));
        self::$paths[] = $path;

        return $path;
    }

    public static function file(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);

        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary file.');
        }

        self::$paths[] = $path;

        return $path;
    }

    public static function cleanup(): void
    {
        foreach (self::$paths as $path) {
            self::remove($path);
        }

        self::$paths = [];
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        chmod($path, 0o700);

        $entries = scandir($path);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path.'/'.$entry);
            }
        }

        rmdir($path);
    }
}
