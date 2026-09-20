<?php

declare(strict_types=1);

namespace App\Commands\Apps;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Apps\DestroyAppRequest;
use Orbit\Sdk\Requests\Apps\ShowAppRequest;
use Orbit\Sdk\Responses\Apps\AppResponse;

final class DestroyAppCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'project:destroy
        {project : Numeric project ID}
        {--yes : Confirm removal without prompting}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove a project.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $appId = $this->positiveId('project', 'Project', 'app.id_invalid');

        if ($appId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        if ($this->option('yes') !== true) {
            $existing = $this->sendWithProgress(
                $connector,
                new ShowAppRequest($appId),
                AppResponse::class,
                ['Inspect Project', 'Inspecting Project', 'Inspected Project'],
            );

            if (! $existing instanceof AppResponse || ! $this->confirmAction(
                "Confirm Project removal [{$existing->slug}]?",
                'Project removal cancelled.',
            )) {
                return self::FAILURE;
            }
        }

        $app = $this->sendWithProgress($connector, new DestroyAppRequest($appId), AppResponse::class, ['Remove Project', 'Removing Project', 'Removed Project']);

        if (! $app instanceof AppResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($app->toArray());

            return self::SUCCESS;
        }

        $this->writeHumanMessage("Project [{$app->slug}] removed.");
        $this->writeHumanMessage("Request ID: {$app->requestId}");

        return self::SUCCESS;
    }
}
