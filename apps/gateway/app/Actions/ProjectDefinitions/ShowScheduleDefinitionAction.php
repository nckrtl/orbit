<?php

declare(strict_types=1);

namespace App\Actions\ProjectDefinitions;

use App\Models\ScheduleDefinition;

final readonly class ShowScheduleDefinitionAction
{
    public function handle(ScheduleDefinition $definition): ScheduleDefinition
    {
        return $definition;
    }
}
