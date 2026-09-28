<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Models\Project;

final readonly class ShowAppAction
{
    public function handle(Project $app): Project
    {
        return $app;
    }
}
