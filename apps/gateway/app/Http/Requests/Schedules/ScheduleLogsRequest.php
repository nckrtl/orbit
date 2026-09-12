<?php

declare(strict_types=1);

namespace App\Http\Requests\Schedules;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class ScheduleLogsRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'lines' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        $input = $this->query->all();

        if (array_diff(array_keys($input), ['lines']) !== []) {
            throw ValidationException::withMessages([
                'query' => ['The request contains unsupported query parameters.'],
            ]);
        }

        return $input;
    }

    public function lines(): int
    {
        return (int) ($this->validated('lines') ?? 100);
    }
}
