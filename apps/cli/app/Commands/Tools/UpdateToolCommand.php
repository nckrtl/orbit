<?php

declare(strict_types=1);

namespace App\Commands\Tools;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Tools\UpdateToolRequest;
use Orbit\Sdk\Responses\Tools\ToolResponse;

final class UpdateToolCommand extends ToolActionCommand
{
    #[\Override]
    protected $signature = 'tool:update
        {tool : Numeric tool ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Update one tool.';

    #[\Override]
    protected function request(int $toolId): GatewayRequest
    {
        return new UpdateToolRequest($toolId);
    }

    #[\Override]
    protected function renderSuccess(ToolResponse $tool): int
    {
        $message = match ($tool->outcome) {
            'applied' => "Tool [{$tool->package}] updated.",
            'unchanged' => "Tool [{$tool->package}] is already current.",
            'blocked_by_constraint' => $tool->versionConstraint === null || $tool->versionConstraint === ''
                ? ''
                : "Tool [{$tool->package}] update blocked by constraint [{$tool->versionConstraint}].",
            default => '',
        };

        $this->info($message);
        $this->line("Request ID: {$tool->requestId}");

        return self::SUCCESS;
    }

    #[\Override]
    protected function accepts(ToolResponse $tool): bool
    {
        return (
            in_array($tool->outcome, ['applied', 'unchanged'], strict: true)
            || $tool->outcome === 'blocked_by_constraint'
            && $tool->versionConstraint !== null
            && $tool->versionConstraint !== ''
        );
    }
}
