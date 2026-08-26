<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Processes\RestartProcessRequest;

final class RestartProcessCommand extends ProcessActionCommand
{
    #[\Override]
    protected $signature = 'process:restart
        {process : Numeric process ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Restart one process.';

    protected function request(int $processId): GatewayRequest
    {
        return new RestartProcessRequest($processId);
    }

    protected function pastTense(): string
    {
        return 'restarted';
    }
}
