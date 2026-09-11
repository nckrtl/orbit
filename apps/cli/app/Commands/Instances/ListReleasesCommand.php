<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Deployments\ListAppInstanceReleasesRequest;
use Orbit\Sdk\Responses\Deployments\DeploymentReleasesResponse;

final class ListReleasesCommand extends DeploymentCommand
{
    #[\Override]
    protected $signature = 'instance:releases
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

        $response = $this->send(
            $connector,
            new ListAppInstanceReleasesRequest($instanceId),
            DeploymentReleasesResponse::class,
        );

        if (! $response instanceof DeploymentReleasesResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $this->line('Retained releases:');

        if ($response->releases === []) {
            $this->line('- none');
        }

        foreach ($response->releases as $release) {
            $suffix = $release === $response->selectedRelease ? ' (selected)' : '';
            $this->line("- {$release}{$suffix}");
        }

        $this->line('Selected release: '.($response->selectedRelease ?? '-'));
        $this->line('Request ID: '.$response->requestId);

        return self::SUCCESS;
    }
}
