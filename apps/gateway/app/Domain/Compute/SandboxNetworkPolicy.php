<?php

declare(strict_types=1);

namespace App\Domain\Compute;

use App\Models\TaskSandbox;

interface SandboxNetworkPolicy
{
    public function ensure(TaskSandbox $sandbox): void;

    /** Only after the owned peer has left the fleet. */
    public function remove(TaskSandbox $sandbox): void;
}
