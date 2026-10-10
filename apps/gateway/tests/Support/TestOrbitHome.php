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
        InterruptCleanup::register($remove);
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

    private static function set(string $name, string $value): void
    {
        if (! putenv("{$name}={$value}")) {
            throw new RuntimeException("Could not set the {$name} test environment variable.");
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
