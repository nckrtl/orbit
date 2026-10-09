<?php

declare(strict_types=1);

namespace App\Actions\ProjectDefinitions;

use App\Data\ProjectDefinitions\ProjectDefinitionInputData;
use App\Domain\ProjectDefinitions\ProjectDefinitionConflict;
use App\Models\ProcessDefinition;
use App\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;
use SensitiveParameter;

final readonly class CreateProcessDefinitionAction
{
    public function execute(Project $project, #[SensitiveParameter] ProjectDefinitionInputData $data): ProcessDefinition
    {
        try {
            return $project->processDefinitions()->create([
                'app' => $data->app,
                'name' => $data->name,
                'environments' => $data->environments,
                'spec' => $data->spec,
            ]);
        } catch (UniqueConstraintViolationException) {
            ProjectDefinitionConflict::nameTaken('process');
        }
    }
}
