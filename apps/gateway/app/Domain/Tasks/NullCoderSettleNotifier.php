<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\TaskGroup;

final readonly class NullCoderSettleNotifier implements CoderSettleNotifier
{
    public function notify(TaskGroup $group): void {}

    public function escalate(TaskGroup $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void {}

    public function assistance(TaskGroup $group, string $reason): void {}
}
