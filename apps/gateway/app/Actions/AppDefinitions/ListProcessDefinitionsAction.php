<?php

declare(strict_types=1);

namespace App\Actions\AppDefinitions;

use App\Models\ProcessDefinition;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListProcessDefinitionsAction
{
    /** @return Collection<int, ProcessDefinition> */
    public function handle(Project $app): Collection
    {
        return $app->processDefinitions()->orderBy('name')->get();
    }
}
