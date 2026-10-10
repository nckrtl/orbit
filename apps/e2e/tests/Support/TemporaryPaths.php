<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Throwable;

/**
 * Tracks temporary paths created by tests so they are removed after each test.
 * Without this, parallel suite runs exhaust the tmpfs inode budget on /tmp.
 * Every path lives in one directory per test process, which the process removes
 * when it ends, so paths that tests derive from a tracked one, such as
 * `<path>-worktrees` or `<path>.json`, go too.
 */
final class TemporaryPaths
{
    /** @var list<string> */
    private static array $paths = [];

    private static ?string $directory = null;

    /**
     * Returns the canonical temporary directory of this test process.
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

        $base = realpath(sys_get_temp_dir());

        if ($base === false) {
            throw new RuntimeException('Unable to resolve the temporary directory.');
        }

        if (preg_match('~\A(/[A-Za-z_][A-Za-z0-9_.-]*)+\z~', $base) !== 1) {
            $base = realpath('/tmp');

            if ($base === false) {
                throw new RuntimeException('Unable to resolve /tmp.');
            }
        }

        $directory = $base.'/orbit-e2e-tests-'.bin2hex(random_bytes(6));

        if (! mkdir($directory, 0o755) || ! chmod($directory, 0o755)) {
            throw new RuntimeException('Unable to create the test process temporary directory.');
        }

        $owner = getmypid();
        register_shutdown_function(static function () use ($directory, $owner): void {
            if (getmypid() !== $owner) {
                return;
            }

            try {
                @self::remove($directory);
            } catch (Throwable) {
                // Another user's files stay; the Node's tmpfiles rule empties the directory after a day.
            }
        });

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
