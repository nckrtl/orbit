<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

interface TaskEvidenceJudge
{
    /**
     * @param  list<array{id: string, requirement: string, question: string, true: string, false: string, environment: string}>  $criteria
     * @param  array<string, array<string, mixed>>  $evidence
     */
    public function judge(array $criteria, array $evidence): TaskEvidenceResult;
}
