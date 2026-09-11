<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Deployment;

enum DeploymentProgressPhase: string
{
    case SourcePreparation = 'source_preparation';
    case EnvironmentSync = 'environment_sync';
    case BeforeActivation = 'before_activation';
    case Activation = 'activation';
    case PhpRefresh = 'php_refresh';
    case AfterActivation = 'after_activation';
    case Rollback = 'rollback';
}
