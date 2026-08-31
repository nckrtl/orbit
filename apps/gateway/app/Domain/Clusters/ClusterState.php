<?php

declare(strict_types=1);

namespace App\Domain\Clusters;

enum ClusterState: string
{
    case Inactive = 'inactive';
    case Active = 'active';
}
