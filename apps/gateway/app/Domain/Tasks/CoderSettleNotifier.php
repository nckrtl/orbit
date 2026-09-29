<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;

/**
 * Posts the opt-in HMAC-signed Coder settle webhook for a Task group.
 *
 * A no-op implementation returns without sending. A refused remote response
 * must not fail settle.
 */
interface CoderSettleNotifier
{
    public function notify(Task $group): void;

    public function escalate(Task $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void;

    public function assistance(Task $group, string $reason): void;
}
