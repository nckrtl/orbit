<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskScheduler;
use Illuminate\Console\Command;

final class TickTaskSessionsCommand extends Command
{
    #[\Override]
    protected $signature = 'tasks:tick';

    #[\Override]
    protected $description = 'Observe running task threads, ask Jev for the next action, and execute it.';

    public function handle(TaskScheduler $scheduler, TaskExtensionState $extension): int
    {
        if (! $extension->enabled()) {
            $this->info('Tasks extension is disabled.');

            return self::SUCCESS;
        }

        $decisions = $scheduler->tick();
        $this->info('Routed ['.count($decisions).'] task groups.');

        return self::SUCCESS;
    }
}
