<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;

/**
 * Commits an approved subtask in a Task group's shared checkout.
 */
interface TaskWorkspaceSigner
{
    public function commit(Instance $instance, string $message): ?string;
}
