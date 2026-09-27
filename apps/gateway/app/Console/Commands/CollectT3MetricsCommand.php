<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentMetricCollector;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\TaskAgentSpawner;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Infrastructure\Tasks\T3\T3SendLeaseManager;
use App\Models\AgentThread;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class CollectT3MetricsCommand extends Command
{
    #[\Override]
    protected $signature = 'tasks:collect-t3-metrics';

    #[\Override]
    protected $description = 'Consume T3 thread events and persist model-call metrics independently of viewers.';

    public function handle(AgentDriverRegistry $drivers, TaskExtensionState $extension, T3SendLeaseManager $sendLeases): int
    {
        if (! $extension->enabled()) {
            return self::SUCCESS;
        }

        $sendLeases->pruneExpired();

        AgentThread::query()
            ->where('driver', 't3')
            ->where('external_id', 'not like', TaskAgentSpawner::PendingPrefix.'%')
            ->whereNull('t3_metrics_final_at')
            ->whereDoesntHave('sendLeases', static fn ($leases) => $leases->where('expires_at', '>', now()))
            ->where(static function ($query): void {
                $query->whereNull('t3_metrics_retry_at')->orWhere('t3_metrics_retry_at', '<=', now());
            })
            ->orderByRaw('COALESCE(t3_metrics_retry_at, t3_metrics_collected_at, created_at)')
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->each(function (AgentThread $thread) use ($drivers): void {
                $activityVersion = $thread->t3_metrics_activity_version;
                try {
                    $collector = $drivers->get($thread->driver);
                    if (! $collector instanceof AgentMetricCollector) {
                        return;
                    }

                    if (! $collector->collectMetrics($thread)) {
                        $this->defer($thread, $activityVersion);

                        return;
                    }

                    $values = [
                        't3_metrics_collected_at' => now(),
                        't3_metrics_attempts' => 0,
                        't3_metrics_retry_at' => null,
                    ];
                    if ($this->isSettled($thread)) {
                        $values['t3_metrics_final_at'] = now();
                    }
                    AgentThread::query()
                        ->whereKey($thread->id)
                        ->where('t3_metrics_activity_version', $activityVersion)
                        ->whereDoesntHave('sendLeases', static fn ($leases) => $leases->where('expires_at', '>', now()))
                        ->whereNull('t3_metrics_final_at')
                        ->update($values);
                } catch (Throwable $exception) {
                    $this->defer($thread, $activityVersion);
                    $key = 't3-metrics-collector:failure:'.$thread->id.':'.hash('sha256', $exception::class);
                    if (Cache::add($key, true, now()->addHour())) {
                        report($exception);
                    }
                }
            });

        return self::SUCCESS;
    }

    private function defer(AgentThread $thread, int $activityVersion): void
    {
        $attempts = $thread->t3_metrics_attempts + 1;
        $delay = min(3600, 30 * (2 ** min($attempts - 1, 7)));
        AgentThread::query()
            ->whereKey($thread->id)
            ->where('t3_metrics_activity_version', $activityVersion)
            ->whereDoesntHave('sendLeases', static fn ($leases) => $leases->where('expires_at', '>', now()))
            ->whereNull('t3_metrics_final_at')
            ->update([
                't3_metrics_attempts' => $attempts,
                't3_metrics_retry_at' => now()->addSeconds($delay),
            ]);
    }

    private function isSettled(AgentThread $thread): bool
    {
        $group = $thread->taskGroup;
        if ($group === null || in_array($group->status, [TaskGroupStatus::Completed, TaskGroupStatus::Failed, TaskGroupStatus::Cancelled], true)) {
            return true;
        }

        if ($thread->task_id !== null) {
            $task = $thread->task;
            if ($task === null || in_array($task->status, [TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled], true)) {
                return true;
            }
        }

        return in_array($thread->state, [AgentThreadState::Done, AgentThreadState::Failed], true)
            && $thread->t3_metrics_observed_activity_version === $thread->t3_metrics_activity_version;
    }
}
