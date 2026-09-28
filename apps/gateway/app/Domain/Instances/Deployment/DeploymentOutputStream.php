<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

enum DeploymentOutputStream: string
{
    case Stdout = 'stdout';
    case Stderr = 'stderr';
}
