<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Processes\DestroyProcessRequest;

final class DestroyProcessCommand extends ProcessActionCommand
{
    #[\Override]
    protected $signature = 'process:destroy
        {process : Numeric process ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Destroy one process.';

    protected function request(int $processId): GatewayRequest
    {
        return new DestroyProcessRequest($processId);
    }

    protected function pastTense(): string
    {
        return 'removed';
    }
}
