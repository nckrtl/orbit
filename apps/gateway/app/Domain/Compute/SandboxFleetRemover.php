<?php

declare(strict_types=1);

namespace App\Domain\Compute;

use App\Models\TaskSandbox;

interface SandboxFleetRemover
{
    public function assertRemovable(TaskSandbox $sandbox): void;

    /** Called only after destruction intent and model-key revocation are durable. */
    public function remove(TaskSandbox $sandbox): void;
}
