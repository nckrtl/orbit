<?php

declare(strict_types=1);

namespace App\Actions\ProjectDefinitions;

use App\Models\Project;
use App\Models\ScheduleDefinition;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListScheduleDefinitionsAction
{
    /** @return Collection<int, ScheduleDefinition> */
    public function handle(Project $project): Collection
    {
        return $project->scheduleDefinitions()->orderBy('name')->get();
    }
}
