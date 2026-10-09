<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Instances\ListInstancesRequest;
use Orbit\Sdk\Responses\Instances\InstanceRemovalProgressResponse;
use Orbit\Sdk\Responses\Instances\InstancesResponse;
use Orbit\Sdk\Responses\Projects\ProjectAppResponse;

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

        $response = $this->sendWithProgress($connector, new ListInstancesRequest, InstancesResponse::class, ['List Instances', 'Loading Instances', 'Loaded Instances']);

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
                $instance->projectId,
                $instance->nodeId,
                $instance->vitePort ?? null,
                $instance->name,
                $instance->sourceLayout,
                implode(', ', array_map(static fn (ProjectAppResponse $app): string => $app->name, $instance->apps)) ?: null,
                $instance->selectedBranch ?? null,
                $instance->branchOverride ?? null,
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
                'Source layout',
                'Apps',
                'Selected branch',
                'Branch override',
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

    private function removalSummary(InstanceRemovalProgressResponse $removal): string
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
