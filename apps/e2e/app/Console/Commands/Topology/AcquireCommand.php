<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\TopologyAcquirer;
use App\E2E\Value\AttemptId;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyRequest;
use Throwable;

final class AcquireCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology:acquire {issue} {worktree} {--json}';
    #[\Override]
    protected $description = 'Acquire one disposable feature topology';

    public function handle(TopologyAcquirer $acquirer, OperationId $operation): int
    {
        try {
            $topology = $acquirer->acquire(
                new TopologyRequest((string) $this->argument('issue'), (string) $this->argument('worktree')),
                AttemptId::generate(),
            );
            $payload = [
                'state' => 'ready',
                'operation_id' => $operation->value,
                'topology' => $topology->toArray(),
            ];
            $this->line($this->option('json') ? json_encode($payload, JSON_THROW_ON_ERROR) : 'ready');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->outputFailure($exception, $operation);

            return self::FAILURE;
        }
    }
}
