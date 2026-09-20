<?php

declare(strict_types=1);

namespace App\Domain\Hibernation;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;

final readonly class AppDevHibernationPolicy
{
    public function appliesToInstance(AppInstance $instance): bool
    {
        $instance->loadMissing('node.roles');

        return $this->hasActiveAppDevRole($instance->node);
    }

    public function appliesToProcess(Process $process): bool
    {
        if (! AppInstance::isMorphType($process->owner_type)) {
            return false;
        }

        $owner = $process->owner;

        return $owner instanceof AppInstance && $this->appliesToInstance($owner);
    }

    public function usesOnDemandHostStart(AppInstance $instance): bool
    {
        return $this->appliesToInstance($instance);
    }

    public function hasActiveAppDevRole(Node $node): bool
    {
        return $node->roles()
            ->where('role', RoleName::AppDev)
            ->where('status', LifecycleStatus::Active)
            ->exists();
    }
}
