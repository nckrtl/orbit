<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * Gives tests the Ubuntu userland that Orbit's Node scripts expect.
 *
 * Production scripts run on Ubuntu Nodes. They use util-linux `setsid` and `flock`, GNU flags such as
 * `stat -c`, `mv -T`, `sed -i`, and `find -printf`, and Bash 4 features such as `mapfile`. A Linux host has
 * all of them, so this class changes nothing there. On another host, such as macOS, it puts the missing tools
 * first on PATH for this process and its children: `setsid` and `flock` stand-ins from Toolchain/bin, and
 * Homebrew's GNU coreutils, sed, find, and Bash. When one of them is not installed, it stops with the
 * command that installs it.
 */
final class TestToolchain
{
    /** Tools that the stand-ins in Toolchain/bin supply. */
    private const array StandIns = ['setsid', 'flock'];

    /** @var array<string, string> GNU tools outside coreutils, by the Homebrew formula that installs them. */
    private const array GnuTools = ['sed' => 'gnu-sed', 'find' => 'findutils'];

    private const array HomebrewPrefixes = ['/opt/homebrew', '/usr/local', '/home/linuxbrew/.linuxbrew'];

    private const string DirectoriesVariable = 'ORBIT_TEST_TOOLCHAIN_PATH';

    private const string BashVariable = 'ORBIT_TEST_TOOLCHAIN_BASH';

    /** @var list<string>|null */
    private static ?array $directories = null;

    private static ?string $bash = null;

    public static function bootstrap(): void
    {
        if (self::$directories !== null) {
            return;
        }

        $inherited = getenv('PATH');
        $inherited = is_string($inherited) && $inherited !== '' ? $inherited : '/usr/bin:/bin';
        $sharedDirectories = getenv(self::DirectoriesVariable);
        $sharedBash = getenv(self::BashVariable);

        // A parallel worker inherits the runner's toolchain, which is already first on its PATH.
        if (is_string($sharedDirectories) && is_string($sharedBash) && $sharedBash !== '') {
            self::$directories = array_values(array_filter(explode(PATH_SEPARATOR, $sharedDirectories), is_dir(...)));
            self::$bash = $sharedBash;

            return;
        }

        $links = [];
        $missing = [];

        foreach (self::StandIns as $tool) {
            if (self::find($tool, $inherited) === null) {
                $links[$tool] = __DIR__.DIRECTORY_SEPARATOR.'Toolchain'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.$tool;
            }
        }

        foreach (self::GnuTools as $tool => $formula) {
            if (self::isGnu($tool, $inherited)) {
                continue;
            }

            $gnu = self::gnuTool($tool, $formula, $inherited);

            if ($gnu === null) {
                $missing[] = $formula;
            } else {
                $links[$tool] = $gnu;
            }
        }

        $bash = self::bashAtLeast4($inherited);

        if ($bash === null) {
            $missing[] = 'bash';
        } elseif (min(self::bashMajorVersion('/bin/bash'), self::bashMajorVersion(self::find('bash', $inherited))) < 4) {
            // Fixtures that pin PATH to /usr/bin:/bin must still find Bash 4 first.
            $links['bash'] = $bash;
        }

        $coreutils = self::isGnu('stat', $inherited) ? false : self::gnuCoreutils();

        if ($coreutils === null) {
            $missing[] = 'coreutils';
        }

        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'The Gateway tests run Ubuntu Node scripts that need GNU coreutils, sed, and find, and Bash 4 or later. '
                .'Install the missing tools with `brew install %s`, then run the tests again.',
                implode(' ', $missing),
            ));
        }

        $directories = $links === [] ? [] : [self::linkDirectory($links)];

        if (is_string($coreutils)) {
            $directories[] = $coreutils;
        }

        self::$directories = $directories;
        self::$bash = (string) $bash;
        self::set(self::DirectoriesVariable, implode(PATH_SEPARATOR, $directories));
        self::set(self::BashVariable, self::$bash);

        if ($directories !== []) {
            self::set('PATH', implode(PATH_SEPARATOR, [...$directories, $inherited]));
        }
    }

    /** Returns a PATH that puts the test toolchain before $path. Use it wherever a test pins PATH for a child. */
    public static function path(string $path = '/usr/bin:/bin'): string
    {
        $directories = [...self::directories(), $path];
        $php = dirname(PHP_BINARY);

        // Node scripts call `php`; keep the interpreter that runs the tests reachable after $path.
        if (! in_array($php, explode(PATH_SEPARATOR, $path), true)) {
            $directories[] = $php;
        }

        return implode(PATH_SEPARATOR, $directories);
    }

    /** Returns a Bash 4+ interpreter: `/bin/bash` on Linux, Homebrew Bash on macOS. */
    public static function bash(): string
    {
        self::bootstrap();

        return self::$bash ?? throw new RuntimeException('The test Bash interpreter is not bootstrapped.');
    }

    /**
     * Points a fixture script's absolute tool paths, such as `exec /usr/bin/stat "$@"`, at the toolchain's
     * copies. On Linux it returns the script unchanged.
     */
    public static function script(string $script): string
    {
        $directories = self::directories();

        if ($directories === []) {
            return $script;
        }

        return (string) preg_replace_callback(
            '~(?<![\w./-])/(?:usr/)?bin/([a-z][\w.+-]*)(?![\w./-])~',
            static function (array $match) use ($directories): string {
                foreach ($directories as $directory) {
                    if (is_executable($directory.DIRECTORY_SEPARATOR.$match[1])) {
                        return $directory.DIRECTORY_SEPARATOR.$match[1];
                    }
                }

                return $match[0];
            },
            $script,
        );
    }

    /**
     * Fails a test that runs a production Node program on a Linux kernel interface, such as /proc or O_PATH,
     * that no package can add to another host. The test fails with the reason instead of being skipped.
     */
    public static function requireLinux(string $reason): void
    {
        if (PHP_OS_FAMILY === 'Linux') {
            return;
        }

        Assert::fail("This test needs Linux: {$reason} Run it on a Linux host. CI runs it on every pull request.");
    }

    /** Returns the path of a host tool that a test needs, or fails with the command that installs it. */
    public static function require(string $tool, string $install): string
    {
        self::bootstrap();
        $path = getenv('PATH');

        return self::find($tool, is_string($path) ? $path : self::path())
            ?? throw new RuntimeException("This test needs [{$tool}]. Install it with `{$install}`, then run the tests again.");
    }

    /** @return list<string> */
    private static function directories(): array
    {
        self::bootstrap();

        return self::$directories ?? [];
    }

    /**
     * Links each replacement tool into this process's own directory, so a tool the host already has correctly
     * is never shadowed.
     *
     * @param  array<string, string>  $links
     */
    private static function linkDirectory(array $links): string
    {
        $directory = TestOrbitHome::path().DIRECTORY_SEPARATOR.'toolchain';

        if (! is_dir($directory) && ! mkdir($directory, 0o700) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create the test toolchain directory [{$directory}].");
        }

        foreach ($links as $tool => $target) {
            if (! is_executable($target)) {
                throw new RuntimeException("The test {$tool} [{$target}] is not executable.");
            }

            if (! symlink($target, $directory.DIRECTORY_SEPARATOR.$tool)) {
                throw new RuntimeException("Could not link the test {$tool} into [{$directory}].");
            }
        }

        return $directory;
    }

    private static function gnuCoreutils(): ?string
    {
        foreach (self::homebrewPrefixes() as $prefix) {
            $directory = $prefix.'/opt/coreutils/libexec/gnubin';

            if (self::isGnu('stat', $directory)) {
                return $directory;
            }
        }

        return null;
    }

    /** Finds a GNU tool that Homebrew installs with a `g` prefix, such as `gsed` from `gnu-sed`. */
    private static function gnuTool(string $tool, string $formula, string $path): ?string
    {
        $candidates = array_map(
            static fn (string $prefix): string => "{$prefix}/opt/{$formula}/libexec/gnubin/{$tool}",
            self::homebrewPrefixes(),
        );
        $candidates[] = self::find('g'.$tool, $path);

        foreach ($candidates as $candidate) {
            if ($candidate !== null && self::isGnu(basename($candidate), dirname($candidate))) {
                return $candidate;
            }
        }

        return null;
    }

    private static function bashAtLeast4(string $path): ?string
    {
        $candidates = ['/bin/bash', self::find('bash', $path)];

        foreach (self::homebrewPrefixes() as $prefix) {
            $candidates[] = $prefix.'/bin/bash';
        }

        foreach ($candidates as $candidate) {
            if (self::bashMajorVersion($candidate) >= 4) {
                return $candidate;
            }
        }

        return null;
    }

    private static function bashMajorVersion(?string $bash): int
    {
        if ($bash === null || ! is_executable($bash)) {
            return 0;
        }

        $output = [];
        exec(escapeshellarg($bash).' -c '.escapeshellarg('printf %s "${BASH_VERSINFO[0]}"').' 2>/dev/null', $output);

        return (int) ($output[0] ?? 0);
    }

    /** @return list<string> */
    private static function homebrewPrefixes(): array
    {
        $prefix = getenv('HOMEBREW_PREFIX');

        return array_values(array_unique([
            ...(is_string($prefix) && $prefix !== '' ? [$prefix] : []),
            ...self::HomebrewPrefixes,
        ]));
    }

    private static function isGnu(string $tool, string $path): bool
    {
        $binary = self::find($tool, $path);

        if ($binary === null) {
            return false;
        }

        $output = [];
        exec(escapeshellarg($binary).' --version 2>/dev/null', $output, $status);

        return $status === 0 && str_contains(implode("\n", $output), 'GNU');
    }

    private static function find(string $tool, string $path): ?string
    {
        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            $candidate = ($directory === '' ? '.' : $directory).DIRECTORY_SEPARATOR.$tool;

            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
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
