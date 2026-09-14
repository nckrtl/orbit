<?php

declare(strict_types=1);

namespace App\Actions\Hibernation;

use App\Domain\Hibernation\AppDevHibernationPolicy;
use App\Domain\Hibernation\HibernationMarkerStore;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Models\AppInstance;
use App\Models\Process;
use Illuminate\Support\Carbon;

final readonly class SweepIdleAppDevRuntimesAction
{
    public function __construct(
        private AppDevHibernationPolicy $policy,
        private ProcessAdmissionLock $admissions,
        private ProcessRuntimeManager $runtime,
        private HibernationMarkerStore $markers,
        private int $idleSeconds = RuntimeHibernation::DefaultIdleSeconds,
    ) {}

    public function execute(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $halted = 0;

        $instances = AppInstance::query()
            ->where('environment', 'development')
            ->whereHas('node.roles', static function ($query): void {
                $query
                    ->where('role', RoleName::AppDev)
                    ->where('status', LifecycleStatus::Active);
            })
            ->with(['node', 'processes'])
            ->orderBy('id')
            ->get();

        foreach ($instances as $instance) {
            if (! $this->policy->appliesToInstance($instance)) {
                continue;
            }

            $running = $this->desiredRunning($instance);

            if ($running === []) {
                continue;
            }

            $activity = $this->markers->lastActivityUnix($instance->node, RuntimeHibernation::key((int) $instance->id));

            if ($activity !== null && ($now->getTimestamp() - $activity) < $this->idleSeconds) {
                continue;
            }

            $this->admissions->run([(int) $instance->id], function () use ($instance, $running): void {
                foreach ($running as $process) {
                    $this->runtime->stop($process);
                }

                $this->markers->markAsleep($instance->node, RuntimeHibernation::key((int) $instance->id));
            });

            $halted++;
        }

        return $halted;
    }

    /** @return list<Process> */
    private function desiredRunning(AppInstance $instance): array
    {
        return $instance->processes
            ->filter(static fn (Process $process): bool => $process->desired_state === DesiredProcessState::Running)
            ->sortBy('id')
            ->values()
            ->all();
    }
}
