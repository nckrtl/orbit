<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\TaskDefinition;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListTaskDefinitionsAction
{
    public function __construct(private RequireTasksExtensionAction $extension) {}

    /** @return Collection<int, TaskDefinition> */
    public function execute(?int $projectId): Collection
    {
        $this->extension->execute();

        return TaskDefinition::query()
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('project_id')
            ->orderBy('name')
            ->get();
    }
}
