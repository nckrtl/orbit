<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\TopologyAcquirer;
use App\E2E\Value\GuestProcess;
use InvalidArgumentException;
use Throwable;

final class KillCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology:kill {issue} {role} {name} '.self::WORKTREE_OPTION.' {--json}';

    #[\Override]
    protected $description = 'Stop a spawned process; its journal stays readable';

    public function handle(TopologyAcquirer $acquirer): int
    {
        try {
            $process = new GuestProcess((string) $this->argument('name'));
            $request = $this->request();
            $role = (string) $this->argument('role');
            $result = $acquirer->execute($request, $role, $process->killArgv());
            $this->log($request, "role={$role} kill={$process->name} exit={$result->exitCode}");
            if (! $result->successful()) {
                throw new InvalidArgumentException("The process could not be stopped: {$result->stderr}");
            }
            $this->outputJson(
                ['state' => 'killed', 'role' => $role, 'name' => $process->name],
                "stopped {$process->name} on {$role}",
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->outputFailure($exception);

            return self::FAILURE;
        }
    }
}
