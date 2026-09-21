<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\AppInstances\ListAppInstancesRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceRemovalProgressResponse;
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

        $response = $this->sendWithProgress($connector, new ListAppInstancesRequest, AppInstancesResponse::class, ['List Instances', 'Loading Instances', 'Loaded Instances']);

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
                $instance->vitePort ?? null,
                $instance->name,
                $instance->environment,
                $instance->sourceLayout,
                $instance->effectiveRoot ?? null,
                $instance->selectedBranch ?? null,
                $instance->branchOverride ?? null,
                $instance->migrationRequired ? 'yes' : 'no',
                $instance->domain ?? null,
                $instance->url ?? null,
                $instance->status,
                $instance->removal === null
                    ? null
                    : $this->removalSummary($instance->removal),
            ];
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            [
                'ID',
                'Project',
                'Node',
                'Vite port',
                'Name',
                'Environment',
                'Source layout',
                'Root',
                'Selected branch',
                'Branch override',
                'Migration required',
                'Route domain',
                'URL',
                'Status',
                'Removal',
            ],
            $rows,
            'No Instances found.',
        ));
        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }

    private function removalSummary(AppInstanceRemovalProgressResponse $removal): string
    {
        $summary =
            ($removal->force ? 'forced' : 'normal')
            ." {$removal->completed}/{$removal->total} completed"
            ."; {$removal->remaining} remaining"
            .'; '
            .($removal->currentStep ?? '—');

        if ($removal->failedStep !== null) {
            $summary .= "; failed {$removal->failedStep} (".($removal->errorCode ?? '—').')';
        }

        return $summary;
    }
}
