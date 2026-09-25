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

    private static ?string $directory = null;

    /**
     * Returns the canonical temporary directory for test paths.
     *
     * The harness refuses symbolic-link path components and compares canonical
     * paths, so the base resolves links such as macOS `/var` -> `/private/var`.
     * Pest derives test namespaces from file paths, so when a component of the
     * system directory is not a valid namespace segment (macOS per-user
     * `/var/folders/1j/...`), the canonical `/tmp` is used instead.
     */
    public static function directory(): string
    {
        if (self::$directory !== null) {
            return self::$directory;
        }

        $directory = realpath(sys_get_temp_dir());

        if ($directory === false) {
            throw new RuntimeException('Unable to resolve the temporary directory.');
        }

        if (preg_match('~\A(/[A-Za-z_][A-Za-z0-9_.-]*)+\z~', $directory) !== 1) {
            $directory = realpath('/tmp');

            if ($directory === false) {
                throw new RuntimeException('Unable to resolve /tmp.');
            }
        }

        return self::$directory = $directory;
    }

    public static function path(string $prefix, int $randomBytes = 8): string
    {
        $path = self::directory().'/'.$prefix.bin2hex(random_bytes($randomBytes));
        self::$paths[] = $path;

        return $path;
    }

    public static function file(string $prefix): string
    {
        $path = tempnam(self::directory(), $prefix);

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
