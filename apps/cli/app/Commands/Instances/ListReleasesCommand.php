<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Deployments\ListAppInstanceReleasesRequest;
use Orbit\Sdk\Responses\Deployments\DeploymentReleasesResponse;

final class ListReleasesCommand extends DeploymentCommand
{
    #[\Override]
    protected $signature = 'instance:release:list
        {instance : Numeric instance ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List retained production AppInstance releases.';

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
            new ListAppInstanceReleasesRequest($instanceId),
            DeploymentReleasesResponse::class,
            ['List releases', 'Loading releases', 'Loaded releases'],
        );

        if (! $response instanceof DeploymentReleasesResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Release', 'Selected'],
            array_map(fn (string $release): array => [$release, $release === $response->selectedRelease ? 'yes' : 'no'], $response->releases),
            'No retained releases found.',
        ));
        $this->writeHumanMessage('Selected release: '.($response->selectedRelease ?? '—'));
        $this->writeHumanMessage('Request ID: '.$response->requestId);

        return self::SUCCESS;
    }
}
