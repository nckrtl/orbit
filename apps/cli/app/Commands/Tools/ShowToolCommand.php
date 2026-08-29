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
        $response = $this->send($connector, new ShowToolRequest($id), ToolResponse::class);
        if (! $response instanceof ToolResponse) {
            return self::FAILURE;
        }
        if ($this->option('json') === true) {
            $this->writeToolJson($response);

            return self::SUCCESS;
        }
        $data = $response->toArray();
        unset($data['request_id']);
        $this->table(
            ['Field', 'Value'],
            array_map(fn ($k, $v): array => [$k, $this->value($v)], array_keys($data), array_values($data)),
        );
        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
