<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

enum AppInstanceRemovalStep: string
{
    case SourcePreparation = 'source_preparation';
    case RouteTargetClear = 'route_target_clear';
    case SourceFinalization = 'source_finalization';
    case RuntimeCleanup = 'runtime_cleanup';
    case RowDeletion = 'row_deletion';
}
