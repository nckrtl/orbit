<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\T3;

use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;

final readonly class T3NodeEligibility
{
    public function allows(Node $node): bool
    {
        if (
            $node->platform !== 'linux'
            || ! is_string($node->wireguard_ip)
            || $node->wireguard_ip === ''
        ) {
            return false;
        }

        return $node->processes()
            ->where('name', 't3-code')
            ->where('runtime', ProcessRuntime::Systemd->value)
            ->where('status', LifecycleStatus::Active->value)
            ->where('desired_state', DesiredProcessState::Running->value)
            ->exists();
    }
}
