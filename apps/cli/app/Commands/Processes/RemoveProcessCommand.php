<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Processes\RemoveProcessRequest;

final class RemoveProcessCommand extends ProcessActionCommand
{
    #[\Override]
    protected $signature = 'process:remove
        {process : Numeric process ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove one process.';

    protected function request(int $processId): GatewayRequest
    {
        return new RemoveProcessRequest($processId);
    }

    protected function pastTense(): string
    {
        return 'removed';
    }
}
