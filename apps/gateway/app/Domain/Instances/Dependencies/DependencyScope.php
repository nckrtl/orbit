<?php

declare(strict_types=1);

namespace App\Domain\Instances\Dependencies;

enum DependencyScope: string
{
    case Regular = 'regular';
    case Development = 'development';
}
