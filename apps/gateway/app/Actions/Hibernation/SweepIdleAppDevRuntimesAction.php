<?php

declare(strict_types=1);

namespace App\Actions\Hibernation;

use App\Domain\AgentView\AgentProcessView;
use App\Domain\Hibernation\DevelopmentHibernationPolicy;
use App\Domain\Hibernation\HibernationMarkerStore;
use App\Domain\Hibernation\InstanceCheckoutInspector;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Hibernation\RuntimeHibernationSweepResult;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Instance;
use App\Models\Process;
use Illuminate\Support\Carbon;

final readonly class SweepIdleAppDevRuntimesAction
{
    public function __construct(
        private DevelopmentHibernationPolicy $policy,
        private ProcessAdmissionLock $admissions,
        private ProcessRuntimeManager $runtime,
        private HibernationMarkerStore $markers,
        private InstanceCheckoutInspector $checkouts,
        private int $idleSeconds = RuntimeHibernation::DefaultIdleSeconds,
        private int $dependencyIdleSeconds = RuntimeHibernation::DefaultDependencyIdleSeconds,
        private ?AgentProcessView $agents = null,
    ) {}

    public function execute(?Carbon $now = null): RuntimeHibernationSweepResult
    {
        $now ??= Carbon::now();
        $halted = 0;
        $pruned = 0;

        $instances = Instance::query()
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

            $key = RuntimeHibernation::key((int) $instance->id);
            $httpActivity = $this->markers->lastActivityUnix($instance->node, $key);

            if ($this->isIdle($httpActivity, $now, $this->idleSeconds)) {
                $this->admissions->run([(int) $instance->id], function () use ($instance, $running, $key): void {
                    foreach ($running as $process) {
                        // A fresh agent view that shows the Process stopped saves the stop's SSH commands.
                        if ($this->agents?->isStopped($process) === true) {
                            continue;
                        }

                        $this->runtime->stop($process);
                    }

                    $this->markers->markAsleep($instance->node, $key);
                });

                $halted++;
            }

            if ($this->prune($instance, $key, $now, $httpActivity)) {
                $pruned++;
            }
        }

        return new RuntimeHibernationSweepResult($halted, $pruned);
    }

    private function prune(Instance $instance, string $key, Carbon $now, ?int $httpActivity): bool
    {
        if ($this->hasKeepAliveDesiredRunning($instance)) {
            return false;
        }

        if ($this->markers->isAwake($instance->node, $key) || $this->markers->isCold($instance->node, $key)) {
            return false;
        }

        if (! $this->isIdle($httpActivity, $now, $this->dependencyIdleSeconds)) {
            return false;
        }

        if (! $this->isIdle($this->processLifecycleUnix($instance), $now, $this->dependencyIdleSeconds)) {
            return false;
        }

        $state = $this->checkouts->inspect($instance);

        if (! $this->isIdle($state->sourceTreeLastActivityUnix, $now, $this->dependencyIdleSeconds)) {
            return false;
        }

        if (! $state->hasPrunable()) {
            return false;
        }

        $this->admissions->run([(int) $instance->id], function () use ($instance, $key, $state): void {
            // Cold is recovery intent: wake must restore even when pruning stops between apps.
            $this->markers->markCold($instance->node, $key);
            $this->checkouts->prune($instance, $state);
        });

        return true;
    }

    private function isIdle(?int $activity, Carbon $now, int $window): bool
    {
        return $activity === null || ($now->getTimestamp() - $activity) >= $window;
    }

    private function processLifecycleUnix(Instance $instance): ?int
    {
        $times = $instance->processes
            ->map(static fn (Process $process): ?int => $process->updated_at?->getTimestamp())
            ->filter(static fn (?int $time): bool => $time !== null)
            ->all();

        return $times === [] ? null : max($times);
    }

    private function hasKeepAliveDesiredRunning(Instance $instance): bool
    {
        return $instance->processes->contains(
            static fn (Process $process): bool => $process->desired_state === DesiredProcessState::Running
                && $process->keep_alive,
        );
    }

    /** @return list<Process> */
    private function desiredRunning(Instance $instance): array
    {
        return array_values($instance->processes
            ->filter(static fn (Process $process): bool => $process->desired_state === DesiredProcessState::Running
                && ! $process->keep_alive)
            ->sortBy('id')
            ->all());
    }
}
