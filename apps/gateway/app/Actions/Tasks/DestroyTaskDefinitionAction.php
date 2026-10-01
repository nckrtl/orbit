<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\TaskDefinition;

final readonly class DestroyTaskDefinitionAction
{
    public function __construct(private RequireTasksExtensionAction $extension) {}

    public function execute(TaskDefinition $definition): void
    {
        $this->extension->execute();
        $definition->delete();
    }
}
