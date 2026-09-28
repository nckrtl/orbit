<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

enum InstanceEnvironmentRouteDomain: string
{
    case Candidate = 'candidate';
    case Authoritative = 'authoritative';
}
