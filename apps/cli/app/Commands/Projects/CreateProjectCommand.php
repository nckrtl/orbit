<?php

declare(strict_types=1);

namespace App\Commands\Projects;

use App\Commands\GatewayCommand;
use App\Commands\Projects\Concerns\ParsesTaskWorkspaceRouted;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\NamedAppOptions;
use Orbit\Sdk\Requests\Projects\CreateProjectRequest;
use Orbit\Sdk\Responses\Projects\ProjectResponse;

final class CreateProjectCommand extends GatewayCommand
{
    use ParsesTaskWorkspaceRouted;

    #[\Override]
    protected $signature = 'project:create
        {slug : Unique project slug}
        {type : Project type (monorepo, laravel-app, symfony-app, laravel-package, or node-package)}
        {repository : Git repository URL}
        {--name= : Optional display name}
        {--source-access= : How Orbit reads a private github.com repository: github_app (default) or gh_cli}
        {--default-branch= : Stored default branch; resolve the remote default when omitted}
        {--apps= : JSON list of apps, each with name, path, web_root and type}
        {--task-check= : Task check command. Omitted stores none}
        {--task-workspace-routed= : Whether new task workspaces get a Route (true or false)}
        {--task-compute= : Compute for future task groups (shared or vm)}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Create a project.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $slug = $this->stringArgument('slug', 'Project slug', 'project.slug_required');

        if ($slug === null) {
            return self::FAILURE;
        }

        $repositoryUrl = $this->stringArgument('repository', 'Repository URL', 'project.repository_required');

        if ($repositoryUrl === null) {
            return self::FAILURE;
        }

        if (! $this->hasSafeRepositoryInput($repositoryUrl)) {
            return $this->renderGatewayFailure(
                'project.repository_invalid',
                'Repository URL is invalid.',
            );
        }

        if (strlen($slug) > 63 || preg_match('/[\x00-\x1F\x7F]/', $slug) === 1) {
            return $this->renderGatewayFailure(
                'project.slug_invalid',
                'Project slug is invalid.',
            );
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

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $type = $this->stringArgument('type', 'Project type', 'project.type_required');

        if ($type === null) {
            return self::FAILURE;
        }

        if (! in_array($type, ['monorepo', 'laravel-app', 'symfony-app', 'laravel-package', 'node-package'], true)) {
            return $this->renderGatewayFailure(
                'project.type_invalid',
                'Project type must be monorepo, laravel-app, symfony-app, laravel-package, or node-package.',
            );
        }

        $sourceAccess = $this->stringOption('source-access');

        if ($sourceAccess !== null && ! in_array($sourceAccess, ['github_app', 'gh_cli'], true)) {
            return $this->renderGatewayFailure(
                'project.source_access_invalid',
                'Source access must be github_app or gh_cli.',
            );
        }

        $apps = NamedAppOptions::apps($this->stringOption('apps') ?? '');

        if ($apps === null) {
            return $this->renderGatewayFailure(
                'project.apps_invalid',
                'Pass --apps as a JSON list of apps, each with name, path, web_root and type.',
            );
        }
        $taskCheck = $this->stringOption('task-check');

        if ($taskCheck !== null && (trim($taskCheck) === '' || strlen($taskCheck) > 4096)) {
            return $this->renderGatewayFailure('project.task_check_invalid', 'Task check command is invalid.');
        }

        $project = $this->sendWithProgress(
            $connector,
            new CreateProjectRequest(
                slug: $slug,
                repositoryUrl: $repositoryUrl,
                apps: $apps,
                type: $type,
                name: $this->stringOption('name'),
                defaultBranch: $this->stringOption('default-branch'),
                taskCheck: $taskCheck,
                taskCheckProvided: $taskCheck !== null,
                sourceAccess: $sourceAccess,
                taskWorkspaceRouted: $taskWorkspaceRouted['value'],
                taskCompute: is_string($taskCompute) ? $taskCompute : null,
            ),
            ProjectResponse::class,
            ['Create Project', 'Creating Project', 'Created Project'],
        );

        if (! $project instanceof ProjectResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($project->toArray());

            return self::SUCCESS;
        }

        $this->writeHumanMessage("Project [{$project->slug}] created.");
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
