<?php

declare(strict_types=1);

namespace App\Http\Requests\Nodes;

use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\Node;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class RenameNodeRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $node = $this->route('node');
        $nodeId = $node instanceof Node ? $node->id : null;

        return [
            'name' => [
                'required',
                'string',
                'alpha_dash:ascii',
                'max:63',
                Rule::unique('nodes', 'name')->ignore($nodeId),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect(
                $this->getContent(),
                ['name'],
            );
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function name(): string
    {
        return (string) $this->validated('name');
    }
}
