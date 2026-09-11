<?php

declare(strict_types=1);

namespace App\Actions\AppDefinitions;

use App\Models\ProcessDefinition;

final readonly class RemoveProcessDefinitionAction
{
    public function execute(ProcessDefinition $definition): ProcessDefinition
    {
        $definition->delete();

        return $definition;
    }
}
