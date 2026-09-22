<?php

declare(strict_types=1);

namespace App\Actions\Hibernation;

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Hibernation\AppDevHibernationPolicy;
use App\Domain\Hibernation\AppInstanceCheckoutInspector;
use App\Domain\Hibernation\HibernationMarkerStore;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Hibernation\RuntimeHibernationSweepResult;
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
        private AppInstanceCheckoutInspector $checkouts,
        private int $idleSeconds = RuntimeHibernation::DefaultIdleSeconds,
        private int $dependencyIdleSeconds = RuntimeHibernation::DefaultDependencyIdleSeconds,
    ) {}

    public function execute(?Carbon $now = null): RuntimeHibernationSweepResult
    {
        $now ??= Carbon::now();
        $halted = 0;
        $pruned = 0;

        $instances = AppInstance::query()
            ->where('environment', 'development')
            ->whereHas('node.roles', static function ($query): void {
                $query
                    ->where('role', RoleName::AppDev)
                    ->where('status', LifecycleStatus::Active);
            })
            ->orderBy('id')
            ->get(['id', 'node_id', 'checkout_path']);

        foreach ($instances as $instance) {
            $result = $this->admissions->run(
                [(int) $instance->id],
                fn (): RuntimeHibernationSweepResult => $this->sweepOwned($instance, $now),
            );
            $halted += $result->halted;
            $pruned += $result->pruned;
        }

        return new RuntimeHibernationSweepResult($halted, $pruned);
    }

    private function sweepOwned(AppInstance $candidate, Carbon $now): RuntimeHibernationSweepResult
    {
        $instance = AppInstance::query()
            ->whereKey($candidate->id)
            ->where('node_id', $candidate->node_id)
            ->where('checkout_path', $candidate->checkout_path)
            ->with(['node.roles', 'processes'])
            ->first();

        if (
            ! $instance instanceof AppInstance
            || $instance->environment !== 'development'
            || $instance->status !== AppInstanceState::Active
            || $instance->migration_required
            || $instance->provisioning_step !== 'active'
            || $instance->node->status !== LifecycleStatus::Active
            || ! $this->policy->appliesToInstance($instance)
        ) {
            return new RuntimeHibernationSweepResult(0, 0);
        }

        $running = $this->desiredRunning($instance);

        if ($running === []) {
            return new RuntimeHibernationSweepResult(0, 0);
        }

        $key = RuntimeHibernation::key((int) $instance->id);
        $httpActivity = $this->markers->lastActivityUnix($instance->node, $key);
        $halted = 0;

        if ($this->isIdle($httpActivity, $now, $this->idleSeconds)) {
            foreach ($running as $process) {
                $this->runtime->stop($process);
            }

            $this->markers->markAsleep($instance->node, $key);
            $halted = 1;
        }

        return new RuntimeHibernationSweepResult(
            $halted,
            $this->prune($instance, $key, $now, $httpActivity) ? 1 : 0,
        );
    }

    private function prune(AppInstance $instance, string $key, Carbon $now, ?int $httpActivity): bool
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

        $this->checkouts->prune($instance, $state);
        $this->markers->markCold($instance->node, $key);

        return true;
    }

    private function isIdle(?int $activity, Carbon $now, int $window): bool
    {
        return $activity === null || ($now->getTimestamp() - $activity) >= $window;
    }

    private function processLifecycleUnix(AppInstance $instance): ?int
    {
        $times = $instance->processes
            ->map(static fn (Process $process): ?int => $process->updated_at?->getTimestamp())
            ->filter(static fn (?int $time): bool => $time !== null)
            ->all();

        return $times === [] ? null : max($times);
    }

    private function hasKeepAliveDesiredRunning(AppInstance $instance): bool
    {
        return $instance->processes->contains(
            static fn (Process $process): bool => $process->desired_state === DesiredProcessState::Running
                && $process->keep_alive,
        );
    }

    /** @return list<Process> */
    private function desiredRunning(AppInstance $instance): array
    {
        return $instance->processes
            ->filter(static fn (Process $process): bool => $process->desired_state === DesiredProcessState::Running
                && ! $process->keep_alive)
            ->sortBy('id')
            ->values()
            ->all();
    }
}
