<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Processes\StopProcessRequest;

final class StopProcessCommand extends ProcessActionCommand
{
    #[\Override]
    protected $signature = 'process:stop
        {process : Numeric process ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Stop one process.';

    protected function request(int $processId): GatewayRequest
    {
        return new StopProcessRequest($processId);
    }

    protected function pastTense(): string
    {
        return 'stopped';
    }
}
