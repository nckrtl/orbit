<?php

declare(strict_types=1);

namespace App\Http\Requests\AppInstances;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class RemoveAppInstanceRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['discard_source' => ['sometimes', $this->strictBoolean(...)]];
    }

    public function validationData(): array
    {
        $content = $this->getContent();

        if (trim($content) === '[]') {
            return [];
        }

        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($content, ['discard_source']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function discardSource(): bool
    {
        return $this->validated('discard_source', false) === true;
    }

    private function strictBoolean(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_bool($value)) {
            $fail("The {$attribute} field must be true or false.");
        }
    }
}
