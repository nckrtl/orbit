<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Processes\StartProcessRequest;

final class StartProcessCommand extends ProcessActionCommand
{
    #[\Override]
    protected $signature = 'process:start
        {process : Numeric process ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Start one process.';

    protected function request(int $processId): GatewayRequest
    {
        return new StartProcessRequest($processId);
    }

    protected function pastTense(): string
    {
        return 'started';
    }
}
