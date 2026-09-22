<?php

declare(strict_types=1);

namespace App\Domain\Projects;

enum LifecyclePhase: string
{
    case Setup = 'setup';
    case Teardown = 'teardown';
}
