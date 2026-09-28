<?php

declare(strict_types=1);

namespace App\Actions\ProjectDefinitions;

use App\Models\ProcessDefinition;

final readonly class ShowProcessDefinitionAction
{
    public function handle(ProcessDefinition $definition): ProcessDefinition
    {
        return $definition;
    }
}
