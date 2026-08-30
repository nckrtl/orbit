<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\TopologyAcquirer;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyRequest;
use Throwable;

final class AcquireCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology:acquire {issue} {worktree} {--json}';
    #[\Override]
    protected $description = 'Acquire one disposable discovery topology on the mounted worktree';

    public function handle(TopologyAcquirer $acquirer, OperationId $operation): int
    {
        try {
            $topology = $acquirer->acquireDiscovery(
                new TopologyRequest((string) $this->argument('issue'), (string) $this->argument('worktree')),
            );
            $this->outputJson(
                [
                    'state' => $topology->purpose->value,
                    'operation_id' => $operation->value,
                    'attempt_id' => $topology->attempt->value,
                    'topology' => $topology->toArray(),
                ],
                $topology->purpose->value.' '.$topology->attempt->value,
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->outputFailure($exception, $operation);

            return self::FAILURE;
        }
    }
}
