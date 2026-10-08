<?php

declare(strict_types=1);

namespace App\Data\GatewayReleases;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * The last run of `gateway:release:auto`: when it checked and what it decided.
 *
 * @phpstan-import-type Tick from \App\Domain\GatewayReleases\GatewayReleaseAutomation
 */
#[MapOutputName(SnakeCaseMapper::class)]
final class GatewayReleaseTickData extends Data
{
    public function __construct(
        public string $checkedAt,
        public string $result,
        public ?string $sha,
        public ?int $record,
        public ?string $errorCode,
        public ?string $message,
    ) {}

    /** @param Tick $tick */
    public static function fromTick(array $tick): self
    {
        return new self(
            checkedAt: $tick['checked_at'],
            result: $tick['result'],
            sha: $tick['sha'],
            record: $tick['record'],
            errorCode: $tick['error_code'],
            message: $tick['message'],
        );
    }
}
