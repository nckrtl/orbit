<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Data\Projects\CreateProjectData;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectType;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\Tasks\TaskCompute;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use UnexpectedValueException;

final class StoreProjectRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'filled', 'string', 'regex:/\A[A-Z]{3}\z/D'],
            'slug' => ['required', 'string', 'alpha_dash:ascii', 'max:63'],
            'type' => [
                'required',
                'string',
                Rule::enum(ProjectType::class),
            ],
            'repository_url' => ['required', 'string', 'max:2048'],
            'source_access' => ['sometimes', 'string', Rule::enum(ProjectSourceAccess::class)],
            'default_branch' => ['sometimes', 'string', 'max:255'],
            'apps' => ['required', 'list'],
            'apps.*' => ['array:name,path,web_root,type'],
            'apps.*.name' => ['required', 'string', 'max:63'],
            'apps.*.path' => ['required', 'string', 'max:255'],
            'apps.*.web_root' => ['present', 'nullable', 'string', 'max:255'],
            'apps.*.type' => ['required', 'string', 'max:32'],
            'task_check' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'task_workspace_routed' => ['sometimes', 'boolean:strict'],
            'task_compute' => ['sometimes', 'required', 'string', Rule::enum(TaskCompute::class)],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            app(TopLevelJsonObjectInspector::class)->refuseRemoved($this->getContent(), 'root', 'apps');

            return app(TopLevelJsonObjectInspector::class)->inspect(
                $this->getContent(),
                ['code', 'name', 'slug', 'type', 'repository_url', 'source_access', 'default_branch', 'apps', 'task_check', 'task_workspace_routed', 'task_compute'],
            );
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! is_string($this->input('repository_url'))) {
                return;
            }

            $repository = $this->string('repository_url')->toString();

            if ($repository !== '' && ! GitRepositoryOrigin::isValid($repository)) {
                $validator->errors()->add(
                    'repository_url',
                    'The repository URL must be a valid HTTPS or SSH Git origin.',
                );
            }

            if (
                $this->input('source_access') === ProjectSourceAccess::GhCli->value
                && ! GitHubRepository::fromOrigin($repository) instanceof GitHubRepository
            ) {
                $validator->errors()->add('source_access', 'GitHub CLI source access needs a github.com repository URL.');
            }

            $this->validateSourceDefaults($validator);
        }];
    }

    public function payload(): CreateProjectData
    {
        $validated = $this->validated();
        $slug = is_string($validated['slug'] ?? null) ? $validated['slug'] : '';

        return new CreateProjectData(
            code: is_string($validated['code'] ?? null) ? $validated['code'] : null,
            name: is_string($validated['name'] ?? null) ? $validated['name'] : $slug,
            slug: $slug,
            type: ProjectType::from(is_string($validated['type'] ?? null) ? $validated['type'] : ''),
            repositoryUrl: is_string($validated['repository_url'] ?? null) ? $validated['repository_url'] : '',
            defaultBranch: is_string($validated['default_branch'] ?? null) ? $validated['default_branch'] : null,
            apps: $validated['apps'] ?? [],
            taskCheckProvided: array_key_exists('task_check', $validated),
            taskCheck: is_string($validated['task_check'] ?? null) ? $validated['task_check'] : null,
            sourceAccess: ProjectSourceAccess::tryFrom(is_string($validated['source_access'] ?? null) ? $validated['source_access'] : '')
                ?? ProjectSourceAccess::GitHubApp,
            taskWorkspaceRoutedProvided: array_key_exists('task_workspace_routed', $validated),
            taskWorkspaceRouted: ($validated['task_workspace_routed'] ?? false) === true,
            taskCompute: is_string($validated['task_compute'] ?? null) ? TaskCompute::from($validated['task_compute']) : TaskCompute::Shared,
        );
    }

    private function validateSourceDefaults(Validator $validator): void
    {
        $branch = $this->input('default_branch');

        if (is_string($branch) && ! GitBranchName::isValid($branch)) {
            $validator->errors()->add('default_branch', 'The default branch is not a valid Git branch name.');
        }
    }
}
