<?php

declare(strict_types=1);

namespace App\Commands\Tools;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tools\ShowToolRequest;
use Orbit\Sdk\Responses\Tools\ToolResponse;

final class ShowToolCommand extends ToolCommand
{
    #[\Override]
    protected $signature = 'tool:show {tool : Numeric tool ID} {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show a tool.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $id = $this->toolId();
        if ($id === null) {
            return self::FAILURE;
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $response = $this->sendWithProgress(
            $connector,
            new ShowToolRequest($id),
            ToolResponse::class,
            ['Show Tool', 'Loading Tool', 'Loaded Tool'],
        );
        if (! $response instanceof ToolResponse) {
            return self::FAILURE;
        }

        return $this->renderTool($response, "Tool [{$response->package}].");
    }
}
