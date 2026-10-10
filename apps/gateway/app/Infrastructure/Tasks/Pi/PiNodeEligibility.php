<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\Pi;

use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;

/** A Node can host Pi threads when its managed `pi-server` Process is recorded as running. */
final readonly class PiNodeEligibility
{
    public function allows(Node $node): bool
    {
        if ($node->platform !== 'linux' || ! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            return false;
        }

        return $node->processes()
            ->where('name', 'pi-server')
            ->where('runtime', ProcessRuntime::Systemd->value)
            ->where('status', LifecycleStatus::Active->value)
            ->where('desired_state', DesiredProcessState::Running->value)
            ->exists();
    }
}
