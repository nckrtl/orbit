<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Instances\ListInstancesRequest;
use Orbit\Sdk\Responses\Instances\InstancesResponse;

final class ListInstancesCommand extends GatewayCommand
{
    protected $signature = 'instance:list
        {--json : Return machine-readable JSON}';

    protected $description = 'List instances.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->send($connector, new ListInstancesRequest);

        if (! $response instanceof InstancesResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($response->instances as $instance) {
            $rows[] = [
                $instance->id,
                $instance->appId,
                $instance->nodeId,
                $instance->name,
                $instance->environment,
                $instance->status,
                $instance->phpVersion,
                $instance->hostname,
            ];
        }

        $this->table(['ID', 'App', 'Node', 'Name', 'Environment', 'Status', 'PHP', 'Hostname'], $rows);
        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
