<?php

declare(strict_types=1);

namespace App\Data\Analytics;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** Whether a fleet Stats API key is stored. The key itself never leaves the Gateway. */
#[MapOutputName(SnakeCaseMapper::class)]
final class AnalyticsCredentialsData extends Data
{
    public function __construct(
        public bool $configured,
        public string $driver,
    ) {}
}
