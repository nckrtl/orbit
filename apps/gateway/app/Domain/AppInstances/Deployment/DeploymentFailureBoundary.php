<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Deployment;

enum DeploymentFailureBoundary: string
{
    case Preparation = 'preparation';
    case Environment = 'environment';
    case BeforeActivation = 'before_activation';
    case Activation = 'activation';
    case CacheRefresh = 'cache_refresh';
    case AfterActivation = 'after_activation';
    case RollbackSelection = 'rollback_selection';
    case Operation = 'operation';
}
