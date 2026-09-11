<?php

declare(strict_types=1);

namespace App\Actions\AppDefinitions;

use App\Models\App as OrbitApp;
use App\Models\ProcessDefinition;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListProcessDefinitionsAction
{
    /** @return Collection<int, ProcessDefinition> */
    public function handle(OrbitApp $app): Collection
    {
        return $app->processDefinitions()->orderBy('name')->get();
    }
}
