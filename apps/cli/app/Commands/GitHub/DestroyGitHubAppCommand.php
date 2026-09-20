<?php

declare(strict_types=1);

namespace App\Commands\GitHub;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\GitHub\DestroyGitHubAppRequest;
use Orbit\Sdk\Responses\GitHub\GitHubAppResponse;

final class DestroyGitHubAppCommand extends GitHubCommand
{
    #[\Override]
    protected $signature = 'github:app:destroy
        {--yes : Confirm removal without a prompt}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Delete the GitHub App credentials the Gateway stores.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        if (! $this->confirmAction(
            'Delete the stored GitHub App? Orbit then reads every repository without a credential.',
            'GitHub App removal cancelled.',
            requiredCode: 'input.confirmation_required',
            requiredMessage: 'Non-interactive GitHub App removal requires --yes.',
        )) {
            return self::FAILURE;
        }

        $connector = $this->connector($repository, $factory);

        if ($connector === null) {
            return self::FAILURE;
        }

        $app = $this->sendWithProgress(
            $connector,
            new DestroyGitHubAppRequest,
            GitHubAppResponse::class,
            ['Delete GitHub App', 'Deleting GitHub App', 'Deleted GitHub App'],
        );

        if (! $app instanceof GitHubAppResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($app->toArray());

            return self::SUCCESS;
        }

        $this->writeHumanMessage("Deleted the stored credentials of GitHub App [{$app->name}].");
        $this->writeHumanMessage("Delete the App registration on GitHub: {$app->settingsUrl}");
        $this->writeHumanMessage("Request ID: {$app->requestId}");

        return self::SUCCESS;
    }
}
