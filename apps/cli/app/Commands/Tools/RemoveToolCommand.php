<?php

declare(strict_types=1);

namespace App\Commands\Tools;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Tools\RemoveToolRequest;
use Orbit\Sdk\Responses\Tools\ToolResponse;

final class RemoveToolCommand extends ToolActionCommand
{
    #[\Override]
    protected $signature = 'tool:remove
        {tool : Numeric tool ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove one tool.';

    #[\Override]
    protected function request(int $toolId): GatewayRequest
    {
        return new RemoveToolRequest($toolId);
    }

    #[\Override]
    protected function renderSuccess(ToolResponse $tool): int
    {
        $this->info("Tool [{$tool->package}] removed.");
        $this->line("Request ID: {$tool->requestId}");

        return self::SUCCESS;
    }

    #[\Override]
    protected function accepts(ToolResponse $tool): bool
    {
        return $tool->outcome === 'applied';
    }
}
