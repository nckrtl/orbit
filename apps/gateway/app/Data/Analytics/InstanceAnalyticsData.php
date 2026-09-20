<?php

declare(strict_types=1);

namespace App\Data\Analytics;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class InstanceAnalyticsData extends Data
{
    /** @param list<InstanceAnalyticsHostData> $hosts */
    public function __construct(
        public int $instanceId,
        public bool $enabled,
        public ?string $domain,
        public ?string $dashboardUrl,
        public array $hosts,
        public ?string $snippet,
    ) {}
}
