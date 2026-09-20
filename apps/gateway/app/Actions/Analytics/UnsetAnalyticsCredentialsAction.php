<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Data\Analytics\AnalyticsCredentialsData;
use App\Domain\Analytics\AnalyticsStatsDriver;
use App\Domain\Analytics\AnalyticsStatsKeyStore;

final readonly class UnsetAnalyticsCredentialsAction
{
    public function __construct(
        private AnalyticsStatsKeyStore $keys,
        private AnalyticsStatsDriver $driver,
    ) {}

    public function execute(): AnalyticsCredentialsData
    {
        $this->keys->clear();

        return new AnalyticsCredentialsData(
            configured: false,
            driver: $this->driver->name(),
        );
    }
}
