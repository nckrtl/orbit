<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\TopologyAcquirer;
use App\E2E\Value\AttemptId;
use App\E2E\Value\OperationId;
use Throwable;

final class VerifyCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology:verify {issue} {attempt} {--json}';
    #[\Override]
    protected $description = 'Verify one exact topology attempt';

    public function handle(TopologyAcquirer $acquirer, OperationId $operation): int
    {
        try {
            $topology = $acquirer->verify(
                (string) $this->argument('issue'),
                new AttemptId((string) $this->argument('attempt')),
            );
            $this->outputJson([
                'state' => 'verified',
                'operation_id' => $operation->value,
                'attempt_id' => $topology->attempt->value,
                'verification' => $topology->verification->toArray(),
            ], 'verified');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->outputFailure($exception, $operation);

            return self::FAILURE;
        }
    }
}
