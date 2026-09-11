<?php

declare(strict_types=1);

namespace App\Actions\AppDefinitions;

use App\Data\AppDefinitions\AppDefinitionInputData;
use App\Domain\AppDefinitions\AppDefinitionConflict;
use App\Models\App as OrbitApp;
use App\Models\ProcessDefinition;
use Illuminate\Database\QueryException;
use SensitiveParameter;

final readonly class CreateProcessDefinitionAction
{
    public function execute(OrbitApp $app, #[SensitiveParameter] AppDefinitionInputData $data): ProcessDefinition
    {
        try {
            return $app->processDefinitions()->create([
                'name' => $data->name,
                'environments' => $data->environments,
                'spec' => $data->spec,
            ]);
        } catch (QueryException $exception) {
            AppDefinitionConflict::nameTaken('process', $exception);
        }
    }
}
