<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\TopologyAcquirer;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyRequest;
use Throwable;

final class SyncCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology:sync {issue} {worktree} {--json}';
    #[\Override]
    protected $description = 'Synchronize one disposable feature topology';

    public function handle(TopologyAcquirer $acquirer, OperationId $operation): int
    {
        try {
            $topology = $acquirer->sync(
                new TopologyRequest((string) $this->argument('issue'), (string) $this->argument('worktree')),
            );
            $payload = [
                'state' => 'ready',
                'operation_id' => $operation->value,
            ];
            $this->line($this->option('json') ? json_encode($payload, JSON_THROW_ON_ERROR) : 'ready');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->outputFailure($exception, $operation);

            return self::FAILURE;
        }
    }
}
