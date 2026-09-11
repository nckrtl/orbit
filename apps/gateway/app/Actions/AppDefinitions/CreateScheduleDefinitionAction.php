<?php

declare(strict_types=1);

namespace App\Actions\AppDefinitions;

use App\Data\AppDefinitions\AppDefinitionInputData;
use App\Domain\AppDefinitions\AppDefinitionConflict;
use App\Models\App as OrbitApp;
use App\Models\ScheduleDefinition;
use Illuminate\Database\UniqueConstraintViolationException;
use SensitiveParameter;

final readonly class CreateScheduleDefinitionAction
{
    public function execute(OrbitApp $app, #[SensitiveParameter] AppDefinitionInputData $data): ScheduleDefinition
    {
        try {
            return $app->scheduleDefinitions()->create([
                'name' => $data->name,
                'environments' => $data->environments,
                'spec' => $data->spec,
            ]);
        } catch (UniqueConstraintViolationException) {
            AppDefinitionConflict::nameTaken('schedule');
        }
    }
}
