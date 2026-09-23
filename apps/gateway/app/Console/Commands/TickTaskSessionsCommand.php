<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskPlannerObserver;
use App\Domain\Tasks\TaskScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class TickTaskSessionsCommand extends Command
{
    #[\Override]
    protected $signature = 'tasks:tick';

    #[\Override]
    protected $description = 'Observe running task threads and waiting planners, ask Jev for the next action, and execute it.';

    public function handle(TaskScheduler $scheduler, TaskExtensionState $extension, TaskPlannerObserver $planners): int
    {
        if (! $extension->enabled()) {
            $this->info('Tasks extension is disabled.');

            return self::SUCCESS;
        }

        $lock = Cache::lock('orbit:tasks:tick', 300);
        if (! $lock->get()) {
            $this->info('Another tasks tick is already running.');

            return self::SUCCESS;
        }

        try {
            $decisions = $scheduler->tick();
            $started = 0;
            while (($group = $scheduler->claimNext()) !== null) {
                $started++;
            }
            $planners->observe();
        } finally {
            $lock->release();
        }
        $this->info('Routed ['.count($decisions).'] tasks and started ['.$started.'] groups.');

        return self::SUCCESS;
    }
}
