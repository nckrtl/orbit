<?php

declare(strict_types=1);

namespace Tests\Support\Schedules;

use App\Domain\Schedules\ScheduleRuntimeAccount;
use App\Domain\Schedules\ScheduleRuntimeAccountResolver;
use App\Models\Node;

final class FakeScheduleRuntimeAccountResolver implements ScheduleRuntimeAccountResolver
{
    public bool $unavailable = false;

    public function resolve(Node $node, string $user): ScheduleRuntimeAccount
    {
        if ($this->unavailable) {
            throw new \RuntimeException('sensitive account inspection');
        }

        $home = $user === $node->user ? '/home/'.$user : '/home/'.$user;

        return new ScheduleRuntimeAccount($user, $user, $home, '/bin/bash');
    }
}
