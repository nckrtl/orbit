<?php

declare(strict_types=1);

namespace App\Commands\Apps;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Apps\RemoveAppRequest;
use Orbit\Sdk\Responses\Apps\AppResponse;

final class RemoveAppCommand extends GatewayCommand
{
    protected $signature = 'app:remove
        {app : Numeric app ID}
        {--json : Return machine-readable JSON}';

    protected $description = 'Remove an app.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $appId = $this->positiveId('app', 'App');

        if ($appId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $app = $this->send($connector, new RemoveAppRequest($appId));

        if (! $app instanceof AppResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($app->toArray());

            return self::SUCCESS;
        }

        $this->info("App [{$app->slug}] removed.");
        $this->line("Request ID: {$app->requestId}");

        return self::SUCCESS;
    }
}
