<?php

declare(strict_types=1);

namespace App\Actions\AppDefinitions;

use App\Models\ScheduleDefinition;

final readonly class RemoveScheduleDefinitionAction
{
    public function execute(ScheduleDefinition $definition): ScheduleDefinition
    {
        $definition->delete();

        return $definition;
    }
}
