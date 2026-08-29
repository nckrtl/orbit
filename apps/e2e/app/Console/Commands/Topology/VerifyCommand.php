<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\TopologyAcquirer;
use App\E2E\Value\OperationId;
use Throwable;

final class VerifyCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology:verify {issue} {--json}';
    #[\Override]
    protected $description = 'Verify one disposable feature topology';

    public function handle(TopologyAcquirer $acquirer, OperationId $operation): int
    {
        try {
            $topology = $acquirer->verify((string) $this->argument('issue'));
            $identity = $operation->value;
            $payload = [
                'state' => 'verified',
                'operation_id' => $identity,
                'verification' => $topology->verification->toArray(),
            ];
            $this->line($this->option('json') ? json_encode($payload, JSON_THROW_ON_ERROR) : 'verified');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->outputFailure($exception, $operation);

            return self::FAILURE;
        }
    }
}
