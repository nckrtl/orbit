<?php

declare(strict_types=1);

namespace App\Commands\Apps;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Apps\ListAppsRequest;
use Orbit\Sdk\Responses\Apps\AppsResponse;

final class ListAppsCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'app:list
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List apps.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress($connector, new ListAppsRequest, AppsResponse::class, ['List Apps', 'Loading Apps', 'Loaded Apps']);

        if (! $response instanceof AppsResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($response->apps as $app) {
            $rows[] = [
                $app->id,
                $app->name,
                $app->slug,
                $app->repositoryUrl,
                $app->defaultBranch ?? '—',
                $app->root ?? '—',
            ];
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(['ID', 'Name', 'Slug', 'Repository', 'Default branch', 'Web root'], $rows, 'No Apps found.'));
        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
