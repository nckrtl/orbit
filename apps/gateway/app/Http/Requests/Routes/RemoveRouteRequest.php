<?php

declare(strict_types=1);

namespace App\Http\Requests\Routes;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class RemoveRouteRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['offline' => ['sometimes', $this->strictBoolean(...)]];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        $content = $this->getContent();

        if (trim($content) === '[]') {
            return [];
        }

        try {
            return app(TopLevelJsonObjectInspector::class)->inspect(
                $content,
                ['offline', ...array_keys($this->route()?->parameters() ?? [])],
            );
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function offline(): bool
    {
        return $this->validated('offline', false) === true;
    }

    private function strictBoolean(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_bool($value)) {
            $fail("The {$attribute} field must be true or false.");
        }
    }
}
