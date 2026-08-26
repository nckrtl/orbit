<?php

declare(strict_types=1);

namespace App\Commands\Apps;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Apps\CreateAppRequest;
use Orbit\Sdk\Responses\Apps\AppResponse;

/** @mago-expect lint:cyclomatic-complexity The command validates one bounded repository reference before transport. */
final class CreateAppCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'app:new
        {slug : Unique app slug}
        {repository : Git repository URL}
        {--name= : Optional display name}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Create an app.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $slug = $this->stringArgument('slug', 'App slug', 'app.slug_required');

        if ($slug === null) {
            return self::FAILURE;
        }

        $repositoryUrl = $this->stringArgument('repository', 'Repository URL', 'app.repository_required');

        if ($repositoryUrl === null) {
            return self::FAILURE;
        }

        if (! $this->hasSafeRepositoryInput($repositoryUrl)) {
            return $this->renderGatewayFailure(
                'app.repository_invalid',
                'Repository URL is invalid.',
            );
        }

        if (strlen($slug) > 63 || preg_match('/[\x00-\x1F\x7F]/', $slug) === 1) {
            return $this->renderGatewayFailure(
                'app.slug_invalid',
                'App slug is invalid.',
            );
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $app = $this->send(
            $connector,
            new CreateAppRequest(
                slug: $slug,
                repositoryUrl: $repositoryUrl,
                name: $this->stringOption('name'),
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

        $this->info("App [{$app->slug}] created.");
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
