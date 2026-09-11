<?php

declare(strict_types=1);

namespace App\Actions\AppDefinitions;

use App\Models\ScheduleDefinition;

final readonly class ShowScheduleDefinitionAction
{
    public function handle(ScheduleDefinition $definition): ScheduleDefinition
    {
        return $definition;
    }
}
