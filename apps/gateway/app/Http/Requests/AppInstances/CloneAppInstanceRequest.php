<?php

declare(strict_types=1);

namespace App\Http\Requests\AppInstances;

use App\Data\AppInstances\CloneAppInstanceData;
use App\Domain\Routes\RouteHostname;
use App\Domain\SourceControl\GitBranchName;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\Node;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use UnexpectedValueException;

final class CloneAppInstanceRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'node_id' => ['required', 'integer:strict', 'min:1', Rule::exists(new Node()->getTable(), 'id')],
            'name' => [
                'required',
                'string',
                'max:63',
                'regex:/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/',
            ],
            'preview_name' => ['required', 'string', 'max:253'],
            'branch' => ['sometimes', 'string', 'max:255'],
            'sqlite_source_path' => [
                'sometimes',
                'filled',
                'string',
                'max:4096',
                'regex:/\A\/[^\x00-\x1F\x7F]*\z/D',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect(
                $this->getContent(),
                ['node_id', 'name', 'preview_name', 'branch', 'sqlite_source_path'],
            );
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $previewName = $this->input('preview_name');

            if (is_string($previewName) && ! RouteHostname::isValid($previewName)) {
                $validator->errors()->add('preview_name', 'The preview name is invalid.');
            }

            $branch = $this->input('branch');

            if (is_string($branch) && ! GitBranchName::isValid($branch)) {
                $validator->errors()->add('branch', 'The branch is not a valid Git branch name.');
            }
        }];
    }

    public function payload(): CloneAppInstanceData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return new CloneAppInstanceData(
            nodeId: (int) $validated['node_id'],
            name: (string) $validated['name'],
            previewName: RouteHostname::normalize((string) $validated['preview_name']),
            branch: is_string($validated['branch'] ?? null) ? $validated['branch'] : null,
            sqliteSourcePath: is_string($validated['sqlite_source_path'] ?? null)
                ? $validated['sqlite_source_path']
                : null,
        );
    }
}
