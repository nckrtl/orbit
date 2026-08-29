<?php

declare(strict_types=1);

namespace App\Http\Requests\Metrics;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use UnexpectedValueException;

final class RemoveMetricsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'force' => ['sometimes', $this->strictBoolean(...)],
            'purge_data' => ['sometimes', $this->strictBoolean(...)],
        ];
    }

    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['force', 'purge_data']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('purge_data') !== true || $this->input('force') === true) {
                return;
            }

            $validator->errors()->add(
                'force',
                'The force field must be true when purge data is requested.',
            );
        }];
    }

    public function force(): bool
    {
        return $this->validated('force', false) === true;
    }

    public function purgeData(): bool
    {
        return $this->validated('purge_data', false) === true;
    }

    private function strictBoolean(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_bool($value)) {
            $fail("The {$attribute} field must be true or false.");
        }
    }
}
