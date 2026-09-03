<?php

declare(strict_types=1);

namespace App\E2E\Value;

enum TopologyNodePurpose: string
{
    case Gateway = 'gateway';
    case Operator = 'operator';
    case Workload = 'workload';
    case Extension = 'extension';
}
