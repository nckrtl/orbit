<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseSmoke;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Runs `bin/gateway-smoke` of the release under test against the live Gateway: its own CLI, the
 * web app Caddy serves, and the scheduler and agent view units of the stable checkout path. The
 * smoke command bounds itself with `--timeout`. When it runs past that bound by more than a grace
 * period, the Gateway sends it SIGTERM, on which it kills every check command it started, and then
 * kills its process group.
 *
 * @phpstan-import-type SmokeResult from GatewayReleaseSmoke
 */
final readonly class ScriptGatewayReleaseSmoke implements GatewayReleaseSmoke
{
    /** Time the smoke command gets beyond its own limit to start Python and print its result. */
    public const int GraceSeconds = 15;

    /** The smoke report is a few kilobytes. More output than this is cut. */
    private const int MaxOutputBytes = 1_048_576;

    public function __construct(
        private GatewayReleaseLayout $layout,
        private ProcessRunner $processes,
        private string $origin,
        private string $webRoot,
        private int $timeoutSeconds = 90,
        private ?string $writeCheckProject = null,
        private string $caFile = '/etc/caddy/orbit-cert-current/root-ca.pem',
        private int $graceSeconds = self::GraceSeconds,
    ) {}

    public function run(string $id, string $sha, ?DateTimeImmutable $since = null): array
    {
        return $this->runFrom($this->layout->releasePath(GatewayReleaseCommit::assertId($id)), $sha, $since);
    }

    /**
     * Runs the smoke command of the checkout or release at `$root`.
     *
     * @return SmokeResult
     */
    public function runFrom(string $root, string $sha, ?DateTimeImmutable $since = null): array
    {
        $script = $root.'/bin/gateway-smoke';

        if (! is_file($script)) {
            throw new GatewayReleaseException(
                step: 'smoke',
                errorCode: 'gateway.release_smoke_missing',
                message: "The release has no smoke command at [{$script}].",
                status: 500,
                phase: ['script' => $script],
            );
        }

        $arguments = $this->arguments($script, $sha, $since);

        try {
            $result = $this->processes->run(new ProcessInvocation(
                arguments: $arguments,
                timeout: (float) ($this->timeoutSeconds + $this->graceSeconds),
                maxOutputBytes: self::MaxOutputBytes,
                terminateGraceSeconds: 5.0,
                environment: $this->environment(),
            ));
        } catch (ProcessTimedOutException) {
            throw new GatewayReleaseException(
                step: 'smoke',
                errorCode: 'gateway.release_smoke_timeout',
                message: sprintf('Smoke did not finish within %d seconds and was stopped.', $this->timeoutSeconds + $this->graceSeconds),
                status: 500,
                phase: ['command' => $arguments, 'timeout_seconds' => $this->timeoutSeconds + $this->graceSeconds],
            );
        }

        $report = $this->report($result);

        if ($report === null) {
            throw new GatewayReleaseException(
                step: 'smoke',
                errorCode: 'gateway.release_smoke_failed',
                message: "Smoke exited {$result->exitCode} without a JSON result.",
                status: 500,
                result: $result,
                phase: ['command' => $arguments, 'exit_code' => $result->exitCode, 'stderr' => $this->tail($result->stderr)],
            );
        }

        if (! $result->succeeded() || ($report['passed'] ?? null) !== true) {
            $message = is_string($report['message'] ?? null) ? $report['message'] : 'Smoke did not pass.';

            throw new GatewayReleaseException(
                step: 'smoke',
                errorCode: 'gateway.release_smoke_failed',
                message: 'Smoke failed: '.$message,
                status: 500,
                result: $result,
                phase: ['command' => $arguments, 'exit_code' => $result->exitCode, 'report' => $report],
            );
        }

        return ['outcome' => 'passed', 'report' => $report];
    }

    /** @return non-empty-list<string> */
    private function arguments(string $script, string $sha, ?DateTimeImmutable $since): array
    {
        $arguments = [
            $script,
            '--sha', $sha,
            '--timeout', (string) $this->timeoutSeconds,
            '--checkout', $this->layout->currentPath(),
            '--web-dir', $this->webRoot,
            '--web-url', $this->origin.'/',
            '--up-url', $this->origin.'/up',
            '--status-url', $this->origin.'/api/v1/gateway/status',
        ];

        if ($since instanceof DateTimeImmutable) {
            // Unit start times have whole seconds, so a fractional handoff time would reject a unit started in the same second.
            $arguments[] = '--since';
            $arguments[] = $since->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }

        if ($this->writeCheckProject !== null && $this->writeCheckProject !== '') {
            $arguments[] = '--write-check';
            $arguments[] = '--smoke-project';
            $arguments[] = $this->writeCheckProject;
        }

        return $arguments;
    }

    /**
     * Python's HTTPS calls trust Orbit's root CA through `SSL_CERT_FILE`. An operator's own value
     * wins.
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        $configured = getenv('SSL_CERT_FILE');

        if ((is_string($configured) && $configured !== '') || ! is_file($this->caFile)) {
            return [];
        }

        return ['SSL_CERT_FILE' => $this->caFile];
    }

    /** @return array<array-key, mixed>|null */
    private function report(CommandResult $result): ?array
    {
        $decoded = json_decode(trim($result->stdout), true);

        return is_array($decoded) && is_bool($decoded['passed'] ?? null) ? $decoded : null;
    }

    private function tail(string $output): string
    {
        $output = trim($output);

        return strlen($output) > 2000 ? substr($output, -2000) : $output;
    }
}
