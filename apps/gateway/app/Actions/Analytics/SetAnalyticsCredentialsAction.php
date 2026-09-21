<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Data\Analytics\AnalyticsCredentialsData;
use App\Domain\Analytics\AnalyticsStatsDriver;
use App\Domain\Analytics\AnalyticsStatsKeyStore;
use SensitiveParameter;

final readonly class SetAnalyticsCredentialsAction
{
    public function __construct(
        private AnalyticsStatsKeyStore $keys,
        private AnalyticsStatsDriver $driver,
    ) {}

    public function execute(#[SensitiveParameter] string $apiKey): AnalyticsCredentialsData
    {
        $this->keys->put($apiKey);

        return new AnalyticsCredentialsData(
            configured: true,
            driver: $this->driver->name(),
        );
    }
}
