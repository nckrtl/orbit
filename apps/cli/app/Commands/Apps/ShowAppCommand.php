<?php

declare(strict_types=1);

namespace App\Commands\Apps;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Apps\ShowAppRequest;
use Orbit\Sdk\Responses\Apps\AppResponse;

final class ShowAppCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'app:show
        {app : Numeric app ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show an app.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $appId = $this->positiveId('app', 'App', 'app.id_invalid');

        if ($appId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $app = $this->sendWithProgress($connector, new ShowAppRequest($appId), AppResponse::class, ['Show App', 'Loading App', 'Loaded App']);

        if (! $app instanceof AppResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($app->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("App: {$app->slug}", [
            'ID' => $app->id,
            'Name' => $app->name,
            'Repository' => $app->repositoryUrl,
            'Default branch' => $app->defaultBranch,
            'Web root' => $app->root,
            'Request ID' => $app->requestId,
        ]));

        return self::SUCCESS;
    }
}
