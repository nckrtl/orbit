<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayVersion;
use App\Infrastructure\GatewayReleases\ScriptGatewayReleaseSmoke;
use App\Infrastructure\Processes\CommandDeadline;
use Closure;
use DateTimeImmutable;
use Exception;
use Illuminate\Support\Facades\Config;

/**
 * Runs the smoke test of the live Gateway by hand: `bin/gateway-smoke` of the current release,
 * for the current commit unless the caller names one. It changes nothing and writes no release
 * record.
 */
final readonly class SmokeGatewayReleaseAction
{
    public function __construct(
        private GatewayReleaseLayout $layout,
        private ScriptGatewayReleaseSmoke $smoke,
        private CommandDeadline $deadline = new CommandDeadline,
    ) {}

    /** @return array{release: string|null, sha: string, outcome: string, report: array<array-key, mixed>|null} */
    public function execute(?string $commit, ?string $since): array
    {
        return $this->run($this->smoke, $this->commit($commit), $since);
    }

    /**
     * The same smoke inside one API request. The smoke limit is lowered when needed so the run ends
     * within the time the request's command deadline has left, which ends before PHP-FPM ends the
     * request. One smoke runs at a time, because each run and its checks hold several PHP-FPM
     * workers. Checks that did not pass are a result, not an error: the outcome is `failed` and the
     * report says which checks failed and why.
     *
     * @return array{release: string|null, sha: string, outcome: string, report: array<array-key, mixed>|null}
     */
    public function inRequest(?string $commit, ?string $since): array
    {
        $sha = $this->commit($commit);
        $seconds = (int) floor($this->deadline->cap(Config::float('orbit.command_timeout', 570.0) - CommandDeadline::CleanupReserveSeconds));

        try {
            return $this->alone(fn (): array => $this->run($this->smoke->within($seconds), $sha, $since));
        } catch (GatewayReleaseException $exception) {
            $report = $exception->phase['report'] ?? null;

            if ($exception->errorCode !== 'gateway.release_smoke_failed' || ! is_array($report) || ($report['error'] ?? null) !== 'checks_failed') {
                throw $exception;
            }

            return [
                'release' => $this->layout->currentReleaseId(),
                'sha' => $sha,
                'outcome' => 'failed',
                'report' => $report,
            ];
        }
    }

    /**
     * Runs one API smoke while no other runs, under a `flock` on `ORBIT_HOME/gateway-release-smoke.lock` that ends
     * with the process.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    private function alone(Closure $operation): mixed
    {
        $path = rtrim(Config::string('orbit.home'), '/').'/gateway-release-smoke.lock';
        $handle = is_dir(dirname($path)) ? @fopen($path, 'ce') : false;

        if ($handle === false) {
            throw new GatewayReleaseException(
                step: 'lock',
                errorCode: 'gateway.release_lock_unavailable',
                message: "The Gateway smoke lock [{$path}] cannot be opened.",
                status: 500,
            );
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw new GatewayReleaseException(
                step: 'smoke',
                errorCode: 'gateway.release_smoke_in_progress',
                message: 'Another smoke run is in progress. Wait for it to finish.',
            );
        }

        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array{release: string|null, sha: string, outcome: string, report: array<array-key, mixed>|null} */
    private function run(ScriptGatewayReleaseSmoke $smoke, string $sha, ?string $since): array
    {
        $result = $smoke->runFrom($this->layout->currentPath(), $sha, $this->since($since));

        return [
            'release' => $this->layout->currentReleaseId(),
            'sha' => $sha,
            'outcome' => $result['outcome'],
            'report' => $result['report'],
        ];
    }

    private function commit(?string $commit): string
    {
        return $commit === null || $commit === '' ? $this->currentCommit() : GatewayReleaseCommit::parse($commit);
    }

    private function currentCommit(): string
    {
        $version = GatewayVersion::resolve(null, $this->layout->currentPath().'/REVISION');

        if ($version === 'dev') {
            $configured = Config::get('app.version');
            $version = is_string($configured) ? strtolower($configured) : 'dev';
        }

        if (preg_match('/\A[0-9a-f]{7,40}\z/D', $version) !== 1) {
            throw new GatewayReleaseException(
                step: 'smoke',
                errorCode: 'gateway.release_commit_invalid',
                message: 'The current Gateway reports no commit. Name the commit by its hex SHA.',
                status: 422,
            );
        }

        return $version;
    }

    private function since(?string $since): ?DateTimeImmutable
    {
        if ($since === null || $since === '') {
            return null;
        }

        try {
            $moment = new DateTimeImmutable($since);
        } catch (Exception) {
            $moment = null;
        }

        if (! $moment instanceof DateTimeImmutable || preg_match('/(?:Z|[+-]\d{2}:?\d{2})\z/D', $since) !== 1) {
            throw new GatewayReleaseException(
                step: 'smoke',
                errorCode: 'gateway.release_since_invalid',
                message: 'Pass --since as an ISO 8601 time with a zone, like 2026-10-07T06:00:00Z.',
                status: 422,
            );
        }

        return $moment;
    }
}
