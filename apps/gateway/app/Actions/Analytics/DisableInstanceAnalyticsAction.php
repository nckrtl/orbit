<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Actions\Routes\PublishPublicRouteAction;
use App\Actions\Routes\RemoveRouteAction;
use App\Data\Analytics\InstanceAnalyticsData;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Models\AppInstance;
use App\Models\Route;

final readonly class DisableInstanceAnalyticsAction
{
    public function __construct(
        private ShowInstanceAnalyticsAction $show,
        private PublishPublicRouteAction $publication,
        private RemoveRouteAction $remove,
        private AppInstanceEnvironmentOperationLock $operations,
    ) {}

    public function execute(AppInstance $instance): InstanceAnalyticsData
    {
        return $this->operations->run([$instance->id], function () use ($instance): InstanceAnalyticsData {
            foreach ($this->show->trackingRoutes($instance) as $route) {
                $this->removeRoute($route);
            }

            return $this->show->execute($instance);
        });
    }

    /** An active public edge cannot be removed with its Route, so the host leaves the Ingress first. */
    public function removeRoute(Route $route): void
    {
        $this->remove->execute($this->publication->withdraw($route));
    }
}
