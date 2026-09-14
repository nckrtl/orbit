<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Environment;

enum AppInstanceEnvironmentRouteDomain: string
{
    case Candidate = 'candidate';
    case Authoritative = 'authoritative';
}
