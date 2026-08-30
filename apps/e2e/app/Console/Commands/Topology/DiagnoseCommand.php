<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\TopologyProofRunner;
use App\E2E\Value\AttemptId;
use App\E2E\Value\OperationId;
use Throwable;

final class DiagnoseCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology:diagnose {issue} {attempt} {--json}';
    #[\Override]
    protected $description = 'Move one exact active proved attempt to diagnosis; it never becomes proved again';

    public function handle(TopologyProofRunner $runner, OperationId $operation): int
    {
        try {
            $result = $runner->diagnose(
                (string) $this->argument('issue'),
                new AttemptId((string) $this->argument('attempt')),
            );
            $this->outputJson(
                [
                    'state' => $result->status->value,
                    'operation_id' => $operation->value,
                    'issue' => $result->issue,
                    'attempt_id' => $result->attempt->value,
                    'proof' => $result->summary(),
                ],
                $result->status->value.' '.$result->attempt->value,
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->outputFailure($exception, $operation);

            return self::FAILURE;
        }
    }
}
