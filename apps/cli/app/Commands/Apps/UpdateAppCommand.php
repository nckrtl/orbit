<?php

declare(strict_types=1);

namespace App\Commands\Apps;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Apps\UpdateAppRequest;
use Orbit\Sdk\Responses\Apps\AppResponse;

final class UpdateAppCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'app:update
        {app : Numeric app ID}
        {--slug= : New App slug}
        {--repository= : New repository access URL}
        {--default-branch= : New stored default branch}
        {--root= : New relative web root}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Update an app.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $appId = $this->positiveId('app', 'App', 'app.id_invalid');

        if ($appId === null) {
            return self::FAILURE;
        }

        $slug = $this->stringOption('slug');
        $repositoryUrl = $this->stringOption('repository');
        $defaultBranch = $this->stringOption('default-branch');
        $root = $this->stringOption('root');

        if ($slug !== null && (strlen($slug) > 63 || preg_match('/[\x00-\x1F\x7F]/', $slug) === 1)) {
            return $this->renderGatewayFailure(
                'app.slug_invalid',
                'App slug is invalid.',
            );
        }

        if ($repositoryUrl !== null && ! $this->hasSafeRepositoryInput($repositoryUrl)) {
            return $this->renderGatewayFailure(
                'app.repository_invalid',
                'Repository URL is invalid.',
            );
        }

        if ($slug === null && $repositoryUrl === null && $defaultBranch === null && $root === null) {
            return $this->renderGatewayFailure(
                'app.update_required',
                'Provide at least one App update.',
            );
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $app = $this->send(
            $connector,
            new UpdateAppRequest(
                appId: $appId,
                slug: $slug,
                repositoryUrl: $repositoryUrl,
                defaultBranch: $defaultBranch,
                root: $root,
            ),
            AppResponse::class,
        );

        if (! $app instanceof AppResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($app->toArray());

            return self::SUCCESS;
        }

        $this->info("App [{$app->slug}] updated.");
        $this->line("Request ID: {$app->requestId}");

        return self::SUCCESS;
    }

    private function hasSafeRepositoryInput(string $repositoryUrl): bool
    {
        if (
            strlen($repositoryUrl) > 2048
            || preg_match('/\A\S+\z/uD', $repositoryUrl) !== 1
            || str_contains($repositoryUrl, '?')
            || str_contains($repositoryUrl, '#')
            || preg_match('/[\x00-\x20\x7F]/', $repositoryUrl) === 1
            || preg_match('/[\p{C}\p{Z}]/u', $repositoryUrl) === 1
        ) {
            return false;
        }

        if (preg_match('/(?:token|password|secret|key|credential)\s*=/i', $repositoryUrl) === 1) {
            return false;
        }

        $parts = parse_url($repositoryUrl);

        if (! is_array($parts)) {
            return false;
        }

        if (array_key_exists('pass', $parts)) {
            return false;
        }

        $user = $parts['user'] ?? null;

        return $user === null || ($parts['scheme'] ?? null) === 'ssh' && $user === 'git';
    }
}
