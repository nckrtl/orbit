<?php

declare(strict_types=1);

namespace App\Data\GatewayReleases;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Why automatic releases are paused: `migration_failure`, `rollback`, `manual_deploy`,
 * `interrupted`, or `marker` when only `ORBIT_HOME/gateway-release.paused` exists. `record` names
 * the release record that caused the pause.
 *
 * @phpstan-import-type Pause from \App\Domain\GatewayReleases\GatewayReleaseAutomation
 */
#[MapOutputName(SnakeCaseMapper::class)]
final class GatewayReleasePauseData extends Data
{
    public function __construct(
        public string $reason,
        public ?string $since,
        public ?int $record,
        public ?string $release,
        public ?string $sha,
        public ?string $errorCode,
        public ?string $snapshot,
    ) {}

    /** @param Pause $pause */
    public static function fromPause(array $pause): self
    {
        return new self(
            reason: $pause['reason'],
            since: $pause['since'],
            record: $pause['record'],
            release: $pause['release'],
            sha: $pause['sha'],
            errorCode: $pause['error_code'],
            snapshot: $pause['snapshot'],
        );
    }
}
