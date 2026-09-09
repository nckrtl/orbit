<?php

declare(strict_types=1);

namespace App\Http\Requests\AppInstances;

use App\Data\AppInstances\RegisterAppInstanceData;
use App\Domain\Routes\RouteHostname;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\RelativeWebRoot;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\App as OrbitApp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use UnexpectedValueException;

/** @mago-expect lint:cyclomatic-complexity The request validates each independent optional registration value. */
final class RegisterAppInstanceRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'source_path' => ['required', 'string', 'max:4096', 'regex:/\A\/[^\x00-\x1f]*\z/'],
            'include_worktrees' => ['sometimes', 'boolean'],
            'app_id' => ['sometimes', 'integer', Rule::exists(new OrbitApp()->getTable(), 'id')],
            'app_name' => ['sometimes', 'string', 'max:255'],
            'app_slug' => ['sometimes', 'string', 'alpha_dash:ascii', 'max:63'],
            'default_branch' => ['sometimes', 'string', 'max:255'],
            'instance_name' => ['sometimes', 'string', 'max:63'],
            'root' => ['sometimes', 'string', 'max:255'],
            'hostname' => ['sometimes', 'string', 'max:253'],
        ];
    }

    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), [
                'source_path',
                'include_worktrees',
                'app_id',
                'app_name',
                'app_slug',
                'default_branch',
                'instance_name',
                'root',
                'hostname',
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
            if (is_string($root) && ! RelativeWebRoot::isValid($root)) {
                $validator->errors()->add('root', 'The root must be a normalized relative web path.');
            }

            $hostname = $this->input('hostname');
            if (is_string($hostname) && ! RouteHostname::isValid($hostname)) {
                $validator->errors()->add('hostname', 'The Route hostname is invalid.');
            }
        }];
    }

    public function payload(): RegisterAppInstanceData
    {
        /** @var array<string, mixed> $values */
        $values = $this->validated();

        return new RegisterAppInstanceData(
            sourcePath: (string) $values['source_path'],
            includeWorktrees: ($values['include_worktrees'] ?? false) === true,
            appId: is_int($values['app_id'] ?? null) ? $values['app_id'] : null,
            appName: is_string($values['app_name'] ?? null) ? $values['app_name'] : null,
            appSlug: is_string($values['app_slug'] ?? null) ? $values['app_slug'] : null,
            defaultBranch: is_string($values['default_branch'] ?? null) ? $values['default_branch'] : null,
            instanceName: is_string($values['instance_name'] ?? null) ? $values['instance_name'] : null,
            root: is_string($values['root'] ?? null) ? $values['root'] : null,
            hostname: is_string($values['hostname'] ?? null) ? $values['hostname'] : null,
        );
    }
}
