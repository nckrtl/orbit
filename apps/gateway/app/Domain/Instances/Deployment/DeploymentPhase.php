<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

enum DeploymentPhase: string
{
    case BeforeActivation = 'before_activation';
    case AfterActivation = 'after_activation';
}
