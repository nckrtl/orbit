<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

interface TaskSessionClassifier
{
    public function classify(TaskSessionObservation $observation): TaskSessionDecision;

    public function classifyOutcome(TaskSessionObservation $observation, TaskThreadRole $role): TaskJevDecision;

    /** @return array<string, TaskTranscriptCheck> */
    public function classifyTranscript(TaskSessionObservation $observation, TaskThreadRole $role): array;
}
