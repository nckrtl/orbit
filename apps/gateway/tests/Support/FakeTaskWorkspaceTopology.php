<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tasks\TaskWorkspaceTopology;
use App\Models\Instance;
use RuntimeException;

final class FakeTaskWorkspaceTopology implements TaskWorkspaceTopology
{
    /** @var list<array{string, int, int}> operation, Instance id, group id */
    public array $calls = [];

    public bool $fails = false;

    public function acquire(Instance $workspace, int $groupId): void
    {
        $this->calls[] = ['acquire', $workspace->id, $groupId];
        if ($this->fails) {
            throw new RuntimeException('The topology could not be acquired.');
        }
    }

    public function release(Instance $workspace, int $groupId): void
    {
        $this->calls[] = ['release', $workspace->id, $groupId];
        if ($this->fails) {
            throw new RuntimeException('The topology could not be released.');
        }
    }
}
