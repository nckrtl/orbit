<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Analytics\AnalyticsPageStat;
use App\Domain\Analytics\AnalyticsStatsDriver;
use App\Domain\Analytics\AnalyticsStatsRead;

final class FakeAnalyticsStatsDriver implements AnalyticsStatsDriver
{
    public bool $healthy = true;

    public ?AnalyticsStatsRead $read = null;

    /** @var list<string> */
    public array $sites = [];

    public function name(): string
    {
        return 'plausible_ce';
    }

    public function fleetHealthy(): bool
    {
        return $this->healthy;
    }

    public function read(string $siteDomain): AnalyticsStatsRead
    {
        $this->sites[] = $siteDomain;

        return $this->read ?? AnalyticsStatsRead::of(
            $siteDomain,
            2,
            18,
            91,
            340,
            [new AnalyticsPageStat('/', 120), new AnalyticsPageStat('/pricing', 40)],
        );
    }
}
