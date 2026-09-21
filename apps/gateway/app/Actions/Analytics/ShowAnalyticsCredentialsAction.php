<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Data\Analytics\AnalyticsCredentialsData;
use App\Domain\Analytics\AnalyticsStatsDriver;
use App\Domain\Analytics\AnalyticsStatsKeyStore;

final readonly class ShowAnalyticsCredentialsAction
{
    public function __construct(
        private AnalyticsStatsKeyStore $keys,
        private AnalyticsStatsDriver $driver,
    ) {}

    public function execute(): AnalyticsCredentialsData
    {
        return new AnalyticsCredentialsData(
            configured: $this->keys->configured(),
            driver: $this->driver->name(),
        );
    }
}
