<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Dependencies;

enum DependencyRequirementKind: string
{
    case Dependency = 'dependency';
    case Peer = 'peer';
}
