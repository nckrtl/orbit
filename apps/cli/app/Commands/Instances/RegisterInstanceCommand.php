<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Services\Git\GitWorktreeLocator;
use App\Services\Git\GitWorktreeLocatorException;
use Orbit\Sdk\Requests\AppInstances\RegisterAppInstanceRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;

final class RegisterInstanceCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'instance:register
        {--app= : App slug}
        {--name= : Optional AppInstance name override}
        {--root= : Optional relative web-root override}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Register the caller-local linked Git worktree as a development AppInstance.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
        GitWorktreeLocator $worktrees,
    ): int {
        $app = $this->option('app');

        if (! is_string($app) || $app === '') {
            return $this->renderGatewayFailure('app.slug_required', 'App slug is required.');
        }

        try {
            $worktree = $worktrees->locate();
        } catch (GitWorktreeLocatorException $exception) {
            return $this->renderGatewayFailure('instance.worktree_required', $exception->getMessage());
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $name = $this->option('name');
        $root = $this->option('root');
        $instance = $this->send(
            $connector,
            new RegisterAppInstanceRequest(
                app: $app,
                checkoutPath: $worktree->topLevel,
                name: is_string($name) ? $name : $worktree->defaultName,
                root: is_string($root) ? $root : null,
            ),
            AppInstanceResponse::class,
        );

        if (! $instance instanceof AppInstanceResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($instance->toArray());

            return self::SUCCESS;
        }

        $this->info("Instance [{$instance->name}] registered from caller-local worktree.");
        $this->line("Request ID: {$instance->requestId}");

        return self::SUCCESS;
    }
}
