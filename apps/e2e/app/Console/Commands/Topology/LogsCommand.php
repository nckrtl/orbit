<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\TopologyAcquirer;
use App\E2E\Value\GuestProcess;
use InvalidArgumentException;
use Throwable;

final class LogsCommand extends E2ECommand
{
    #[\Override]
    protected $signature =
        'topology:logs {issue} {role} {name} '
        .self::WORKTREE_OPTION
        .' {--since= : A journalctl time, such as "-5min" or "2026-09-24 06:40:00"}'
        .' {--lines= : Only the last N lines}'
        .' {--json}';

    #[\Override]
    protected $description = 'Print the timestamped output of a spawned process';

    public function handle(TopologyAcquirer $acquirer): int
    {
        try {
            $process = new GuestProcess((string) $this->argument('name'));
            $since = $this->option('since');
            $lines = $this->option('lines');
            if (is_string($lines) && preg_match('/\A[0-9]{1,6}\z/', $lines) !== 1) {
                throw new InvalidArgumentException('--lines must be from 1 to 100000.');
            }
            $argv = $process->logsArgv(
                is_string($since) && $since !== '' ? $since : null,
                is_string($lines) ? (int) $lines : null,
            );
            $request = $this->request();
            $role = (string) $this->argument('role');
            $result = $acquirer->execute($request, $role, $argv);
            if (! $result->successful()) {
                throw new InvalidArgumentException("The journal could not be read: {$result->stderr}");
            }
            $this->outputJson(
                ['state' => 'logs', 'role' => $role, 'name' => $process->name, 'output' => $result->stdout],
                rtrim($result->stdout),
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->outputFailure($exception);

            return self::FAILURE;
        }
    }
}
