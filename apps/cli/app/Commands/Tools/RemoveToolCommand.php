<?php

declare(strict_types=1);

namespace App\Commands\Tools;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Tools\RemoveToolRequest;
use Orbit\Sdk\Responses\Tools\ToolResponse;

final class RemoveToolCommand extends ToolActionCommand
{
    #[\Override]
    protected $signature = 'tool:remove
        {tool : Numeric tool ID}
        {--yes : Skip the destructive confirmation prompt}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove one tool.';

    #[\Override]
    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $toolId = $this->toolId();

        if ($toolId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        if (! $this->confirmAction(
            "Remove Tool [{$toolId}] and delete its record?",
            'Tool removal cancelled.',
        )) {
            return self::FAILURE;
        }

        return parent::handle($repository, $connectors);
    }

    #[\Override]
    protected function request(int $toolId): GatewayRequest
    {
        return new RemoveToolRequest($toolId);
    }

    #[\Override]
    protected function message(ToolResponse $tool): string
    {
        return "Tool [{$tool->package}] removed.";
    }

    #[\Override]
    protected function accepts(ToolResponse $tool): bool
    {
        return $tool->outcome === 'applied';
    }

    #[\Override]
    protected function progressLabels(): array
    {
        return ['Remove Tool', 'Removing Tool', 'Removed Tool'];
    }
}
