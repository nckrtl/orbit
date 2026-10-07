<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayVersion;
use App\Infrastructure\GatewayReleases\ScriptGatewayReleaseSmoke;
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
    ) {}

    /** @return array{release: string|null, sha: string, outcome: string, report: array<array-key, mixed>|null} */
    public function execute(?string $commit, ?string $since): array
    {
        $sha = $commit === null || $commit === '' ? $this->currentCommit() : GatewayReleaseCommit::parse($commit);
        $result = $this->smoke->runFrom($this->layout->currentPath(), $sha, $this->since($since));

        return [
            'release' => $this->layout->currentReleaseId(),
            'sha' => $sha,
            'outcome' => $result['outcome'],
            'report' => $result['report'],
        ];
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
