<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentMetricCollector;
use App\Domain\Tasks\TaskAgentSpawner;
use App\Domain\Tasks\TaskExtensionState;
use App\Models\AgentThread;
use Illuminate\Console\Command;
use Throwable;

final class CollectT3MetricsCommand extends Command
{
    #[\Override]
    protected $signature = 'tasks:collect-t3-metrics';

    #[\Override]
    protected $description = 'Consume T3 thread events and persist model-call metrics independently of viewers.';

    public function handle(AgentDriverRegistry $drivers, TaskExtensionState $extension): int
    {
        if (! $extension->enabled()) {
            return self::SUCCESS;
        }

        AgentThread::query()
            ->where('driver', 't3')
            ->where('external_id', 'not like', TaskAgentSpawner::PendingPrefix.'%')
            ->each(function (AgentThread $thread) use ($drivers): void {
                try {
                    $collector = $drivers->get($thread->driver);
                    if ($collector instanceof AgentMetricCollector) {
                        $collector->collectMetrics($thread);
                    }
                } catch (Throwable) {
                    // The next scheduled collection retries an unavailable Node or stream.
                }
            });

        return self::SUCCESS;
    }
}
