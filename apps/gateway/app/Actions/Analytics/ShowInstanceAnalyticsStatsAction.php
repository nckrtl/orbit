<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Domain\Analytics\AnalyticsStatsDriver;
use App\Models\AppInstance;

final readonly class ShowInstanceAnalyticsStatsAction
{
    public function __construct(
        private ShowInstanceAnalyticsAction $tracking,
        private AnalyticsStatsDriver $driver,
    ) {}

    /** @return array<string, mixed> */
    public function execute(AppInstance $instance): array
    {
        if ($this->tracking->trackingRoutes($instance)->isEmpty() || ! $this->driver->fleetHealthy()) {
            return ['available' => false];
        }

        $domain = $instance->unsetRelation('routes')->authoritativeRoute()?->domain;

        if (! is_string($domain) || $domain === '') {
            return [
                'available' => true,
                'readable' => false,
                'driver' => $this->driver->name(),
                'site_domain' => null,
                'error_code' => 'analytics.stats_domain_missing',
                'error' => 'This App instance has no authoritative domain to map to a Plausible site.',
            ];
        }

        $read = $this->driver->read($domain);

        if (! $read->readable) {
            return [
                'available' => true,
                'readable' => false,
                'driver' => $this->driver->name(),
                'site_domain' => $read->siteDomain,
                'error_code' => $read->errorCode,
                'error' => $read->error,
            ];
        }

        return [
            'available' => true,
            'readable' => true,
            'driver' => $this->driver->name(),
            'site_domain' => $read->siteDomain,
            'live_visitors' => $read->liveVisitors,
            'visitors' => [
                'past_24h' => $read->visitors24h,
                'past_7d' => $read->visitors7d,
                'past_30d' => $read->visitors30d,
            ],
            'pages' => array_map(
                static fn ($page): array => $page->toArray(),
                $read->pages,
            ),
        ];
    }
}
