<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Project;
use App\Models\TaskDefinition;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class ShowTaskDefinitionAction
{
    public function __construct(private RequireTasksExtensionAction $extension) {}

    public function execute(Project $project, string $name): TaskDefinition
    {
        $this->extension->execute();
        $definition = $project->taskDefinitions()->where('name', $name)->first();

        if (! $definition instanceof TaskDefinition) {
            throw (new ModelNotFoundException)->setModel(TaskDefinition::class, [$name]);
        }

        return $definition;
    }
}
