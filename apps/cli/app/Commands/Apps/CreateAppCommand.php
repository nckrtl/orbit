<?php

declare(strict_types=1);

namespace App\Commands\Apps;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Apps\CreateAppRequest;
use Orbit\Sdk\Responses\Apps\AppResponse;

final class CreateAppCommand extends GatewayCommand
{
    protected $signature = 'app:new
        {slug : Unique app slug}
        {repository : Git repository URL}
        {--name= : Display name, defaults to the slug}
        {--json : Return machine-readable JSON}';

    protected $description = 'Create an app.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $slug = $this->stringArgument('slug', 'App slug');
        $repositoryUrl = $this->stringArgument('repository', 'Repository URL');

        if ($slug === null || $repositoryUrl === null) {
            return self::FAILURE;
        }

        if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $slug) !== 1) {
            $this->error('App slug must contain lowercase letters, numbers, and single hyphens only.');

            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $app = $this->send($connector, new CreateAppRequest(
            slug: $slug,
            repositoryUrl: $repositoryUrl,
            name: $this->stringOption('name'),
        ));

        if (! $app instanceof AppResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($app->toArray());

            return self::SUCCESS;
        }

        $this->info("App [{$app->slug}] created.");
        $this->line("Request ID: {$app->requestId}");

        return self::SUCCESS;
    }
}
