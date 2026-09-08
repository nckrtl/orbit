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
                $instance->name,
                $instance->environment,
                $instance->sourceLayout,
                $instance->effectiveRoot ?? '-',
                $instance->selectedBranch ?? '-',
                $instance->branchOverride ?? '-',
                $instance->migrationRequired ? 'yes' : 'no',
                $instance->hostname ?? '-',
                $instance->url ?? '-',
                $instance->status,
                $instance->removal === null
                    ? '-'
                    : $this->removalSummary($instance->removal),
            ];
        }

        $this->table(
            [
                'ID',
                'App',
                'Node',
                'Name',
                'Environment',
                'Source layout',
                'Root',
                'Selected branch',
                'Branch override',
                'Migration required',
                'Route hostname',
                'URL',
                'Status',
                'Removal',
            ],
            $rows,
        );
        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }

    private function removalSummary(\Orbit\Sdk\Responses\AppInstances\AppInstanceRemovalProgressResponse $removal): string
    {
        $summary =
            ($removal->force ? 'forced' : 'normal')
            ." {$removal->completed}/{$removal->total} completed"
            ."; {$removal->remaining} remaining"
            .'; '
            .($removal->currentStep ?? '-');

        if ($removal->failedStep !== null) {
            $summary .= "; failed {$removal->failedStep} (".($removal->errorCode ?? '-').')';
        }

        return $summary;
    }
}
