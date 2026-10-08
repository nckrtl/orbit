<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum TaskCompute: string
{
    case Shared = 'shared';
    case Vm = 'vm';
}
