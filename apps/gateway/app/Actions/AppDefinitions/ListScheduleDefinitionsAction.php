<?php

declare(strict_types=1);

namespace App\Actions\AppDefinitions;

use App\Models\Project;
use App\Models\ScheduleDefinition;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListScheduleDefinitionsAction
{
    /** @return Collection<int, ScheduleDefinition> */
    public function handle(Project $app): Collection
    {
        return $app->scheduleDefinitions()->orderBy('name')->get();
    }
}
