<?php

declare(strict_types=1);

namespace App\Actions\ProjectDefinitions;

use App\Data\ProjectDefinitions\ProjectDefinitionInputData;
use App\Domain\ProjectDefinitions\ProjectDefinitionConflict;
use App\Models\ScheduleDefinition;
use Illuminate\Database\UniqueConstraintViolationException;
use SensitiveParameter;

final readonly class ReplaceScheduleDefinitionAction
{
    public function execute(
        ScheduleDefinition $definition,
        #[SensitiveParameter] ProjectDefinitionInputData $data,
    ): ScheduleDefinition {
        try {
            $definition->update([
                'app' => $definition->project->appName($data->app ?? $definition->app, 'definition'),
                'name' => $data->name,
                'environments' => $data->environments,
                'spec' => $data->spec,
            ]);
        } catch (UniqueConstraintViolationException) {
            ProjectDefinitionConflict::nameTaken('schedule');
        }

        return $definition->refresh();
    }
}
