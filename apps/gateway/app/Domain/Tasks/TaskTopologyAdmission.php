<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;

/** Prepare declared resources and freshly verify them before baseline or turn dispatch. */
interface TaskTopologyAdmission
{
    /** @throws TaskCapacityException when preparation must wait and retry */
    public function prepare(Task $group, Task $task): void;
}
