<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\TopologyAcquirer;
use App\E2E\Value\AttemptId;
use App\E2E\Value\OperationId;
use Throwable;

final class SyncCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology:sync {issue} {attempt} {worktree} {--json}';
    #[\Override]
    protected $description = 'Synchronize the source identity of one exact topology attempt';

    public function handle(TopologyAcquirer $acquirer, OperationId $operation): int
    {
        try {
            $topology = $acquirer->sync(
                (string) $this->argument('issue'),
                new AttemptId((string) $this->argument('attempt')),
                (string) $this->argument('worktree'),
            );
            $this->outputJson([
                'state' => 'ready',
                'operation_id' => $operation->value,
                'attempt_id' => $topology->attempt->value,
                'source' => $topology->source->toArray(),
            ], 'ready');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->outputFailure($exception, $operation);

            return self::FAILURE;
        }
    }
}
