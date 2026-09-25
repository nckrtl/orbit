<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Prepares Ubuntu guest scripts to run on the test host.
 *
 * Guest scripts call the guest's `/usr/bin/php -r` by absolute path. A test
 * host may keep PHP elsewhere (macOS has no `/usr/bin/php`), so host copies
 * call the PHP binary that runs the suite. The scripts themselves keep the
 * absolute guest path.
 *
 * Guest scripts also use GNU coreutils flags, such as `stat -c`. When the
 * host `stat` does not accept them, the suite puts Homebrew's `gnubin`
 * first, and guest script tests fail with install instructions when it is
 * missing.
 */
final class GuestScripts
{
    private const array GNU_BIN_DIRECTORIES = [
        '/opt/homebrew/opt/coreutils/libexec/gnubin',
        '/usr/local/opt/coreutils/libexec/gnubin',
    ];

    private static ?bool $gnuUserland = null;

    /**
     * Puts Homebrew's GNU coreutils first on PATH when the host `stat` lacks GNU flags.
     */
    public static function useGnuUserland(): void
    {
        if (self::hasGnuUserland()) {
            return;
        }

        foreach (self::GNU_BIN_DIRECTORIES as $directory) {
            if (is_executable($directory.'/stat')) {
                $path = $directory.':'.(string) getenv('PATH');
                putenv('PATH='.$path);
                $_ENV['PATH'] = $path;
                $_SERVER['PATH'] = $path;
                self::$gnuUserland = null;

                return;
            }
        }
    }

    public static function requireGnuUserland(): void
    {
        if (! self::hasGnuUserland()) {
            throw new RuntimeException(
                'Guest script tests need a `stat` that accepts GNU flags such as `-c`, as on the Ubuntu guests. '
                .'On macOS, run `brew install coreutils`.',
            );
        }
    }

    private static function hasGnuUserland(): bool
    {
        if (self::$gnuUserland === null) {
            $process = new Process(['stat', '-c', '%n', '/']);
            $process->run();
            self::$gnuUserland = $process->isSuccessful() && $process->getOutput() === "/\n";
        }

        return self::$gnuUserland;
    }

    public static function directory(): string
    {
        return dirname(__DIR__, 2).'/resources/guest';
    }

    public static function source(string $name): string
    {
        self::requireGnuUserland();
        $source = file_get_contents(self::directory().'/'.$name);

        if ($source === false) {
            throw new RuntimeException("Unable to read the guest script {$name}.");
        }

        return str_replace('/usr/bin/php -r ', escapeshellarg(PHP_BINARY).' -r ', $source);
    }

    /**
     * Writes host copies of every guest script, so a script still finds its
     * siblings next to itself, and returns the path of the named copy.
     */
    public static function path(string $name): string
    {
        $directory = TemporaryPaths::path('orbit-guest-', 6);
        mkdir($directory, 0o700, true);
        $scripts = glob(self::directory().'/*');

        foreach ($scripts === false ? [] : $scripts as $script) {
            $copy = $directory.'/'.basename($script);
            file_put_contents($copy, self::source(basename($script)));
            chmod($copy, fileperms($script) & 0o777);
        }

        if (! is_file($directory.'/'.$name)) {
            throw new RuntimeException("The guest script {$name} does not exist.");
        }

        return $directory.'/'.$name;
    }
}
