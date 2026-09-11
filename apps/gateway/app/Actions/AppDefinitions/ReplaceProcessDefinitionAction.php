<?php

declare(strict_types=1);

namespace App\Actions\AppDefinitions;

use App\Data\AppDefinitions\AppDefinitionInputData;
use App\Domain\AppDefinitions\AppDefinitionConflict;
use App\Models\ProcessDefinition;
use Illuminate\Database\UniqueConstraintViolationException;
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
        } catch (UniqueConstraintViolationException) {
            AppDefinitionConflict::nameTaken('process');
        }

        return $definition->refresh();
    }
}
