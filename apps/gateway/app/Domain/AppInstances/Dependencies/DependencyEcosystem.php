<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Dependencies;

enum DependencyEcosystem: string
{
    case Composer = 'composer';
    case Npm = 'npm';
}
