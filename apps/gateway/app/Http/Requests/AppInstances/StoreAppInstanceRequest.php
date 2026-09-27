<?php

declare(strict_types=1);

namespace App\Http\Requests\AppInstances;

use App\Data\AppInstances\CreateAppInstanceData;
use App\Domain\Projects\ProjectType;
use App\Domain\Routes\RouteDomain;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\ProjectRoot;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\App as OrbitApp;
use App\Models\Node;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use UnexpectedValueException;

final class StoreAppInstanceRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'app_id' => ['sometimes', 'integer', Rule::exists(new OrbitApp()->getTable(), 'id')],
            'project_id' => ['sometimes', 'integer', Rule::exists(new OrbitApp()->getTable(), 'id')],
            'node_id' => ['required', 'integer', Rule::exists(new Node()->getTable(), 'id')],
            'name' => [
                'required',
                'string',
                'max:63',
                'regex:/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/',
            ],
            'root' => ['sometimes', 'string', 'max:255'],
            'domain' => ['sometimes', 'string', 'max:253'],
            'branch' => ['sometimes', 'string', 'max:255'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect(
                $this->getContent(),
                ['app_id', 'project_id', 'node_id', 'name', 'root', 'domain', 'branch'],
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
            $root = $data['root'] ?? null;

            $projectId = self::integerId($data['project_id'] ?? $data['app_id'] ?? null);
            $project = $projectId === null ? null : OrbitApp::query()->find($projectId);

            if (is_string($root) && ! ProjectRoot::isValid($root, $project instanceof OrbitApp ? $project->type : ProjectType::LaravelApp)) {
                $validator->errors()->add('root', 'The root must be a normalized relative Project path.');
            }

            $domain = $data['domain'] ?? null;

            if (is_string($domain) && ! RouteDomain::isValid($domain)) {
                $validator->errors()->add('domain', 'The Route domain is invalid.');
            }

            $branch = $data['branch'] ?? null;

            if (is_string($branch) && ! GitBranchName::isValid($branch)) {
                $validator->errors()->add('branch', 'The branch is not a valid Git branch name.');
            }

            $this->validateProjectOwner($validator, $data);
        }];
    }

    public function payload(): CreateAppInstanceData
    {
        /** @var array{node_id: int|string, name: string, app_id?: int|string, project_id?: int|string, root?: string, domain?: string, branch?: string} $validated */
        $validated = $this->validated();

        return new CreateAppInstanceData(
            appId: $this->resolvedProjectId($validated),
            nodeId: self::integerId($validated['node_id']) ?? throw new UnexpectedValueException('A validated Node identifier must be an integer.'),
            name: $validated['name'],
            root: is_string($validated['root'] ?? null) ? $validated['root'] : null,
            domain: is_string($validated['domain'] ?? null)
                ? RouteDomain::normalize($validated['domain'])
                : null,
            branch: is_string($validated['branch'] ?? null) ? $validated['branch'] : null,
        );
    }

    /** @param array<string, mixed> $data */
    private function validateProjectOwner(Validator $validator, array $data): void
    {
        $appId = self::integerId($data['app_id'] ?? null);
        $projectId = self::integerId($data['project_id'] ?? null);

        if ($appId === null && $projectId === null) {
            $validator->errors()->add('project_id', 'Supply project_id or app_id.');
        }

        if ($appId !== null && $projectId !== null && $appId !== $projectId) {
            $validator->errors()->add('project_id', 'project_id and app_id must name the same Project.');
        }
    }

    /** @param array<string, mixed> $validated */
    private function resolvedProjectId(array $validated): int
    {
        $projectId = self::integerId($validated['project_id'] ?? $validated['app_id'] ?? null);

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
