<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Deployments\ListInstanceDeploymentsRequest;
use Orbit\Sdk\Responses\Deployments\InstanceDeploymentResponse;
use Orbit\Sdk\Responses\Deployments\InstanceDeploymentsResponse;

final class ListDeploymentsCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'instance:deployment:list
        {instance : Numeric instance ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List recorded Instance deployment history, newest first.';

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
            new ListInstanceDeploymentsRequest($instanceId),
            InstanceDeploymentsResponse::class,
            ['List deployments', 'Loading deployments', 'Loaded deployments'],
        );

        if (! $response instanceof InstanceDeploymentsResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Started', 'Release', 'Branch', 'Commit', 'By', 'Duration', 'Status'],
            array_map(
                fn (InstanceDeploymentResponse $deployment): array => [
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
