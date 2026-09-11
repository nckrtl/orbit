<?php

declare(strict_types=1);

namespace App\Actions\AppDefinitions;

use App\Data\AppDefinitions\AppDefinitionInputData;
use App\Domain\AppDefinitions\AppDefinitionConflict;
use App\Models\ProcessDefinition;
use Illuminate\Database\QueryException;
use SensitiveParameter;

final readonly class ReplaceProcessDefinitionAction
{
    public function execute(
        ProcessDefinition $definition,
        #[SensitiveParameter] AppDefinitionInputData $data,
    ): ProcessDefinition {
        try {
            $definition->update([
                'name' => $data->name,
                'environments' => $data->environments,
                'spec' => $data->spec,
            ]);
        } catch (QueryException $exception) {
            AppDefinitionConflict::nameTaken('process', $exception);
        }

        return $definition->refresh();
    }
}
