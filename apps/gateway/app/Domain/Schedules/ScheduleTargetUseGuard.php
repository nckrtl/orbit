<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Schedule;

final readonly class ScheduleTargetUseGuard
{
    public function assertNodeRemovable(Node $node): void
    {
        if (
            Schedule::query()->where('host_node_id', $node->id)->exists()
            || Schedule::query()
                ->where('target_type', Node::class)
                ->where('target_id', $node->id)
                ->exists()
        ) {
            $this->inUse();
        }
    }

    public function assertAppInstanceStable(AppInstance $instance): void
    {
        if (
            Schedule::query()
                ->where('target_type', AppInstance::class)
                ->where('target_id', $instance->id)
                ->exists()
        ) {
            $this->inUse();
        }
    }

    private function inUse(): never
    {
        throw new ResourceOperationException(
            ScheduleErrorCode::TargetInUse->value,
            'A Schedule still uses this target placement.',
            409,
        );
    }
}
