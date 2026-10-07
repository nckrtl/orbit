<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Data\Projects\UpdateProjectData;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectType;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\SourceControl\ProjectRoot;
use App\Domain\Tasks\TaskCompute;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use UnexpectedValueException;

final class UpdateProjectRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'filled', 'string', 'regex:/\A[A-Z]{3}\z/D'],
            'type' => ['sometimes', 'required', 'string', Rule::enum(ProjectType::class)],
            'slug' => ['sometimes', 'required', 'string', 'alpha_dash:ascii', 'max:63'],
            'repository_url' => ['sometimes', 'required', 'string', 'max:2048'],
            'source_access' => ['sometimes', 'required', 'string', Rule::enum(ProjectSourceAccess::class)],
            'default_branch' => ['sometimes', 'required', 'string', 'max:255'],
            'root' => ['sometimes', 'required', 'string', 'max:255'],
            'task_check' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'task_workspace_routed' => ['sometimes', 'boolean:strict'],
            'task_compute' => ['sometimes', 'required', 'string', Rule::enum(TaskCompute::class)],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect(
                $this->getContent(),
                ['code', 'type', 'slug', 'repository_url', 'source_access', 'default_branch', 'root', 'task_check', 'task_workspace_routed', 'task_compute'],
            );
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (
                ! $this->exists('code')
                && ! $this->exists('type')
                && ! $this->exists('slug')
                && ! $this->exists('repository_url')
                && ! $this->exists('source_access')
                && ! $this->exists('default_branch')
                && ! $this->exists('root')
                && ! $this->exists('task_check')
                && ! $this->exists('task_workspace_routed')
                && ! $this->exists('task_compute')
            ) {
                $validator->errors()->add('body', 'Provide at least one Project update.');
            }

            $repository = $this->input('repository_url');

            if (is_string($repository) && $repository !== '' && ! GitRepositoryOrigin::isValid($repository)) {
                $validator->errors()->add(
                    'repository_url',
                    'The repository URL must be a valid HTTPS or SSH Git origin.',
                );
            }

            $this->validateSourceAccess($validator);

            $branch = $this->input('default_branch');

            if (is_string($branch) && ! GitBranchName::isValid($branch)) {
                $validator->errors()->add('default_branch', 'The default branch is not a valid Git branch name.');
            }

            $routeApp = $this->route('project');
            $type = ProjectType::tryFrom($this->string('type')->toString())
                ?? ($routeApp instanceof Project ? $routeApp->type : ProjectType::LaravelApp);
            $sentRoot = $this->input('root');
            $root = is_string($sentRoot) ? $sentRoot : ($routeApp instanceof Project ? $routeApp->root : null);

            if (is_string($root) && ! ProjectRoot::isValid($root, $type)) {
                $validator->errors()->add('root', is_string($sentRoot)
                    ? ProjectRoot::message($root, $type)
                    : "The stored root [{$root}] is not valid for a {$type->value} Project. Send a web root with the type change.");
            }
        }];
    }

    public function payload(): UpdateProjectData
    {
        $validated = $this->validated();

        return new UpdateProjectData(
            code: is_string($validated['code'] ?? null) ? $validated['code'] : null,
            typeProvided: array_key_exists('type', $validated),
            type: is_string($validated['type'] ?? null) ? ProjectType::tryFrom($validated['type']) : null,
            slugProvided: array_key_exists('slug', $validated),
            slug: is_string($validated['slug'] ?? null) ? $validated['slug'] : null,
            repositoryUrlProvided: array_key_exists('repository_url', $validated),
            repositoryUrl: is_string($validated['repository_url'] ?? null) ? $validated['repository_url'] : null,
            defaultBranchProvided: array_key_exists('default_branch', $validated),
            defaultBranch: is_string($validated['default_branch'] ?? null) ? $validated['default_branch'] : null,
            rootProvided: array_key_exists('root', $validated),
            root: is_string($validated['root'] ?? null) ? $validated['root'] : null,
            taskCheckProvided: array_key_exists('task_check', $validated),
            taskCheck: is_string($validated['task_check'] ?? null) ? $validated['task_check'] : null,
            sourceAccessProvided: array_key_exists('source_access', $validated),
            sourceAccess: is_string($validated['source_access'] ?? null) ? ProjectSourceAccess::tryFrom($validated['source_access']) : null,
            taskWorkspaceRoutedProvided: array_key_exists('task_workspace_routed', $validated),
            taskWorkspaceRouted: ($validated['task_workspace_routed'] ?? false) === true,
            taskCompute: is_string($validated['task_compute'] ?? null) ? TaskCompute::from($validated['task_compute']) : null,
        );
    }

    /**
     * GitHub CLI source access needs a `github.com` URL, so neither a source access change nor a
     * repository change may leave a `gh_cli` Project on another host.
     */
    private function validateSourceAccess(Validator $validator): void
    {
        $project = $this->route('project');
        $sentAccess = $this->input('source_access');
        $access = is_string($sentAccess)
            ? ProjectSourceAccess::tryFrom($sentAccess)
            : ($project instanceof Project ? $project->source_access : null);
        $sentRepository = $this->input('repository_url');
        $repository = is_string($sentRepository)
            ? $sentRepository
            : ($project instanceof Project ? $project->repository_url : null);

        if (
            $access === ProjectSourceAccess::GhCli
            && is_string($repository)
            && ! GitHubRepository::fromOrigin($repository) instanceof GitHubRepository
        ) {
            $validator->errors()->add('source_access', 'GitHub CLI source access needs a github.com repository URL.');
        }
    }
}
