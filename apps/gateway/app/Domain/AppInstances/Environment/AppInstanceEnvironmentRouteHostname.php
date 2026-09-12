<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Environment;

enum AppInstanceEnvironmentRouteHostname: string
{
    case Candidate = 'candidate';
    case Previous = 'previous';
}
