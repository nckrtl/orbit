<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Deployments\ListAppInstanceDeploymentsRequest;
use Orbit\Sdk\Responses\Deployments\AppInstanceDeploymentResponse;
use Orbit\Sdk\Responses\Deployments\AppInstanceDeploymentsResponse;

final class ListDeploymentsCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'instance:deployment:list
        {instance : Numeric instance ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List recorded deployment history for a production AppInstance, newest first.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');

        if ($instanceId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new ListAppInstanceDeploymentsRequest($instanceId),
            AppInstanceDeploymentsResponse::class,
            ['List deployments', 'Loading deployments', 'Loaded deployments'],
        );

        if (! $response instanceof AppInstanceDeploymentsResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Started', 'Release', 'Branch', 'Commit', 'By', 'Duration', 'Status'],
            array_map(
                fn (AppInstanceDeploymentResponse $deployment): array => [
                    $deployment->startedAt,
                    $deployment->release ?? '—',
                    $deployment->branch ?? '—',
                    $deployment->commit ?? '—',
                    $deployment->triggeredBy ?? '—',
                    $deployment->durationSeconds !== null ? "{$deployment->durationSeconds}s" : '—',
                    $deployment->status,
                ],
                $response->deployments,
            ),
            'No deployment history found.',
        ));
        $this->writeHumanMessage('Request ID: '.$response->requestId);

        return self::SUCCESS;
    }
}
