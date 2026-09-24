<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\State\SecretRedactor;
use App\E2E\TopologyAcquirer;
use App\E2E\Value\GuestProcess;
use InvalidArgumentException;
use Throwable;

final class SpawnCommand extends E2ECommand
{
    use ReadsGuestArgv;

    #[\Override]
    protected $signature =
        'topology:spawn {issue} {role} {name} '
        .self::WORKTREE_OPTION
        .' {--argv=} {--argv-file=} {--json}';

    #[\Override]
    protected $description = 'Start a long-lived process as orbit on a discovery Node and return at once';

    public function handle(TopologyAcquirer $acquirer, SecretRedactor $redactor): int
    {
        try {
            [$argv, $stdin] = $this->commandInput();
            if ($stdin !== null) {
                throw new InvalidArgumentException('A spawned process reads no stdin.');
            }
            $process = new GuestProcess((string) $this->argument('name'));
            $request = $this->request();
            $role = (string) $this->argument('role');
            $result = $acquirer->execute($request, $role, $process->spawnArgv($argv));
            $redactedArgv = json_encode($redactor->redactArgv($argv), JSON_THROW_ON_ERROR);
            $this->log($request, "role={$role} spawn={$process->name} exit={$result->exitCode} argv={$redactedArgv}");
            if (! $result->successful()) {
                throw new InvalidArgumentException(
                    'The process could not start: '.trim($result->stderr.$result->stdout).' Run `kill` first when a process with this name still runs.',
                );
            }
            $this->outputJson(
                ['state' => 'spawned', 'role' => $role, 'name' => $process->name, 'unit' => $process->unit],
                "spawned {$process->name} on {$role} as {$process->unit}",
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->outputFailure($exception);

            return self::FAILURE;
        }
    }
}
