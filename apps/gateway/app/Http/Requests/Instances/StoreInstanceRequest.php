<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use App\Data\Instances\CreateInstanceData;
use App\Domain\Routes\RouteDomain;
use App\Domain\SourceControl\GitBranchName;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\Node;
use App\Models\Project;
use App\Support\ValidatedData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use UnexpectedValueException;

final class StoreInstanceRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists(new Project()->getTable(), 'id')],
            'node_id' => ['required', 'integer', Rule::exists(new Node()->getTable(), 'id')],
            'name' => [
                'required',
                'string',
                'max:63',
                'regex:/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/',
            ],
            'app_overrides' => ['sometimes', 'array'],
            'app_overrides.*' => ['array:path,web_root'],
            'app_overrides.*.path' => ['required', 'string', 'max:255'],
            'app_overrides.*.web_root' => ['present', 'nullable', 'string', 'max:255'],
            'domain' => ['sometimes', 'string', 'max:253'],
            'branch' => ['sometimes', 'string', 'max:255'],
            'database_server' => ['sometimes', 'string', 'max:63', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            app(TopLevelJsonObjectInspector::class)->refuseRemoved($this->getContent(), 'root', 'app_overrides');

            return app(TopLevelJsonObjectInspector::class)->inspect(
                $this->getContent(),
                ['project_id', 'node_id', 'name', 'app_overrides', 'domain', 'branch', 'database_server'],
            );
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $data = $validator->getData();

            $domain = $data['domain'] ?? null;

            if (is_string($domain) && ! RouteDomain::isValid($domain)) {
                $validator->errors()->add('domain', 'The Route domain is invalid.');
            }

            $branch = $data['branch'] ?? null;

            if (is_string($branch) && ! GitBranchName::isValid($branch)) {
                $validator->errors()->add('branch', 'The branch is not a valid Git branch name.');
            }

        }];
    }

    public function payload(): CreateInstanceData
    {
        $validated = $this->validated();

        return new CreateInstanceData(
            projectId: $this->resolvedProjectId($validated),
            nodeId: self::integerId($validated['node_id']) ?? throw new UnexpectedValueException('A validated Node identifier must be an integer.'),
            name: ValidatedData::string($validated['name'] ?? null),
            appOverrides: $validated['app_overrides'] ?? null,
            domain: is_string($validated['domain'] ?? null)
                ? RouteDomain::normalize($validated['domain'])
                : null,
            branch: is_string($validated['branch'] ?? null) ? $validated['branch'] : null,
            databaseServer: is_string($validated['database_server'] ?? null) ? $validated['database_server'] : null,
        );
    }

    /** @param array<string, mixed> $validated */
    private function resolvedProjectId(array $validated): int
    {
        $projectId = self::integerId($validated['project_id'] ?? null);

        if ($projectId === null) {
            throw new UnexpectedValueException('A validated Project identifier must be an integer.');
        }

        return $projectId;
    }

    private static function integerId(mixed $value): ?int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);

        return is_int($integer) ? $integer : null;
    }
}
