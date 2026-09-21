<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\TaskGroup;

/**
 * Posts the opt-in HMAC-signed Coder settle webhook for a Task group.
 *
 * A no-op implementation returns without sending. A refused remote response
 * must not fail settle.
 */
interface CoderSettleNotifier
{
    public function notify(TaskGroup $group): void;
}
