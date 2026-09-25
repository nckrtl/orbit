<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/**
 * Gives each test process its own ORBIT_HOME and scratch directory.
 *
 * Operation locks, SSH state, and other Gateway files live under ORBIT_HOME. Parallel workers each hold an
 * in-memory database, so their records reuse the same IDs. In a shared home, one worker's lock on
 * `app-instance-1.lock` would block another worker's unrelated test with `*.operation_busy`. Tests that need
 * a temporary checkout create it under scratch(), so their cleanup never touches another suite's files.
 */
final class TestOrbitHome
{
    public const Prefix = 'orbit-gateway-testing-';

    private const NoSuchProcess = 3;

    private static ?string $home = null;

    public static function bootstrap(): void
    {
        if (self::$home !== null) {
            return;
        }

        $temporaryDirectory = realpath(sys_get_temp_dir());

        if ($temporaryDirectory === false) {
            throw new RuntimeException('The system temporary directory does not exist.');
        }

        self::removeAbandonedHomes($temporaryDirectory);

        // Always replace an inherited value: parallel workers inherit the runner's environment, and a real
        // ORBIT_HOME in the shell must never receive test state.
        $home = $temporaryDirectory.DIRECTORY_SEPARATOR.self::Prefix.getmypid().'-'.bin2hex(random_bytes(4));

        if (! mkdir(directory: $home, permissions: 0o700)) {
            throw new RuntimeException("Could not create the test ORBIT_HOME [{$home}].");
        }

        self::$home = $home;
        self::set('ORBIT_HOME', $home);

        $owner = getmypid();
        $remove = static function () use ($home, $owner): void {
            // A forked child shares these handlers; only the process that created the home removes it.
            if (getmypid() === $owner) {
                new Filesystem()->deleteDirectory($home);
            }
        };

        register_shutdown_function($remove);
        self::removeOnInterrupt($remove);
    }

    public static function path(): string
    {
        return self::$home ?? throw new RuntimeException('The test ORBIT_HOME is not bootstrapped.');
    }

    /** Returns a new, not yet created path in this process's scratch directory. */
    public static function scratch(string $name): string
    {
        return self::scratchDirectory().DIRECTORY_SEPARATOR.$name.'-'.bin2hex(random_bytes(6));
    }

    /** Removes everything that this process created under scratch(). */
    public static function clearScratch(): void
    {
        new Filesystem()->deleteDirectory(self::scratchDirectory());
    }

    private static function scratchDirectory(): string
    {
        return self::path().DIRECTORY_SEPARATOR.'scratch';
    }

    /**
     * Removes the home as well when Ctrl-C or a termination signal ends the process, because PHP skips shutdown
     * functions then. The previous handler still runs, or the default action ends the process.
     */
    private static function removeOnInterrupt(callable $remove): void
    {
        if (! function_exists('pcntl_signal') || ! function_exists('posix_kill')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
            $previous = pcntl_signal_get_handler($signal);

            pcntl_signal($signal, static function (int $signal, mixed $information) use ($remove, $previous): void {
                $remove();

                if (is_callable($previous)) {
                    $previous($signal, $information);

                    return;
                }

                if ($previous === SIG_IGN) {
                    return;
                }

                pcntl_signal($signal, SIG_DFL);
                posix_kill(getmypid(), $signal);
            });
        }
    }

    /** Removes homes whose process no longer runs, such as those of a run ended by SIGKILL. */
    private static function removeAbandonedHomes(string $temporaryDirectory): void
    {
        if (! function_exists('posix_kill') || ! function_exists('posix_geteuid')) {
            return;
        }

        $pattern = $temporaryDirectory.DIRECTORY_SEPARATOR.self::Prefix.'*';

        foreach (glob($pattern, GLOB_ONLYDIR) ?: [] as $home) {
            if (
                preg_match('/\A'.preg_quote(self::Prefix, '/').'(\d+)-[0-9a-f]{8}\z/', basename($home), $matches) !== 1
                || is_link($home)
                || fileowner($home) !== posix_geteuid()
                || posix_kill((int) $matches[1], 0)
                || posix_get_last_error() !== self::NoSuchProcess
            ) {
                continue;
            }

            new Filesystem()->deleteDirectory($home);
        }
    }

    private static function set(string $name, string $value): void
    {
        if (! putenv("{$name}={$value}")) {
            throw new RuntimeException("Could not set the {$name} test environment variable.");
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
