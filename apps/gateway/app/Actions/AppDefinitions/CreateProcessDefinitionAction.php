<?php

declare(strict_types=1);

namespace App\Actions\AppDefinitions;

use App\Data\AppDefinitions\AppDefinitionInputData;
use App\Domain\AppDefinitions\AppDefinitionConflict;
use App\Models\ProcessDefinition;
use App\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;
use SensitiveParameter;

final readonly class CreateProcessDefinitionAction
{
    public function execute(Project $app, #[SensitiveParameter] AppDefinitionInputData $data): ProcessDefinition
    {
        try {
            return $app->processDefinitions()->create([
                'name' => $data->name,
                'environments' => $data->environments,
                'spec' => $data->spec,
            ]);
        } catch (UniqueConstraintViolationException) {
            AppDefinitionConflict::nameTaken('process');
        }
    }
}
