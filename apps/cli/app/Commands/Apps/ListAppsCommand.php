<?php

declare(strict_types=1);

namespace App\Commands\Apps;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Apps\ListAppsRequest;
use Orbit\Sdk\Responses\Apps\AppsResponse;

final class ListAppsCommand extends GatewayCommand
{
    protected $signature = 'app:list
        {--json : Return machine-readable JSON}';

    protected $description = 'List apps.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->send($connector, new ListAppsRequest);

        if (! $response instanceof AppsResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($response->apps as $app) {
            $rows[] = [$app->id, $app->name, $app->slug, $app->repositoryUrl];
        }

        $this->table(['ID', 'Name', 'Slug', 'Repository'], $rows);
        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
