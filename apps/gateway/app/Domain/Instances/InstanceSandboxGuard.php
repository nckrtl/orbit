<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;

/**
 * Generic Instance operations must not interpret guest paths on the recorded host. Only the old lanes'
 * workspaces carry a `task_sandbox_id`. A task VM workspace is a normal Instance on its own Node.
 */
final readonly class InstanceSandboxGuard
{
    public static function isSandbox(Instance $instance): bool
    {
        return $instance->task_sandbox_id !== null;
    }
}
