<?php

declare(strict_types=1);

namespace App\Actions\ProjectDefinitions;

use App\Models\ProcessDefinition;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListProcessDefinitionsAction
{
    /** @return Collection<int, ProcessDefinition> */
    public function handle(Project $project): Collection
    {
        return $project->processDefinitions()->orderBy('name')->get();
    }
}
