<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\TaskDefinitionConflict;
use App\Domain\Tasks\TaskDefinitionDocument;
use App\Domain\Tasks\TaskDefinitionInvalid;
use App\Domain\Tasks\TaskDefinitionValidator;
use App\Models\TaskDefinition;
use Illuminate\Database\UniqueConstraintViolationException;

final readonly class ReplaceTaskDefinitionAction
{
    public function __construct(
        private RequireTasksExtensionAction $extension,
        private TaskDefinitionValidator $validator,
        private TaskDefinitionDocument $document,
    ) {}

    /** @param array<string, mixed> $definitionInput */
    public function execute(TaskDefinition $definition, array $definitionInput): TaskDefinition
    {
        $this->extension->execute();
        $violations = $this->validator->validate($definitionInput);

        if ($violations !== []) {
            throw new TaskDefinitionInvalid($violations);
        }

        $stored = $this->document->normalize($definitionInput);

        try {
            $definition->update($stored);
        } catch (UniqueConstraintViolationException) {
            TaskDefinitionConflict::nameTaken($stored['name']);
        }

        return $definition->refresh();
    }
}
