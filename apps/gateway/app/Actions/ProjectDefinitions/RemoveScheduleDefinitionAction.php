<?php

declare(strict_types=1);

namespace App\Actions\ProjectDefinitions;

use App\Models\ScheduleDefinition;

final readonly class RemoveScheduleDefinitionAction
{
    public function execute(ScheduleDefinition $definition): ScheduleDefinition
    {
        $definition->delete();

        return $definition;
    }
}
