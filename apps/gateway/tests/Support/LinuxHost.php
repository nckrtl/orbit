<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs a test on the Linux test host when the test host is not Linux.
 *
 * A few tests execute Node programs that depend on Linux kernel interfaces with no equivalent elsewhere, such as
 * `os.O_PATH` with `/proc/self/fd` reopening, the `/proc/net/tcp` listener tables, or POSIX ACLs. On Linux, such as
 * in CI or on beast, the test runs directly. On another host, such as macOS, `delegate()` copies the Gateway
 * directory to beast, an Ubuntu machine like the Nodes, runs the same test there over `ssh beast`, and asserts that
 * it passed. Beast has `acl`, a `caddy` service account, and passwordless `sudo`, as the CI host does.
 *
 * Each test process copies the Gateway once to its own directory on beast, reuses it for every delegated test,
 * and removes it when the process ends. Untracked environment files and keys are never copied.
 */
final class LinuxHost
{
    private const string Host = 'beast';

    private const string RemoteRoot = '/tmp/orbit-gateway-linux-tests';

    private const float TimeoutSeconds = 300.0;

    private static ?string $directory = null;

    /**
     * Returns false on Linux, where the caller runs its test body. Elsewhere it runs this test on beast, fails
     * unless exactly this test passed there, and returns true so the caller returns.
     */
    public static function delegate(TestCase $test): bool
    {
        if (PHP_OS_FAMILY === 'Linux') {
            return false;
        }

        $root = base_path();
        // Pest compiles each test file into a class that records its source file.
        $file = $test::$__filename ?? throw new RuntimeException('The test does not name its Pest source file.');

        if (! str_starts_with($file, $root.'/')) {
            throw new RuntimeException("The test file [{$file}] is outside the Gateway directory [{$root}].");
        }

        $directory = self::directory($root);
        $command = implode(' ', array_map(escapeshellarg(...), [
            'env', '-i', 'HOME=/tmp', 'LANG=C.UTF-8', 'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'php', 'vendor/bin/pest', '--colors=never', '--display-warnings', '--display-notices', '--display-deprecations',
            '--filter', self::filter($test), substr($file, strlen($root) + 1),
        ]));
        $process = self::ssh('cd '.escapeshellarg($directory).' && '.$command, self::TimeoutSeconds);
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();

        Assert::assertSame(
            0,
            $process->getExitCode(),
            'The test failed on the Linux test host (ssh '.self::Host."):\n{$output}",
        );
        Assert::assertMatchesRegularExpression(
            '/Tests:\s+1 passed/',
            $output,
            'The Linux test host (ssh '.self::Host.") did not run exactly this test:\n{$output}",
        );

        return true;
    }

    /**
     * Pest filters by test description. A data set case prints as `… with <data name>` but filters as
     * `… with data set "<data name>"`, and the filter is a regular expression.
     */
    private static function filter(TestCase $test): string
    {
        if (! method_exists($test, 'getPrintableTestCaseMethodName')) {
            throw new RuntimeException('Only Pest tests can run on the Linux test host.');
        }

        $description = (string) $test->getPrintableTestCaseMethodName();
        $dataName = (string) $test->dataName();

        if ($dataName === '') {
            return preg_quote($description, '/');
        }

        $suffix = ' with '.$dataName;

        if (! str_ends_with($description, $suffix)) {
            throw new RuntimeException("Could not find the data set in the test description [{$description}].");
        }

        return preg_quote(substr($description, 0, -strlen($suffix)).' with data set "'.$dataName.'"', '/');
    }

    /** Copies the Gateway to this process's directory on beast once, and removes it when the process ends. */
    private static function directory(string $root): string
    {
        if (self::$directory !== null) {
            return self::$directory;
        }

        $directory = self::RemoteRoot.'/'.bin2hex(random_bytes(8));
        // Directories of test processes that ended without cleanup are removed after a day.
        $prepare = self::ssh(sprintf(
            'mkdir -p %1$s && find %1$s -mindepth 1 -maxdepth 1 -type d -mmin +1440 -exec rm -rf {} + && mkdir %2$s',
            escapeshellarg(self::RemoteRoot),
            escapeshellarg($directory),
        ), 30);

        if ($prepare->run() !== 0) {
            throw new RuntimeException(
                'This test runs a Linux-only Node program on the Linux test host, but `ssh '.self::Host.'` failed. '
                .'Make `ssh '.self::Host.'` work without a prompt, then run the tests again.'
                ."\n".trim($prepare->getErrorOutput()),
            );
        }

        register_shutdown_function(static function () use ($directory): void {
            self::ssh('rm -rf '.escapeshellarg($directory), 60)->run();
        });

        $sync = new Process([
            'rsync', '--archive', '--compress', '--delete',
            '--rsh', 'ssh -o BatchMode=yes -o ConnectTimeout=10',
            '--include', '/.env.example',
            '--exclude', '/.env*',
            '--exclude', '/auth.json',
            '--exclude', '/storage/*.key',
            '--exclude', '/.orbit-tia',
            '--exclude', '/node_modules',
            $root.'/',
            self::Host.':'.$directory.'/',
        ], timeout: self::TimeoutSeconds);

        if ($sync->run() !== 0) {
            throw new RuntimeException(
                'Could not copy the Gateway to the Linux test host with rsync over `ssh '.self::Host.'`:'
                ."\n".trim($sync->getErrorOutput()),
            );
        }

        return self::$directory = $directory;
    }

    private static function ssh(string $command, float $timeout): Process
    {
        return new Process(
            ['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', self::Host, $command],
            timeout: $timeout,
        );
    }
}
