<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\AgentView\AgentStateView;
use App\Domain\Processes\ProcessUsageBroadcaster;
use App\Domain\Tasks\TaskBroadcasts;
use App\Domain\Tasks\TaskWorkspaceSummary;
use Illuminate\Console\Command;
use Throwable;

/**
 * One publish run for the agent view subscriber, which starts it as a child process so its socket
 * loop never waits (ADR 0151). It stores task line counts from the view, sends the task notices, and
 * broadcasts one Process usage sample.
 */
final class AgentViewPublishCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:agent-view-publish {--workspace=* : A changed task workspace as NODE:INSTANCE} {--usage= : Broadcast a Process usage sample with this Unix time}';

    #[\Override]
    protected $description = 'Store task line counts from the agent view and broadcast task notices and Process usage.';

    public function handle(AgentStateView $view, TaskWorkspaceSummary $summary, TaskBroadcasts $broadcasts, ProcessUsageBroadcaster $usage): int
    {
        $failed = false;

        foreach ((array) $this->option('workspace') as $pair) {
            if (! is_string($pair) || preg_match('/\A([1-9][0-9]*):([1-9][0-9]*)\z/D', $pair, $matches) !== 1) {
                continue;
            }

            $workspace = $view->node((int) $matches[1])->workspace((int) $matches[2]);

            if ($workspace === null) {
                continue;
            }

            try {
                $summary->apply((int) $matches[1], $workspace);
            } catch (Throwable $exception) {
                $failed = true;
                $this->error('Task line counts could not be stored: '.$exception->getMessage());
            }
        }

        $broadcasts->flush();
        $sampledAt = $this->option('usage');

        if (is_string($sampledAt) && ctype_digit($sampledAt)) {
            $usage->publish((int) $sampledAt);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
