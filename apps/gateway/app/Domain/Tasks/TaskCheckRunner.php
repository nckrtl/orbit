<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;

interface TaskCheckRunner
{
    /** @param list<array{criterion_id: string, project: string, path: string, test: string}> $references */
    public function run(AppInstance $instance, array $references): TaskCheckResult;

    public function fingerprint(AppInstance $instance): string;
}
