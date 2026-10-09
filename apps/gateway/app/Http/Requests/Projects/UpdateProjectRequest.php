<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Data\Projects\UpdateProjectData;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectType;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryOrigin;
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
            'apps' => ['sometimes', 'list'],
            'apps.*' => ['array:name,path,web_root,type'],
            'apps.*.name' => ['required', 'string', 'max:63'],
            'apps.*.path' => ['required', 'string', 'max:255'],
            'apps.*.web_root' => ['present', 'nullable', 'string', 'max:255'],
            'apps.*.type' => ['required', 'string', 'max:32'],
            'task_check' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'task_workspace_routed' => ['sometimes', 'boolean:strict'],
            'task_compute' => ['sometimes', 'required', 'string', Rule::enum(TaskCompute::class)],
            'review_and_merge' => ['sometimes', 'boolean:strict'],
            'merge_check' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            app(TopLevelJsonObjectInspector::class)->refuseRemoved($this->getContent(), 'root', 'apps');

            return app(TopLevelJsonObjectInspector::class)->inspect(
                $this->getContent(),
                ['code', 'type', 'slug', 'repository_url', 'source_access', 'default_branch', 'apps', 'task_check', 'task_workspace_routed', 'task_compute', 'review_and_merge', 'merge_check'],
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
                && ! $this->exists('apps')
                && ! $this->exists('task_check')
                && ! $this->exists('task_workspace_routed')
                && ! $this->exists('task_compute')
                && ! $this->exists('review_and_merge')
                && ! $this->exists('merge_check')
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
            $this->validateReviewAndMerge($validator);

            $branch = $this->input('default_branch');

            if (is_string($branch) && ! GitBranchName::isValid($branch)) {
                $validator->errors()->add('default_branch', 'The default branch is not a valid Git branch name.');
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
            taskCheckProvided: array_key_exists('task_check', $validated),
            taskCheck: is_string($validated['task_check'] ?? null) ? $validated['task_check'] : null,
            sourceAccessProvided: array_key_exists('source_access', $validated),
            sourceAccess: is_string($validated['source_access'] ?? null) ? ProjectSourceAccess::tryFrom($validated['source_access']) : null,
            taskWorkspaceRoutedProvided: array_key_exists('task_workspace_routed', $validated),
            taskWorkspaceRouted: ($validated['task_workspace_routed'] ?? false) === true,
            taskCompute: is_string($validated['task_compute'] ?? null) ? TaskCompute::from($validated['task_compute']) : null,
            reviewAndMergeProvided: array_key_exists('review_and_merge', $validated),
            reviewAndMerge: ($validated['review_and_merge'] ?? false) === true,
            mergeCheckProvided: array_key_exists('merge_check', $validated),
            mergeCheck: is_string($validated['merge_check'] ?? null) && trim($validated['merge_check']) !== '' ? trim($validated['merge_check']) : null,
            appsProvided: array_key_exists('apps', $validated),
            apps: $validated['apps'] ?? null,
        );
    }

    /**
     * ADR 0203: the review-and-merge flow names the check that must pass, publishes through the App, and runs on
     * shared compute. The rule reads the values this request leaves on the Project.
     */
    private function validateReviewAndMerge(Validator $validator): void
    {
        $project = $this->route('project');
        $enabled = $this->exists('review_and_merge')
            ? $this->input('review_and_merge') === true
            : ($project instanceof Project && $project->review_and_merge);
        if (! $enabled) {
            return;
        }
        $check = $this->exists('merge_check') ? $this->input('merge_check') : ($project instanceof Project ? $project->merge_check : null);
        if (! is_string($check) || trim($check) === '') {
            $validator->errors()->add('merge_check', 'Review and merge needs a merge check, such as "Required checks".');
        }
        $access = is_string($this->input('source_access'))
            ? ProjectSourceAccess::tryFrom($this->string('source_access')->toString())
            : ($project instanceof Project ? $project->source_access : null);
        if ($access !== ProjectSourceAccess::GitHubApp) {
            $validator->errors()->add('review_and_merge', 'Review and merge needs source access through the GitHub App.');
        }
        $compute = is_string($this->input('task_compute'))
            ? TaskCompute::tryFrom($this->string('task_compute')->toString())
            : ($project instanceof Project ? $project->task_compute : TaskCompute::Shared);
        if ($compute !== TaskCompute::Shared) {
            $validator->errors()->add('review_and_merge', 'Review and merge needs shared task compute.');
        }
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
