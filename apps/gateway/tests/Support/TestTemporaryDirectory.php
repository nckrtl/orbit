<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/**
 * Gives each test process its own traversable temporary directory and removes it when the process ends.
 *
 * Tests and the shell programs they run create their fixtures under sys_get_temp_dir(), so removing this
 * directory removes every fixture a test forgot, and a suite run leaves nothing in TMPDIR. A process that a
 * test starts reuses the directory it inherits. A run ended by SIGKILL leaves its directory; the next run
 * removes it once that process is gone. Cross-user tests grant access on individual fixtures, so the directory is
 * traversable. Canonicalize /tmp on macOS, where it is a symlink. PHP caches sys_get_temp_dir(), so this
 * runs before other test bootstrap code. Production and check processes keep their private TMPDIR; only
 * test processes replace it.
 */
final class TestTemporaryDirectory
{
    public const string Prefix = 'orbit-gateway-tests-';

    private const int NoSuchProcess = 3;

    public static function bootstrap(): void
    {
        $configured = getenv('TMPDIR');
        $base = is_string($configured) && $configured !== '' ? $configured : '/tmp';

        if (str_starts_with(basename($base), self::Prefix)) {
            self::use($base);

            return;
        }

        // Cross-user fixtures cannot traverse a role-private workspace TMPDIR ancestor.
        if (preg_match('~/(?:orbit/tmp/(?:agent|check)-[0-9]+|orbit-check-[0-9]+-[^/]+)$~', $base) === 1) {
            $base = '/tmp';
        }

        self::use(self::create($base));
    }

    /**
     * Creates a directory for this test process under $base, which the process removes when it ends.
     * It first removes the directories that ended processes left there.
     */
    public static function create(string $base): string
    {
        $canonical = realpath($base);

        if ($canonical === false || ! is_dir($canonical)) {
            throw new RuntimeException("The temporary directory [{$base}] does not exist.");
        }

        self::removeAbandoned($canonical);
        $owner = getmypid();
        $directory = $canonical.'/'.self::Prefix.$owner.'-'.bin2hex(random_bytes(4));

        if (! mkdir($directory, 0o755) || ! chmod($directory, 0o755)) {
            throw new RuntimeException("Could not create the test temporary directory in [{$canonical}].");
        }

        $remove = static function () use ($directory, $owner): void {
            // A forked child shares these handlers; only the process that made the directory removes it.
            if (getmypid() === $owner) {
                new Filesystem()->deleteDirectory($directory);
            }
        };
        // Run after the shutdown functions of other bootstrap code, which may still use the directory.
        register_shutdown_function(static fn () => register_shutdown_function($remove));
        InterruptCleanup::register($remove);

        return $directory;
    }

    private static function use(string $directory): void
    {
        if (! putenv("TMPDIR={$directory}")) {
            throw new RuntimeException('Could not set the TMPDIR test environment variable.');
        }

        $_ENV['TMPDIR'] = $directory;
        $_SERVER['TMPDIR'] = $directory;
    }

    /** Removes this user's directories whose process no longer runs, such as those of a run ended by SIGKILL. */
    private static function removeAbandoned(string $base): void
    {
        if (! function_exists('posix_kill') || ! function_exists('posix_geteuid')) {
            return;
        }

        foreach (glob($base.'/'.self::Prefix.'*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (
                preg_match('/\A'.preg_quote(self::Prefix, '/').'(\d+)-[0-9a-f]{8}\z/', basename($directory), $matches) !== 1
                || is_link($directory)
                || fileowner($directory) !== posix_geteuid()
                || posix_kill((int) $matches[1], 0)
                || posix_get_last_error() !== self::NoSuchProcess
            ) {
                continue;
            }

            new Filesystem()->deleteDirectory($directory);
        }
    }
}
