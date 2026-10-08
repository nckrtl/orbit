<?php

declare(strict_types=1);

namespace App\Domain\Compute;

use App\Models\TaskSandbox;

interface ComputeDriver
{
    public function provision(TaskSandbox $sandbox): TaskSandbox;

    public function observe(TaskSandbox $sandbox): TaskSandbox;

    public function sealNetwork(TaskSandbox $sandbox): TaskSandbox;

    public function park(TaskSandbox $sandbox): TaskSandbox;

    public function resume(TaskSandbox $sandbox): TaskSandbox;

    public function destroy(TaskSandbox $sandbox): TaskSandbox;

    public function capacity(): int;
}
