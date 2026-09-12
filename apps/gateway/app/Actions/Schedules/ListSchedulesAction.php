<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

use App\Data\Schedules\ScheduleData;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Schedule;
use Illuminate\Support\Collection;

final readonly class ListSchedulesAction
{
    public function __construct(private NodeAccessAuthorizer $authorizer) {}

    /** @return Collection<int, array<string, mixed>> */
    public function execute(Node $caller): Collection
    {
        $accessibleNodeIds = $this->authorizer->accessibleNodeIds($caller);

        /** @var Collection<int, array<string, mixed>> */
        return Schedule::query()
            ->where(static function ($query) use ($accessibleNodeIds): void {
                $query
                    ->where(static function ($query) use ($accessibleNodeIds): void {
                        $query
                            ->where('target_type', Node::class)
                            ->whereIn('target_id', $accessibleNodeIds);
                    })
                    ->orWhere(static function ($query) use ($accessibleNodeIds): void {
                        $query
                            ->where('target_type', AppInstance::class)
                            ->whereIn(
                                'target_id',
                                AppInstance::query()
                                    ->select('id')
                                    ->whereIn('node_id', $accessibleNodeIds),
                            );
                    });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->get([
                'id',
                'target_type',
                'target_id',
                'name',
                'calendar',
                'timeout_seconds',
                'desired_timer_state',
                'status',
                'failed_step',
                'error_code',
                'last_run_at',
                'last_run_status',
            ])
            ->map(static fn (Schedule $schedule): array => ScheduleData::fromModel(
                $schedule,
                includeCommand: false,
            )->toArray());
    }
}
