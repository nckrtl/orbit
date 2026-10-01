<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\TaskDefinitionConflict;
use App\Domain\Tasks\TaskDefinitionDocument;
use App\Domain\Tasks\TaskDefinitionInvalid;
use App\Domain\Tasks\TaskDefinitionValidator;
use App\Models\Project;
use App\Models\TaskDefinition;
use Illuminate\Database\UniqueConstraintViolationException;

final readonly class CreateTaskDefinitionAction
{
    public function __construct(
        private RequireTasksExtensionAction $extension,
        private TaskDefinitionValidator $validator,
        private TaskDefinitionDocument $document,
    ) {}

    /** @param array<string, mixed> $definition */
    public function execute(Project $project, array $definition): TaskDefinition
    {
        $this->extension->execute();
        $this->guard($definition);
        $stored = $this->document->normalize($definition);

        if ($project->taskDefinitions()->where('name', $stored['name'])->exists()) {
            TaskDefinitionConflict::nameTaken($stored['name']);
        }

        try {
            return $project->taskDefinitions()->create($stored);
        } catch (UniqueConstraintViolationException) {
            TaskDefinitionConflict::nameTaken($stored['name']);
        }
    }

    /** @param array<string, mixed> $definition */
    private function guard(array $definition): void
    {
        $violations = $this->validator->validate($definition);

        if ($violations !== []) {
            throw new TaskDefinitionInvalid($violations);
        }
    }
}
