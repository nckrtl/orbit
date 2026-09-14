<?php

declare(strict_types=1);

namespace App\Http\Requests\DatabaseConnections;

use App\Domain\DatabaseConnections\DatabaseConnectionPrefix;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class AttachDatabaseConnectionRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'prefix' => ['sometimes', 'string', 'regex:'.DatabaseConnectionPrefix::Pattern],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['prefix']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function prefix(): ?string
    {
        $validated = $this->validated();

        return is_string($validated['prefix'] ?? null) ? $validated['prefix'] : null;
    }
}
