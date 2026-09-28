<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Models\Project;

final readonly class ShowProjectAction
{
    public function handle(Project $project): Project
    {
        return $project;
    }
}
