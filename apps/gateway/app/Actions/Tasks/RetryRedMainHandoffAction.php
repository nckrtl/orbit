<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Task;

final readonly class RetryRedMainHandoffAction
{
    public function __construct(private RetryTaskHandoffAction $retry) {}

    public function execute(Task $task): bool
    {
        if (! $this->retry->queue($task)) {
            return false;
        }
        $this->retry->recover($task);

        return true;
    }
}
