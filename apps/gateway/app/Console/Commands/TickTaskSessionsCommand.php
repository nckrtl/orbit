<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Tasks\WatchIncomingPullRequestsAction;
use App\Domain\Tasks\TaskBroadcasts;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskTickClock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class TickTaskSessionsCommand extends Command
{
    #[\Override]
    protected $signature = 'tasks:tick';

    #[\Override]
    protected $description = 'Observe running task threads, ask Jev for the next action, and execute it.';

    public function handle(TaskScheduler $scheduler, TaskExtensionState $extension, TaskBroadcasts $broadcasts, TaskTickClock $clock, WatchIncomingPullRequestsAction $incoming): int
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
            $clock->record();
            try {
                $incoming->execute();
            } catch (Throwable $exception) {
                report($exception);
            }
            $decisions = $scheduler->tick();
            $scheduler->releaseStaleReservations();
            $scheduler->removeAbandonedWorkspaces();
            $started = $scheduler->claimAvailable();
        } finally {
            $lock->release();
            $broadcasts->flush();
        }
        $this->info('Routed ['.count($decisions).'] tasks and started ['.$started.'] groups.');

        return self::SUCCESS;
    }
}
