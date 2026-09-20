<?php

declare(strict_types=1);

namespace App\Commands\GitHub;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\GitHub\ShowGitHubAppRequest;
use Orbit\Sdk\Responses\GitHub\GitHubAppResponse;

final class ShowGitHubAppCommand extends GitHubCommand
{
    #[\Override]
    protected $signature = 'github:app:show {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = "Show the Gateway's GitHub App and the accounts that installed it.";

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $connector = $this->connector($repository, $factory);

        if ($connector === null) {
            return self::FAILURE;
        }

        $app = $this->sendWithProgress(
            $connector,
            new ShowGitHubAppRequest,
            GitHubAppResponse::class,
            ['Show GitHub App', 'Loading GitHub App', 'Loaded GitHub App'],
        );

        if (! $app instanceof GitHubAppResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($app->toArray());

            return self::SUCCESS;
        }

        $this->writeApp($app, 'GitHub App.');
        $this->writeHumanMessage("Request ID: {$app->requestId}");

        return self::SUCCESS;
    }
}
