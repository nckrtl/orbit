<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs a test on the Linux test host when the test host is not Linux.
 *
 * A few tests execute Node programs that depend on Linux kernel interfaces with no equivalent elsewhere, such as
 * `os.O_PATH` with `/proc/self/fd` reopening, the `/proc/net/tcp` listener tables, or POSIX ACLs. On Linux, such as
 * in CI or on beast, the test runs directly. On another host, such as macOS, `delegate()` copies the Gateway
 * directory to the Linux test host, runs the same test there over SSH, and asserts that it passed. The host is
 * beast, an Ubuntu machine like the Nodes, unless ORBIT_LINUX_TEST_HOST names another SSH host. Beast has `acl`, a
 * `caddy` service account, and passwordless `sudo`, as the CI host does.
 *
 * Each test process copies the Gateway once and reuses the copy for every test it delegates. The copy holds only
 * the files Git would track, as `git ls-files --cached --others --exclude-standard` lists them, and `vendor/`, so
 * ignored files such as `.env`, keys, logs, caches, and databases never leave the machine. It lives in a mode 700
 * directory under a mode 700 base directory of the remote account. The process removes its copy when it ends,
 * also on Ctrl-C or SIGTERM. The process touches a marker in its copy before each test; a later process removes
 * copies whose marker is older than six hours, such as those of a run ended by SIGKILL. A test that runs longer
 * than five minutes, or ORBIT_LINUX_TEST_TIMEOUT seconds, is stopped on the host.
 */
final class LinuxHost
{
    public const string HostVariable = 'ORBIT_LINUX_TEST_HOST';

    /** Overrides how many seconds a delegated test may run on the Linux test host. */
    public const string TimeoutVariable = 'ORBIT_LINUX_TEST_TIMEOUT';

    private const string DefaultHost = 'beast';

    /** The remote account's base directory; `id -u` keeps accounts apart. */
    private const string RemoteBase = '/tmp/orbit-gateway-linux-tests-$(id -u)';

    /** The shared base directory of an earlier runner; its old copies are still removed. */
    public const string LegacyBase = '/tmp/orbit-gateway-linux-tests';

    public const int StaleMinutes = 360;

    private const int DefaultTimeoutSeconds = 300;

    /** How long the local side waits beyond the remote timeout before it stops the remote test itself. */
    private const int LocalGraceSeconds = 30;

    private static ?string $directory = null;

    /**
     * Returns false on Linux, where the caller runs its test body. Elsewhere it runs this test on the Linux test
     * host, fails unless exactly this test passed there, and returns true so the caller returns.
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
        $gateway = $directory.'/gateway';
        $command = implode(' ', array_map(escapeshellarg(...), [
            'env', '-i', 'HOME=/tmp', 'LANG=C.UTF-8', 'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'timeout', '--kill-after=10', (string) self::timeoutSeconds(),
            'php', $gateway.'/vendor/bin/pest', '--colors=never', '--display-warnings', '--display-notices', '--display-deprecations',
            '--filter', self::filter($test), substr($file, strlen($root) + 1),
        ]));
        $process = self::ssh(
            sprintf('touch %s && cd %s && %s', escapeshellarg($directory.'/alive'), escapeshellarg($gateway), $command),
            self::timeoutSeconds() + self::LocalGraceSeconds,
        );

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            self::ssh(self::stopScript($directory), 30)->run();
            Assert::fail('The test did not finish on the Linux test host (ssh '.self::host().'), so it was stopped there.');
        }

        $output = $process->getOutput().$process->getErrorOutput();

        // timeout(1) exits with 124 when it stopped the test, or 137 when it had to kill it.
        if (in_array($process->getExitCode(), [124, 137], true)) {
            Assert::fail('The test ran longer than '.self::timeoutSeconds().' seconds on the Linux test host (ssh '
                .self::host()."), so it was stopped there:\n{$output}");
        }

        Assert::assertSame(
            0,
            $process->getExitCode(),
            'The test failed on the Linux test host (ssh '.self::host()."):\n{$output}",
        );
        Assert::assertMatchesRegularExpression(
            '/Tests:\s+1 passed/',
            $output,
            'The Linux test host (ssh '.self::host().") did not run exactly this test:\n{$output}",
        );

        return true;
    }

    public static function timeoutSeconds(): int
    {
        $seconds = getenv(self::TimeoutVariable);

        return is_string($seconds) && ctype_digit($seconds) && (int) $seconds > 0 ? (int) $seconds : self::DefaultTimeoutSeconds;
    }

    public static function host(): string
    {
        $host = getenv(self::HostVariable);

        return is_string($host) && $host !== '' ? $host : self::DefaultHost;
    }

    /**
     * The paths, relative to $root, that the copy holds: every file Git would track, and `vendor`. Environment
     * files other than `.env.example` never leave the machine, even when no ignore rule covers them.
     *
     * @return list<string>
     */
    public static function syncPaths(string $root): array
    {
        $list = new Process(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], $root);

        if ($list->run() !== 0) {
            throw new RuntimeException("Could not list the Gateway files with Git:\n".trim($list->getErrorOutput()));
        }

        // A tracked file deleted from the working tree is still listed, and a file can be listed twice.
        $paths = array_values(array_unique(array_filter(
            explode("\0", $list->getOutput()),
            static fn (string $path): bool => $path !== ''
                && (! str_starts_with(basename($path), '.env') || basename($path) === '.env.example')
                && (is_file($root.'/'.$path) || is_link($root.'/'.$path)),
        )));

        if (is_dir($root.'/vendor')) {
            $paths[] = 'vendor';
        }

        return $paths;
    }

    /**
     * Creates a mode 700 copy directory with a fresh marker under a mode 700 base directory that the remote account
     * owns, removes stale copies, and prints the new directory. $base is a shell word, such as RemoteBase.
     *
     * Copies in the earlier shared $legacy directory have no marker, and rsync gave them the source's modification
     * time, so their change time decides: this account's copies unchanged for $staleMinutes are removed.
     */
    public static function prepareScript(string $base, string $id, string $legacy = self::LegacyBase, int $staleMinutes = self::StaleMinutes): string
    {
        return strtr(<<<'SH'
            set -eu
            base=__BASE__
            mkdir -p "$base"
            if [ -L "$base" ] || [ ! -O "$base" ]; then
                echo "$base is not a directory that this account owns." >&2
                exit 1
            fi
            chmod 700 "$base"
            legacy=__LEGACY__
            if [ -d "$legacy" ] && [ ! -L "$legacy" ] && [ -O "$legacy" ]; then
                find "$legacy" -mindepth 1 -maxdepth 1 -type d -user "$(id -u)" -cmin +__MINUTES__ -exec rm -rf {} + 2>/dev/null || true
                rmdir "$legacy" 2>/dev/null || true
            fi
            for copy in "$base"/*/; do
                [ -d "$copy" ] || continue
                if [ -n "$(find "$copy" -maxdepth 0 -mmin +__MINUTES__)" ] && [ -z "$(find "${copy}alive" -mmin -__MINUTES__ 2>/dev/null)" ]; then
                    rm -rf "$copy"
                fi
            done
            mkdir -m 700 "$base/__ID__"
            touch "$base/__ID__/alive"
            printf '%s\n' "$base/__ID__"
            SH, ['__BASE__' => $base, '__LEGACY__' => escapeshellarg($legacy), '__MINUTES__' => (string) $staleMinutes, '__ID__' => $id]);
    }

    /** Stops a test that still runs from $directory. */
    public static function stopScript(string $directory): string
    {
        if (preg_match('#\A[A-Za-z0-9/_-]+\z#', $directory) !== 1) {
            throw new RuntimeException("Unexpected Linux test host directory [{$directory}].");
        }

        // The bracket keeps the pattern from matching this shell's own command line.
        $pattern = $directory.'/gateway/vendor/bin/pes[t]';

        return 'pkill -f -- '.escapeshellarg($pattern).' || true';
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

    /** Copies the Gateway to this process's directory on the Linux test host once. */
    private static function directory(string $root): string
    {
        if (self::$directory !== null) {
            return self::$directory;
        }

        $prepare = self::ssh(self::prepareScript(self::RemoteBase, bin2hex(random_bytes(8))), 60);

        if ($prepare->run() !== 0) {
            throw new RuntimeException(
                'This test runs a Linux-only Node program on the Linux test host, but `ssh '.self::host().'` failed. '
                .'Make `ssh '.self::host().'` work without a prompt, or set '.self::HostVariable.' to another '
                ."Linux SSH host, then run the tests again.\n".trim($prepare->getErrorOutput()),
            );
        }

        $directory = trim($prepare->getOutput());
        $owner = getmypid();
        $remove = static function () use ($directory, $owner): void {
            // A forked child shares these handlers; only the process that made the copy removes it.
            if (getmypid() === $owner) {
                self::ssh(self::stopScript($directory).'; rm -rf '.escapeshellarg($directory), 60)->run();
            }
        };
        register_shutdown_function($remove);
        InterruptCleanup::register($remove);

        $sync = new Process([
            'rsync', '--archive', '--recursive', '--from0', '--files-from=-',
            '--rsh', 'ssh -o BatchMode=yes -o ConnectTimeout=10',
            $root.'/',
            self::host().':'.$directory.'/gateway/',
        ], timeout: 600);
        $sync->setInput(implode("\0", self::syncPaths($root))."\0");

        if ($sync->run() !== 0) {
            throw new RuntimeException(
                'Could not copy the Gateway to the Linux test host with rsync over `ssh '.self::host().'`:'
                ."\n".trim($sync->getErrorOutput()),
            );
        }

        return self::$directory = $directory;
    }

    /** Runs a POSIX shell script on the Linux test host, whatever the remote account's login shell is. */
    private static function ssh(string $script, float $timeout): Process
    {
        return new Process(
            ['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', self::host(), 'sh -c '.escapeshellarg($script)],
            timeout: $timeout,
        );
    }
}
