<?php

declare(strict_types=1);

namespace App\Commands\Projects;

use App\Commands\GatewayCommand;
use App\Commands\Projects\Concerns\ParsesTaskWorkspaceRouted;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Projects\UpdateProjectRequest;
use Orbit\Sdk\Responses\Projects\ProjectResponse;

final class UpdateProjectCommand extends GatewayCommand
{
    use ParsesTaskWorkspaceRouted;

    #[\Override]
    protected $signature = 'project:update
        {project : Numeric project ID}
        {--type= : New Project type}
        {--slug= : New Project slug}
        {--repository= : New repository access URL}
        {--source-access= : How Orbit reads a private github.com repository: github_app or gh_cli}
        {--default-branch= : New stored default branch}
        {--root= : New repository-relative root; package types may use .}
        {--task-check= : New task check command for task baselines and handoffs}
        {--clear-task-check : Remove the task check command so tasks run no check command}
        {--task-workspace-routed= : Change routing for future task workspaces (true or false)}
        {--task-compute= : Compute for future task groups (shared or vm)}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Update a project.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $projectId = $this->positiveId('project', 'Project', 'project.id_invalid');

        if ($projectId === null) {
            return self::FAILURE;
        }

        $type = $this->stringOption('type');
        $slug = $this->stringOption('slug');
        $repositoryUrl = $this->stringOption('repository');
        $sourceAccess = $this->stringOption('source-access');
        $defaultBranch = $this->stringOption('default-branch');
        $root = $this->stringOption('root');
        $taskCheck = $this->stringOption('task-check');
        $clearTaskCheck = $this->option('clear-task-check') === true;

        if ($slug !== null && (strlen($slug) > 63 || preg_match('/[\x00-\x1F\x7F]/', $slug) === 1)) {
            return $this->renderGatewayFailure(
                'project.slug_invalid',
                'Project slug is invalid.',
            );
        }

        if ($repositoryUrl !== null && ! $this->hasSafeRepositoryInput($repositoryUrl)) {
            return $this->renderGatewayFailure(
                'project.repository_invalid',
                'Repository URL is invalid.',
            );
        }

        if ($type !== null && ! in_array($type, ['monorepo', 'laravel-app', 'laravel-package', 'node-package'], true)) {
            return $this->renderGatewayFailure(
                'project.type_invalid',
                'Project type must be monorepo, laravel-app, laravel-package, or node-package.',
            );
        }

        if ($sourceAccess !== null && ! in_array($sourceAccess, ['github_app', 'gh_cli'], true)) {
            return $this->renderGatewayFailure(
                'project.source_access_invalid',
                'Source access must be github_app or gh_cli.',
            );
        }

        if ($taskCheck !== null && $clearTaskCheck) {
            return $this->renderGatewayFailure('project.task_check_conflict', 'Choose either --task-check or --clear-task-check.');
        }

        if ($taskCheck !== null && (trim($taskCheck) === '' || strlen($taskCheck) > 4096)) {
            return $this->renderGatewayFailure('project.task_check_invalid', 'Task check command is invalid.');
        }

        $taskCompute = $this->option('task-compute');

        if ($this->input->hasParameterOption('--task-compute') && ! in_array($taskCompute, ['shared', 'vm'], true)) {
            return $this->renderGatewayFailure('project.task_compute_invalid', 'Task compute must be shared or vm.');
        }

        $taskWorkspaceRouted = $this->taskWorkspaceRouted();

        if (! $taskWorkspaceRouted['valid']) {
            return $this->renderGatewayFailure(
                'project.task_workspace_routed_invalid',
                'Task workspace routed must be true or false.',
            );
        }

        if ($type === null && $slug === null && $repositoryUrl === null && $sourceAccess === null && $defaultBranch === null && $root === null && $taskCheck === null && ! $clearTaskCheck && $taskWorkspaceRouted['value'] === null && $taskCompute === null) {
            return $this->renderGatewayFailure(
                'project.update_required',
                'Provide at least one Project update.',
            );
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $project = $this->sendWithProgress(
            $connector,
            new UpdateProjectRequest(
                projectId: $projectId,
                type: $type,
                slug: $slug,
                repositoryUrl: $repositoryUrl,
                defaultBranch: $defaultBranch,
                root: $root,
                taskCheck: $clearTaskCheck ? null : $taskCheck,
                taskCheckProvided: $clearTaskCheck || $taskCheck !== null,
                sourceAccess: $sourceAccess,
                taskWorkspaceRouted: $taskWorkspaceRouted['value'],
                taskCompute: is_string($taskCompute) ? $taskCompute : null,
            ),
            ProjectResponse::class,
            ['Update Project', 'Updating Project', 'Updated Project'],
        );

        if (! $project instanceof ProjectResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($project->toArray());

            return self::SUCCESS;
        }

        $this->writeHumanMessage("Project [{$project->slug}] updated.");
        $this->writeHumanMessage("Request ID: {$project->requestId}");

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
