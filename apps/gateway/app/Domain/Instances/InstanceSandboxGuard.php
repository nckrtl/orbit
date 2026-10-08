<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCompute;
use App\Models\Instance;
use App\Models\Task;

/** Generic Instance operations must not interpret guest paths on the recorded host. */
final readonly class InstanceSandboxGuard
{
    public static function isSandbox(Instance $instance): bool
    {
        return $instance->task_sandbox_id !== null || ($instance->exists && Task::topLevel()
            ->where('taskable_type', $instance->getMorphClass())
            ->where('taskable_id', $instance->id)
            ->where('task_compute', TaskCompute::Vm->value)->exists());
    }

    public static function assertHostOperation(Instance $instance): void
    {
        if (self::isSandbox($instance)) {
            throw new ResourceOperationException(
                'instance.sandbox_managed',
                'This Instance belongs to a task sandbox. Use its task group to manage the workspace.',
                409,
            );
        }
    }
}
