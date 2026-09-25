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
    #[\Override]
    protected $signature = 'project:create
        {slug : Unique project slug}
        {type : Project type (monorepo, laravel-app, laravel-package, or node-package)}
        {repository : Git repository URL}
        {--name= : Optional display name}
        {--default-branch= : Stored default branch; resolve the remote default when omitted}
        {--root=public : Relative web root}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Create a project.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $slug = $this->stringArgument('slug', 'Project slug', 'app.slug_required');

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
                'Project slug is invalid.',
            );
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $root = $this->stringOption('root');

        if ($root === null) {
            return $this->renderGatewayFailure('app.root_required', 'Project root is required.');
        }

        $type = $this->stringArgument('type', 'Project type', 'project.type_required');

        if ($type === null) {
            return self::FAILURE;
        }

        if (! in_array($type, ['monorepo', 'laravel-app', 'laravel-package', 'node-package'], true)) {
            return $this->renderGatewayFailure(
                'project.type_invalid',
                'Project type must be monorepo, laravel-app, laravel-package, or node-package.',
            );
        }

        $app = $this->sendWithProgress(
            $connector,
            new CreateAppRequest(
                slug: $slug,
                repositoryUrl: $repositoryUrl,
                root: $root,
                type: $type,
                name: $this->stringOption('name'),
                defaultBranch: $this->stringOption('default-branch'),
            ),
            AppResponse::class,
            ['Create Project', 'Creating Project', 'Created Project'],
        );

        if (! $app instanceof AppResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($app->toArray());

            return self::SUCCESS;
        }

        $this->writeHumanMessage("Project [{$app->slug}] created.");
        $this->writeHumanMessage("Request ID: {$app->requestId}");

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
