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
    protected $signature = 'project:update
        {project : Numeric project ID}
        {--type= : New Project type}
        {--slug= : New Project slug}
        {--repository= : New repository access URL}
        {--default-branch= : New stored default branch}
        {--root= : New repository-relative root; package types may use .}
        {--task-check= : New task check command for task baselines and handoffs}
        {--clear-task-check : Remove the task check command so tasks run no check command}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Update a project.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $appId = $this->positiveId('project', 'Project', 'app.id_invalid');

        if ($appId === null) {
            return self::FAILURE;
        }

        $type = $this->stringOption('type');
        $slug = $this->stringOption('slug');
        $repositoryUrl = $this->stringOption('repository');
        $defaultBranch = $this->stringOption('default-branch');
        $root = $this->stringOption('root');
        $taskCheck = $this->stringOption('task-check');
        $clearTaskCheck = $this->option('clear-task-check') === true;

        if ($slug !== null && (strlen($slug) > 63 || preg_match('/[\x00-\x1F\x7F]/', $slug) === 1)) {
            return $this->renderGatewayFailure(
                'app.slug_invalid',
                'Project slug is invalid.',
            );
        }

        if ($repositoryUrl !== null && ! $this->hasSafeRepositoryInput($repositoryUrl)) {
            return $this->renderGatewayFailure(
                'app.repository_invalid',
                'Repository URL is invalid.',
            );
        }

        if ($type !== null && ! in_array($type, ['monorepo', 'laravel-app', 'laravel-package', 'node-package'], true)) {
            return $this->renderGatewayFailure(
                'project.type_invalid',
                'Project type must be monorepo, laravel-app, laravel-package, or node-package.',
            );
        }

        if ($taskCheck !== null && $clearTaskCheck) {
            return $this->renderGatewayFailure('app.task_check_conflict', 'Choose either --task-check or --clear-task-check.');
        }

        if ($taskCheck !== null && (trim($taskCheck) === '' || strlen($taskCheck) > 4096)) {
            return $this->renderGatewayFailure('app.task_check_invalid', 'Task check command is invalid.');
        }

        if ($type === null && $slug === null && $repositoryUrl === null && $defaultBranch === null && $root === null && $taskCheck === null && ! $clearTaskCheck) {
            return $this->renderGatewayFailure(
                'app.update_required',
                'Provide at least one Project update.',
            );
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $app = $this->sendWithProgress(
            $connector,
            new UpdateAppRequest(
                appId: $appId,
                type: $type,
                slug: $slug,
                repositoryUrl: $repositoryUrl,
                defaultBranch: $defaultBranch,
                root: $root,
                taskCheck: $clearTaskCheck ? null : $taskCheck,
                taskCheckProvided: $clearTaskCheck || $taskCheck !== null,
            ),
            AppResponse::class,
            ['Update Project', 'Updating Project', 'Updated Project'],
        );

        if (! $app instanceof AppResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($app->toArray());

            return self::SUCCESS;
        }

        $this->writeHumanMessage("Project [{$app->slug}] updated.");
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
