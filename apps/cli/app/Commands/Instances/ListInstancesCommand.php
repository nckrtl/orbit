<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\AppInstances\ListAppInstancesRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstancesResponse;

final class ListInstancesCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'instance:list
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List instances.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->send($connector, new ListAppInstancesRequest, AppInstancesResponse::class);

        if (! $response instanceof AppInstancesResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($response->appInstances as $instance) {
            $rows[] = [
                $instance->id,
                $instance->appId,
                $instance->nodeId,
                $instance->clusterId,
                $instance->name,
                $instance->environment,
                $instance->effectiveRoot ?? '-',
                $instance->branch ?? '-',
                $instance->status,
            ];
        }

        $this->table(['ID', 'App', 'Node', 'Cluster', 'Name', 'Environment', 'Root', 'Branch', 'Status'], $rows);
        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
