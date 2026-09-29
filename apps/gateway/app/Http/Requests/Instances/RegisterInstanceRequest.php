<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use App\Data\Instances\RegisterInstanceData;
use App\Domain\Projects\ProjectType;
use App\Domain\Routes\RouteDomain;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\ProjectRoot;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use UnexpectedValueException;

final class RegisterInstanceRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'source_path' => ['required', 'string', 'max:4096', 'regex:/\A\/[^\x00-\x1f]*\z/'],
            'include_worktrees' => ['sometimes', 'boolean'],
            'project_id' => ['sometimes', 'integer', Rule::exists(new Project()->getTable(), 'id')],
            'project_name' => ['sometimes', 'string', 'max:255'],
            'project_slug' => ['sometimes', 'string', 'alpha_dash:ascii', 'max:63'],
            'default_branch' => ['sometimes', 'string', 'max:255'],
            'instance_name' => ['sometimes', 'string', 'max:63'],
            'root' => ['sometimes', 'string', 'max:255'],
            'domain' => ['sometimes', 'string', 'max:253'],
            'setup' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), [
                'source_path',
                'include_worktrees',
                'project_id',
                'project_name',
                'project_slug',
                'default_branch',
                'instance_name',
                'root',
                'domain',
                'setup',
            ]);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $branch = $this->input('default_branch');
            if (is_string($branch) && ! GitBranchName::isValid($branch)) {
                $validator->errors()->add('default_branch', 'The default branch is not a valid Git branch name.');
            }

            $root = $this->input('root');
            $projectId = $this->input('project_id');
            $project = is_numeric($projectId) ? Project::query()->find((int) $projectId) : null;
            $type = $project instanceof Project ? $project->type : ProjectType::LaravelPackage;

            if (is_string($root) && ! ProjectRoot::isValid($root, $type)) {
                $validator->errors()->add('root', 'The root must be a normalized relative Project path.');
            }

            $domain = $this->input('domain');
            if (is_string($domain) && ! RouteDomain::isValid($domain)) {
                $validator->errors()->add('domain', 'The Route domain is invalid.');
            }

        }];
    }

    public function payload(): RegisterInstanceData
    {
        $values = $this->validated();

        return new RegisterInstanceData(
            sourcePath: is_string($values['source_path'] ?? null) ? $values['source_path'] : '',
            includeWorktrees: ($values['include_worktrees'] ?? false) === true,
            projectId: is_int($values['project_id'] ?? null) ? $values['project_id'] : null,
            projectName: is_string($values['project_name'] ?? null) ? $values['project_name'] : null,
            projectSlug: is_string($values['project_slug'] ?? null) ? $values['project_slug'] : null,
            defaultBranch: is_string($values['default_branch'] ?? null) ? $values['default_branch'] : null,
            instanceName: is_string($values['instance_name'] ?? null) ? $values['instance_name'] : null,
            root: is_string($values['root'] ?? null) ? $values['root'] : null,
            domain: is_string($values['domain'] ?? null) ? $values['domain'] : null,
            runSetup: ($values['setup'] ?? false) === true,
        );
    }
}
