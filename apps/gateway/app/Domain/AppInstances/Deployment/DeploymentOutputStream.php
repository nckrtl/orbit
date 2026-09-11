<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Deployment;

enum DeploymentOutputStream: string
{
    case Stdout = 'stdout';
    case Stderr = 'stderr';
}
