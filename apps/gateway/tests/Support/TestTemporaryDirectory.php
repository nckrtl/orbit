<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Points TMPDIR at the canonical system temporary directory before any test reads it.
 *
 * On macOS, TMPDIR is `/var/folders/...`, and `/var` is a symlink to `/private/var`. Orbit refuses symlinked
 * path ancestors and compares canonical paths, as its Ubuntu Nodes require. Tests that build fixtures under
 * sys_get_temp_dir() would then see those checks reject their own directories. PHP caches the temporary
 * directory on the first sys_get_temp_dir() call, so this runs before anything else in tests/bootstrap.php.
 * The parallel runner reads it earlier, but its workers are new processes that inherit this TMPDIR. Child
 * processes inherit it too, so `mktemp` in shell programs under test agrees with the PHP side.
 */
final class TestTemporaryDirectory
{
    public static function bootstrap(): void
    {
        $configured = getenv('TMPDIR');
        $directory = is_string($configured) && $configured !== '' ? $configured : '/tmp';
        $canonical = realpath($directory);

        if ($canonical === false || ! is_dir($canonical)) {
            throw new RuntimeException("The temporary directory [{$directory}] does not exist.");
        }

        if (! putenv("TMPDIR={$canonical}")) {
            throw new RuntimeException('Could not set the TMPDIR test environment variable.');
        }

        $_ENV['TMPDIR'] = $canonical;
        $_SERVER['TMPDIR'] = $canonical;

    }
}
