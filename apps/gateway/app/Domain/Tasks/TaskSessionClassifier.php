<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

interface TaskSessionClassifier
{
    public function classifyOutcome(TaskSessionObservation $observation, TaskThreadRole $role): TaskJevDecision;
}
