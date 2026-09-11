<?php

declare(strict_types=1);

namespace App\Actions\AppDefinitions;

use App\Models\App as OrbitApp;
use App\Models\ScheduleDefinition;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListScheduleDefinitionsAction
{
    /** @return Collection<int, ScheduleDefinition> */
    public function handle(OrbitApp $app): Collection
    {
        return $app->scheduleDefinitions()->orderBy('name')->get();
    }
}
