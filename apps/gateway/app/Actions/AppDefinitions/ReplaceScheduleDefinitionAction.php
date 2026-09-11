<?php

declare(strict_types=1);

namespace App\Actions\AppDefinitions;

use App\Data\AppDefinitions\AppDefinitionInputData;
use App\Domain\AppDefinitions\AppDefinitionConflict;
use App\Models\ScheduleDefinition;
use Illuminate\Database\QueryException;
use SensitiveParameter;

final readonly class ReplaceScheduleDefinitionAction
{
    public function execute(
        ScheduleDefinition $definition,
        #[SensitiveParameter] AppDefinitionInputData $data,
    ): ScheduleDefinition {
        try {
            $definition->update([
                'name' => $data->name,
                'environments' => $data->environments,
                'spec' => $data->spec,
            ]);
        } catch (QueryException $exception) {
            AppDefinitionConflict::nameTaken('schedule', $exception);
        }

        return $definition->refresh();
    }
}
