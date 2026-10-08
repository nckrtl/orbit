<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayVersion;
use App\Infrastructure\GatewayReleases\ScriptGatewayReleaseSmoke;
use App\Infrastructure\Nodes\NodeLocks;
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
    /**
     * The time a smoke run gets in an API request: PHP-FPM ends a request at 600 seconds
     * (`NodeLocks::RequestSeconds`), and the response needs time to go out after the run.
     */
    public const int RequestSeconds = NodeLocks::RequestSeconds - 30;

    public function __construct(
        private GatewayReleaseLayout $layout,
        private ScriptGatewayReleaseSmoke $smoke,
    ) {}

    /** @return array{release: string|null, sha: string, outcome: string, report: array<array-key, mixed>|null} */
    public function execute(?string $commit, ?string $since): array
    {
        return $this->run($this->smoke, $this->commit($commit), $since);
    }

    /**
     * The same smoke inside one API request. The smoke limit is lowered when needed so the run ends
     * within RequestSeconds. Checks that did not pass are a result, not an error: the outcome is
     * `failed` and the report says which checks failed and why.
     *
     * @return array{release: string|null, sha: string, outcome: string, report: array<array-key, mixed>|null}
     */
    public function inRequest(?string $commit, ?string $since): array
    {
        $sha = $this->commit($commit);

        try {
            return $this->run($this->smoke->within(self::RequestSeconds), $sha, $since);
        } catch (GatewayReleaseException $exception) {
            $report = $exception->phase['report'] ?? null;

            if ($exception->errorCode !== 'gateway.release_smoke_failed' || ! is_array($report)) {
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
