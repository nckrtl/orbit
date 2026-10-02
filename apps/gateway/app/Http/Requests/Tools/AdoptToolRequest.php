<?php

declare(strict_types=1);

namespace App\Http\Requests\Tools;

use App\Data\Tools\AdoptToolData;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class AdoptToolRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'node_id' => ['required', 'integer', 'min:1', 'exists:nodes,id', $this->strictInteger(...)],
            'manager' => ['required', 'string', 'max:32'],
            'package' => ['required', 'string', 'max:255'],
            'version_constraint' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect(
                $this->getContent(),
                ['node_id', 'manager', 'package', 'version_constraint'],
            );
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): AdoptToolData
    {
        $validated = $this->validated();

        return new AdoptToolData(
            nodeId: $this->integer('node_id'),
            manager: $this->string('manager')->toString(),
            package: $this->string('package')->toString(),
            versionConstraint: is_string($validated['version_constraint'] ?? null)
                ? $validated['version_constraint']
                : null,
        );
    }

    private function strictInteger(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value)) {
            $fail("The {$attribute} field must be an integer.");
        }
    }
}
