<?php

declare(strict_types=1);

namespace App\Actions\ProjectDefinitions;

use App\Data\ProjectDefinitions\ProjectDefinitionInputData;
use App\Domain\ProjectDefinitions\ProjectDefinitionConflict;
use App\Models\Project;
use App\Models\ScheduleDefinition;
use Illuminate\Database\UniqueConstraintViolationException;
use SensitiveParameter;

final readonly class CreateScheduleDefinitionAction
{
    public function execute(Project $project, #[SensitiveParameter] ProjectDefinitionInputData $data): ScheduleDefinition
    {
        try {
            return $project->scheduleDefinitions()->create([
                'app' => $data->app,
                'name' => $data->name,
                'environments' => $data->environments,
                'spec' => $data->spec,
            ]);
        } catch (UniqueConstraintViolationException) {
            ProjectDefinitionConflict::nameTaken('schedule');
        }
    }
}
