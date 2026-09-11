<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\DeploymentLayout;

enum DeploymentLayoutStep: string
{
    case Accepted = 'accepted';
    case SourceMoved = 'source_moved';
    case PersistentStatePlaced = 'persistent_state_placed';
    case RuntimePublished = 'runtime_published';
    case RouteProjected = 'route_projected';
    case Completed = 'completed';
}
