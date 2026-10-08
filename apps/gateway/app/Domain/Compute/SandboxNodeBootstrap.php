<?php

declare(strict_types=1);

namespace App\Domain\Compute;

use App\Models\Node;
use App\Models\TaskSandbox;

interface SandboxNodeBootstrap
{
    public function prepare(TaskSandbox $sandbox, Node $node): void;

    public function enroll(TaskSandbox $sandbox, Node $node): Node;
}
