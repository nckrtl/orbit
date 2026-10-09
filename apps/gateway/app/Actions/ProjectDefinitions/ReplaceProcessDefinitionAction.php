<?php

declare(strict_types=1);

namespace App\Actions\ProjectDefinitions;

use App\Data\ProjectDefinitions\ProjectDefinitionInputData;
use App\Domain\ProjectDefinitions\ProjectDefinitionConflict;
use App\Models\ProcessDefinition;
use Illuminate\Database\UniqueConstraintViolationException;
use SensitiveParameter;

final readonly class ReplaceProcessDefinitionAction
{
    public function execute(
        ProcessDefinition $definition,
        #[SensitiveParameter] ProjectDefinitionInputData $data,
    ): ProcessDefinition {
        try {
            $definition->update([
                'app' => $definition->project->appName($data->app ?? $definition->app, 'definition'),
                'name' => $data->name,
                'environments' => $data->environments,
                'spec' => $data->spec,
            ]);
        } catch (UniqueConstraintViolationException) {
            ProjectDefinitionConflict::nameTaken('process');
        }

        return $definition->refresh();
    }
}
