<?php

declare(strict_types=1);

namespace App\Commands\Tools;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Tools\ListToolManagersRequest;
use Orbit\Sdk\Responses\Tools\ToolManagersResponse;

final class ListToolManagersCommand extends ToolCommand
{
    #[\Override]
    protected $signature = 'tool:manager:list {--node= : Numeric target node ID} {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List tool managers for a node.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $node = $this->nodeId();
        if ($node === null) {
            return self::FAILURE;
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $response = $this->sendWithProgress(
            $connector,
            new ListToolManagersRequest($node),
            ToolManagersResponse::class,
            ['List Tool managers', 'Loading Tool managers', 'Loaded Tool managers'],
        );
        if (! $response instanceof ToolManagersResponse) {
            return self::FAILURE;
        }
        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }
        $rows = array_map(fn ($m): array => [
            $m->id,
            $m->name,
            $m->status,
            $m->installedVersion,
            $m->failedStep,
            $m->errorCode,
        ], $response->managers);
        ConsoleWriter::write(
            $this->output,
            $this->humanRenderer()->table(['ID', 'Manager', 'Status', 'Version', 'Failed step', 'Error'], $rows),
        );
        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
