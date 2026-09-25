<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs a test in a Linux container when the test host is not Linux.
 *
 * A few tests execute Node programs that depend on Linux kernel interfaces with no equivalent elsewhere, such as
 * `os.O_PATH` with `/proc/self/fd` reopening, or the `/proc/net/tcp` listener tables. On Linux the test runs
 * directly. On another host, such as macOS, `delegate()` runs the same test in a Debian PHP container through the
 * local Docker runtime and asserts that it passed there. The Gateway directory is mounted at its host path, and
 * the test runs as the host user.
 */
final class LinuxContainer
{
    private const string Dockerfile = <<<'DOCKERFILE'
        FROM php:8.5-cli
        RUN apt-get update \
            && apt-get install --yes --no-install-recommends git procps python3 \
            && rm -rf /var/lib/apt/lists/*
        DOCKERFILE;

    private const float TimeoutSeconds = 300.0;

    /** @var array<string, string>|null */
    private static ?array $environment = null;

    /**
     * Returns false on Linux, where the caller runs its test body. Elsewhere it runs this test in a Linux
     * container, fails unless exactly this test passed there, and returns true so the caller returns.
     */
    public static function delegate(TestCase $test): bool
    {
        if (PHP_OS_FAMILY === 'Linux') {
            return false;
        }

        $root = base_path();
        // Pest compiles each test file into a class that records its source file.
        $file = $test::$__filename ?? throw new RuntimeException('The test does not name its Pest source file.');
        $filter = self::filter($test);
        $identity = posix_getuid().':'.posix_getgid();
        $script = implode(' && ', [
            'printf "orbit-test:x:%s:%s::/tmp:/bin/bash\n" "$HOST_UID" "$HOST_GID" >> /etc/passwd',
            'exec setpriv --reuid="$HOST_UID" --regid="$HOST_GID" --clear-groups env HOME=/tmp "$@"',
        ]);
        [$uid, $gid] = explode(':', $identity);

        $process = new Process(
            [
                self::docker(), 'run', '--rm', '--init',
                '--volume', "{$root}:{$root}",
                '--workdir', $root,
                '--env', "HOST_UID={$uid}",
                '--env', "HOST_GID={$gid}",
                self::image(),
                'sh', '-c', $script, 'orbit-linux-test',
                'php', 'vendor/bin/pest', '--colors=never', '--filter', $filter, $file,
            ],
            env: self::environment(),
            timeout: self::TimeoutSeconds,
        );
        $process->run();
        $output = preg_replace('/\e\[[0-9;]*m/', '', $process->getOutput().$process->getErrorOutput()) ?? '';

        Assert::assertSame(
            0,
            $process->getExitCode(),
            "The test failed in the Linux container:\n{$output}",
        );
        Assert::assertMatchesRegularExpression(
            '/Tests:\s+1 passed/',
            $output,
            "The Linux container did not run exactly this test:\n{$output}",
        );

        return true;
    }

    /**
     * Pest filters by test description. A data set case prints as `… with dataset "name"` but filters as
     * `… with data set "dataset "name""`, the PHPUnit form.
     */
    private static function filter(TestCase $test): string
    {
        if (! method_exists($test, 'getPrintableTestCaseMethodName')) {
            throw new RuntimeException('Only Pest tests can run in the Linux container.');
        }

        $description = (string) $test->getPrintableTestCaseMethodName();
        $dataName = $test->dataName();

        if ($dataName === '') {
            return $description;
        }

        $separator = strrpos($description, ' with dataset ');

        if ($separator === false) {
            throw new RuntimeException("Could not find the data set in the test description [{$description}].");
        }

        return substr($description, 0, $separator).' with data set "'.$dataName.'"';
    }

    /** Builds the test image once per Dockerfile version; parallel workers wait for the first build. */
    private static function image(): string
    {
        $tag = 'orbit-gateway-linux-test:'.substr(hash('sha256', self::Dockerfile), 0, 12);
        $inspect = new Process([self::docker(), 'image', 'inspect', $tag], env: self::environment());

        if ($inspect->run() === 0) {
            return $tag;
        }

        $lock = fopen(sys_get_temp_dir().'/orbit-gateway-linux-test-image.lock', 'c');

        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('Could not lock the Linux test image build.');
        }

        try {
            if ($inspect->run() !== 0) {
                $build = new Process([self::docker(), 'build', '--quiet', '--tag', $tag, '-'], env: self::environment(), timeout: 600);
                $build->setInput(self::Dockerfile);

                if ($build->run() !== 0) {
                    throw new RuntimeException("Could not build the Linux test image:\n".$build->getErrorOutput());
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $tag;
    }

    private static function docker(): string
    {
        $docker = new ExecutableFinder()->find('docker', null, ['/opt/homebrew/opt/docker/bin', '/usr/local/opt/docker/bin']);

        if ($docker === null) {
            throw new RuntimeException(
                'This test runs a Linux-only Node program in a Linux container. Install a Docker runtime, for example '
                .'`brew install colima docker && colima start`, then run the tests again.',
            );
        }

        return $docker;
    }

    /**
     * Talks to the active Docker context with an empty client configuration, so a desktop credential helper that
     * is not installed cannot break pulls of the public base image.
     *
     * @return array<string, string>
     */
    private static function environment(): array
    {
        if (self::$environment !== null) {
            return self::$environment;
        }

        $host = getenv('DOCKER_HOST');

        if (! is_string($host) || $host === '') {
            $context = new Process([self::docker(), 'context', 'inspect', '--format', '{{.Endpoints.docker.Host}}']);
            $context->run();
            $host = trim($context->getOutput());
        }

        $configuration = sys_get_temp_dir().'/orbit-gateway-linux-test-docker';

        if (! is_dir($configuration) && ! @mkdir($configuration, 0o700) && ! is_dir($configuration)) {
            throw new RuntimeException('Could not create the Docker client configuration for Linux tests.');
        }

        file_put_contents($configuration.'/config.json', "{}\n");
        $environment = ['DOCKER_CONFIG' => $configuration, 'DOCKER_HOST' => $host];
        $info = new Process([self::docker(), 'info', '--format', '{{.OSType}}'], env: $environment);

        if ($host === '' || $info->run() !== 0 || trim($info->getOutput()) !== 'linux') {
            throw new RuntimeException(
                'This test runs a Linux-only Node program in a Linux container, but no Docker runtime is running. '
                .'Start one, for example with `colima start`, then run the tests again.',
            );
        }

        return self::$environment = $environment;
    }
}
