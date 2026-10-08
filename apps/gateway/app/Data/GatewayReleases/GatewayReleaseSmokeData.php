<?php

declare(strict_types=1);

namespace App\Data\GatewayReleases;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One smoke run against the live Gateway: the current release, the commit the Gateway must serve,
 * `passed` or `failed`, and the JSON report of `bin/gateway-smoke` with each check.
 */
#[MapOutputName(SnakeCaseMapper::class)]
final class GatewayReleaseSmokeData extends Data
{
    /** @param array<array-key, mixed>|null $report */
    public function __construct(
        public ?string $release,
        public string $sha,
        public string $outcome,
        public ?array $report,
    ) {}

    /** @param array{release: string|null, sha: string, outcome: string, report: array<array-key, mixed>|null} $result */
    public static function fromResult(array $result): self
    {
        return new self(
            release: $result['release'],
            sha: $result['sha'],
            outcome: $result['outcome'],
            report: $result['report'],
        );
    }
}
