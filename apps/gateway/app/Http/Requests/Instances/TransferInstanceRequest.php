<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use App\Data\Instances\TransferInstanceData;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\Node;
use App\Support\ValidatedData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class TransferInstanceRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'node_id' => ['required', 'integer:strict', 'min:1', Rule::exists(new Node()->getTable(), 'id')],
            'name' => [
                'sometimes',
                'string',
                'max:63',
                'regex:/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/',
            ],
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
                ['node_id', 'name', 'sqlite_source_path'],
            );
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): TransferInstanceData
    {
        $validated = $this->validated();

        return new TransferInstanceData(
            nodeId: ValidatedData::integer($validated['node_id'] ?? null),
            name: is_string($validated['name'] ?? null) ? $validated['name'] : null,
            sqliteSourcePath: is_string($validated['sqlite_source_path'] ?? null)
                ? $validated['sqlite_source_path']
                : null,
        );
    }
}
