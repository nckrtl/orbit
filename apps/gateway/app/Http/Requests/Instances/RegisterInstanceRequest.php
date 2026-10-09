<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use App\Data\Instances\RegisterInstanceData;
use App\Domain\Routes\RouteDomain;
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
            'instance_name' => ['sometimes', 'string', 'max:63'],
            'app_overrides' => ['sometimes', 'array'],
            'app_overrides.*' => ['array:path,web_root'],
            'app_overrides.*.path' => ['required', 'string', 'max:255'],
            'app_overrides.*.web_root' => ['present', 'nullable', 'string', 'max:255'],
            'domain' => ['sometimes', 'string', 'max:253'],
            'setup' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            app(TopLevelJsonObjectInspector::class)->refuseRemoved($this->getContent(), 'root', 'app_overrides');

            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), [
                'source_path',
                'include_worktrees',
                'project_id',
                'instance_name',
                'app_overrides',
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
            instanceName: is_string($values['instance_name'] ?? null) ? $values['instance_name'] : null,
            appOverrides: $values['app_overrides'] ?? null,
            domain: is_string($values['domain'] ?? null) ? $values['domain'] : null,
            runSetup: ($values['setup'] ?? false) === true,
        );
    }
}
