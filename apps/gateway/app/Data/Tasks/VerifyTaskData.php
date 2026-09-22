<?php

declare(strict_types=1);

namespace App\Data\Tasks;

final readonly class VerifyTaskData
{
    /** @param list<array{criterion_id: string, project: string, path: string, test: string}> $references */
    public function __construct(public string $runKey, public array $references) {}
}
