<?php

declare(strict_types=1);

namespace App\Domain\Routes;

enum RouteTargetSetStep: string
{
    case Reserved = 'reserved';
    case WorkloadPrepared = 'workload-prepared';
    case EnvironmentSynchronized = 'environment-synchronized';
    case DatabaseCommitted = 'database-committed';
    case RouterPublished = 'router-published';
    case VacatedPublished = 'vacated-published';
    case Removal = 'removal';
    case Completed = 'completed';
}
