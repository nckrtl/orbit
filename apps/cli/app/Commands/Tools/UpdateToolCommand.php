<?php

declare(strict_types=1);

namespace App\Commands\Tools;

use App\Support\Console\ProgressState;
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
    protected function message(ToolResponse $tool): string
    {
        return match ($tool->outcome) {
            'applied' => "Tool [{$tool->package}] updated.",
            'unchanged' => "Tool [{$tool->package}] is already current.",
            default => "Tool [{$tool->package}] update blocked by constraint [{$tool->versionConstraint}].",
        };
    }

    #[\Override]
    protected function accepts(ToolResponse $tool): bool
    {
        return
            in_array($tool->outcome, ['applied', 'unchanged'], strict: true)
            || $tool->outcome === 'blocked_by_constraint'
            && $tool->versionConstraint !== null
            && $tool->versionConstraint !== '';
    }

    #[\Override]
    protected function progressLabels(): array
    {
        return ['Update Tool', 'Updating Tool', 'Updated Tool'];
    }

    #[\Override]
    protected function resultState(ToolResponse $tool): ProgressState
    {
        return match ($tool->outcome) {
            'applied' => ProgressState::Success,
            'unchanged' => ProgressState::Skipped,
            default => ProgressState::Warning,
        };
    }
}
