<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Data\Analytics\InstanceAnalyticsData;
use App\Data\Analytics\InstanceAnalyticsHostData;
use App\Domain\Analytics\AnalyticsHostname;
use App\Domain\Analytics\AnalyticsTrackingHosts;
use App\Domain\Analytics\AnalyticsTrackingUpstream;
use App\Domain\Routes\RouteKind;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Database\Eloquent\Collection;

final readonly class ShowInstanceAnalyticsAction
{
    public function execute(Instance $instance): InstanceAnalyticsData
    {
        $domain = $instance->unsetRelation('routes')->authoritativeRoute()?->domain;
        $routes = $this->trackingRoutes($instance);
        $first = $routes->first();

        return new InstanceAnalyticsData(
            instanceId: $instance->id,
            enabled: $routes->isNotEmpty(),
            domain: $domain,
            dashboardUrl: AnalyticsTrackingUpstream::node() instanceof Node
                ? 'https://'.AnalyticsHostname::Value
                : null,
            hosts: array_values($routes
                ->map(static fn (Route $route): InstanceAnalyticsHostData => InstanceAnalyticsHostData::fromRoute($route, $domain))
                ->all()),
            snippet: $first instanceof Route && $domain !== null
                ? AnalyticsTrackingHosts::snippet($domain, $first->domain)
                : null,
        );
    }

    /** @return Collection<int, Route> */
    public function trackingRoutes(Instance $instance): Collection
    {
        return Route::query()
            ->where('kind', RouteKind::AnalyticsTracking->value)
            ->whereHas('analyticsTracking', static fn ($query) => $query->where('instance_id', $instance->id))
            ->orderBy('id')
            ->get();
    }
}
